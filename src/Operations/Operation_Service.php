<?php
/**
 * Orchestrates the operation pipeline: create, queue, cancel.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;
use InvalidArgumentException;

/**
 * The front of the pipeline (CONTEXT §2): Filter → (Preview) → Snapshot →
 * Execution. `create()` records a draft; `queue()` performs the one irreversible
 * decision that everything downstream depends on — it resolves the filter
 * exactly once, freezes the resulting ids into the changes table, and hands off
 * to Action Scheduler. From that point execution reads only the frozen list, so
 * changing a field the filter matched on cannot make targets disappear
 * mid-operation (CONTEXT §2).
 *
 * Queueing takes the single-writer lock; the runner releases it when the
 * operation settles.
 */
final class Operation_Service {

	/**
	 * Default number of objects in the first chunk, before adaptation kicks in.
	 * Kept conservative so the very first request stays well under a 30s limit on
	 * an average shared host (CONTEXT §6); the runner grows it when chunks run
	 * fast. Measured worst case at 200/chunk on the pessimized dev box was ~16s,
	 * so 100 leaves comfortable margin for slower hosts.
	 */
	public const DEFAULT_BATCH = 100;

	/**
	 * Query engine (filter → ids).
	 *
	 * @var Query_Engine
	 */
	private Query_Engine $engine;

	/**
	 * Operations repository.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Changes repository.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Field provider registry.
	 *
	 * @var Field_Providers
	 */
	private Field_Providers $providers;

	/**
	 * Single-writer lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Scheduler for chunk hand-off and cancellation.
	 *
	 * @var Operation_Scheduler
	 */
	private Operation_Scheduler $scheduler;

	/**
	 * Build the service.
	 *
	 * @param Query_Engine        $engine     Query engine.
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Field_Providers     $providers  Field provider registry.
	 * @param Lock                $lock       Single-writer lock.
	 * @param Operation_Scheduler $scheduler Scheduler for chunk hand-off/cancel.
	 */
	public function __construct(
		Query_Engine $engine,
		Operations $operations,
		Changes $changes,
		Field_Providers $providers,
		Lock $lock,
		Operation_Scheduler $scheduler
	) {
		$this->engine     = $engine;
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->providers  = $providers;
		$this->lock       = $lock;
		$this->scheduler  = $scheduler;
	}

	/**
	 * Record a draft operation.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param Operation_Mode                          $mode    Write strategy.
	 * @param Operation_Source                        $source  Origin.
	 * @param int                                     $user_id Owner user id.
	 * @return int The new operation id.
	 *
	 * @throws InvalidArgumentException When an action targets a field no provider handles.
	 */
	public function create(
		Filter $filter,
		array $actions,
		Operation_Mode $mode,
		Operation_Source $source,
		int $user_id
	): int {
		$this->assert_fields_supported( $actions );

		return $this->operations->create( $filter, $actions, $mode, $source, $user_id );
	}

	/**
	 * Dry-run a filter and actions: count the targets and show what would change
	 * for a small sample, without writing anything (CONTEXT §2, the Preview step).
	 * Resolving here is a throwaway read, separate from the single resolution
	 * queue() freezes.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param int                                     $limit   Maximum sample rows.
	 * @return array{target_count: int, sample: list<array{id: int, name: string, changes: list<array{field: string, old: ?string, new: ?string}>}>}
	 *
	 * @throws InvalidArgumentException When an action targets an unsupported field.
	 */
	public function preview( Filter $filter, array $actions, int $limit = 20 ): array {
		$this->assert_fields_supported( $actions );

		$ids   = $this->engine->resolve( $filter );
		$limit = max( 0, $limit );

		$sample = array();

		foreach ( array_slice( $ids, 0, $limit ) as $object_id ) {
			$product = wc_get_product( $object_id );

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$changes = array();

			foreach ( $actions as $action ) {
				$provider = $this->providers->for( $action->field() );

				if ( null === $provider ) {
					continue;
				}

				$current = $provider->read( $product, $action->field() );
				$new     = $action->apply( $current );

				$changes[] = array(
					'field' => $action->field(),
					'old'   => Values::to_string( $current ),
					'new'   => Values::to_string( $new ),
				);
			}

			$sample[] = array(
				'id'      => (int) $object_id,
				'name'    => $product->get_name(),
				'changes' => $changes,
			);
		}

		return array(
			'target_count' => count( $ids ),
			'sample'       => $sample,
		);
	}

	/**
	 * Freeze an operation's targets and hand it to the scheduler.
	 *
	 * @param int $op_id Operation id.
	 *
	 * @throws InvalidArgumentException When the operation is missing or not a draft.
	 * @throws Operation_Blocked        When another operation holds the write-lock.
	 */
	public function queue( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		if ( Operation_Status::DRAFT !== $operation->status ) {
			throw new InvalidArgumentException( 'Only a draft operation can be queued.' );
		}

		if ( ! $this->lock->acquire( $op_id ) ) {
			throw new Operation_Blocked( 'Another operation is already writing to this catalog.' );
		}

		$actions = $operation->actions();
		$this->assert_fields_supported( $actions );

		// The one-and-only filter resolution (CONTEXT §2).
		$ids = $this->engine->resolve( $operation->filter() );

		if ( array() === $ids ) {
			// Nothing to do: settle immediately and free the lock.
			$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
			$this->lock->release( $op_id );
			return;
		}

		$this->changes->seed( $op_id, $this->seed_rows( $ids, $actions ) );
		$this->operations->set_target_count( $op_id, count( $ids ) );
		$this->operations->set_batch_size( $op_id, self::DEFAULT_BATCH );
		$this->operations->set_status( $op_id, Operation_Status::QUEUED );
		$this->operations->touch( $op_id );

		$this->scheduler->enqueue_chunk( $op_id, self::DEFAULT_BATCH );
	}

	/**
	 * Cancel a running or queued operation: stop scheduling, pause it, and free
	 * the lock. Already-applied changes remain (undo is a separate M3 operation).
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation || ! $operation->status->is_active() ) {
			return;
		}

		$this->scheduler->cancel_operation( $op_id );
		$this->operations->set_status( $op_id, Operation_Status::PAUSED );
		$this->lock->release( $op_id );
	}

	/**
	 * Build the cross-product of target ids and action fields as seed rows.
	 *
	 * @param int[]                                   $ids     Frozen target ids.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @return array<int, array{object_id: int, field_type: string, field_key: string}>
	 */
	private function seed_rows( array $ids, array $actions ): array {
		$specs = array();

		foreach ( $actions as $action ) {
			$provider = $this->providers->for( $action->field() );

			if ( null === $provider ) {
				continue;
			}

			$specs[] = array(
				'field_type' => $provider->field_type( $action->field() )->value,
				'field_key'  => $provider->storage_key( $action->field() ),
			);
		}

		$rows = array();

		foreach ( $ids as $object_id ) {
			foreach ( $specs as $spec ) {
				$rows[] = array(
					'object_id'  => $object_id,
					'field_type' => $spec['field_type'],
					'field_key'  => $spec['field_key'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Fail fast if any action targets a field no provider can write.
	 *
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to check.
	 *
	 * @throws InvalidArgumentException When a field is unsupported.
	 */
	private function assert_fields_supported( array $actions ): void {
		foreach ( $actions as $action ) {
			if ( ! $this->providers->handles( $action->field() ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/UI-facing message, sanitized at the REST boundary.
					sprintf( 'No provider handles the field "%s".', $action->field() )
				);
			}
		}
	}
}
