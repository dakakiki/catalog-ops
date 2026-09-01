<?php
/**
 * Orchestrates the operation pipeline: create, queue, cancel.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Operations\Actions\Formula;
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
	 * Plan gating (free-tier object cap, undo/formula availability).
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * The rules deciding which objects a change can actually be written to.
	 *
	 * @var Write_Rules
	 */
	private Write_Rules $rules;

	/**
	 * Build the service.
	 *
	 * @param Query_Engine        $engine     Query engine.
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Field_Providers     $providers  Field provider registry.
	 * @param Lock                $lock       Single-writer lock.
	 * @param Operation_Scheduler $scheduler Scheduler for chunk hand-off/cancel.
	 * @param License|null        $license    Plan gating; defaults to unlimited
	 *                                        (unlicensed development and tests).
	 * @param Write_Rules|null    $rules      Applicability rules; the default set is
	 *                                        stateless, so it is built when omitted.
	 */
	public function __construct(
		Query_Engine $engine,
		Operations $operations,
		Changes $changes,
		Field_Providers $providers,
		Lock $lock,
		Operation_Scheduler $scheduler,
		?License $license = null,
		?Write_Rules $rules = null
	) {
		$this->engine     = $engine;
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->providers  = $providers;
		$this->lock       = $lock;
		$this->scheduler  = $scheduler;
		$this->license    = $license ?? License::unlimited();
		$this->rules      = $rules ?? new Write_Rules();
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
		$this->assert_formulas_allowed( $actions );

		return $this->operations->create( $filter, $actions, $mode, $source, $user_id );
	}

	/**
	 * Dry-run a filter and actions without writing (CONTEXT §2, the Preview step):
	 * how many objects the filter matches, of those how many the edit will actually
	 * change, and — for the rest — exactly why not.
	 *
	 * An object is omitted when the edit cannot be written to it: it lacks a field
	 * the change reads (the formula engine skips on any empty or non-numeric input,
	 * never coercing to zero), or WooCommerce would refuse the value on save and
	 * keep what was there. Each of those is a {@see Write_Rules} requirement,
	 * enforced identically here and at {@see queue()}, which is what makes the
	 * previewed count equal what execution applies — and what an undo reverts.
	 *
	 * The per-reason counts partition the omitted total exactly: each requirement is
	 * added to the previous ones, so an object is attributed to the first rule it
	 * fails rather than to every rule it fails. They therefore always sum to
	 * `omitted`, whatever the overlap between rules.
	 *
	 * Warnings are the other direction — objects the change applies to perfectly
	 * well but damages in passing, such as the sale prices a lower regular price
	 * wipes. They are counted, never subtracted.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @return array{matched: int, applicable: int, omitted: int, omitted_by: list<array{reason: string, count: int}>, warnings: list<array{code: string, count: int}>}
	 *
	 * @throws InvalidArgumentException When an action targets an unsupported field.
	 */
	public function preview( Filter $filter, array $actions ): array {
		$this->assert_fields_supported( $actions );

		$matched      = $this->engine->count( $filter );
		$requirements = $this->rules->requirements( $actions );

		$applicable = $matched;
		$omitted_by = array();
		$applied    = array();

		// Add one requirement at a time: the drop in the count is precisely the
		// objects this rule is the first to exclude.
		foreach ( $requirements as $requirement ) {
			$applied[]  = $requirement;
			$remaining  = 0 === $applicable ? 0 : $this->engine->count( $filter, $applied );
			$difference = $applicable - $remaining;
			$applicable = $remaining;

			if ( $difference > 0 ) {
				$omitted_by[] = array(
					'reason' => $requirement->reason(),
					'count'  => $difference,
				);
			}
		}

		return array(
			'matched'    => $matched,
			'applicable' => $applicable,
			'omitted'    => $matched - $applicable,
			'omitted_by' => $omitted_by,
			'warnings'   => $this->count_warnings( $filter, $actions, $matched ),
		);
	}

	/**
	 * Count the objects each of the actions' collateral warnings applies to,
	 * dropping the ones that affect nothing so the UI only ever shows live numbers.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param int                                     $matched Objects the filter matched.
	 * @return list<array{code: string, count: int}>
	 */
	private function count_warnings( Filter $filter, array $actions, int $matched ): array {
		if ( 0 === $matched ) {
			return array();
		}

		$warnings = array();

		foreach ( $this->rules->warnings( $actions ) as $predicate ) {
			$count = $this->engine->count( $filter, array( $predicate ) );

			if ( $count > 0 ) {
				$warnings[] = array(
					'code'  => $predicate->reason(),
					'count' => $count,
				);
			}
		}

		return $warnings;
	}

	/**
	 * Record an undo of an earlier operation as a draft (CONTEXT §2: undo is an
	 * operation, not a function — the inverse enters the same pipeline, has a
	 * preview, and is itself undoable). It carries no filter or actions; its
	 * targets are the parent's recorded deltas, frozen at {@see queue()} time. The
	 * conflict policy the user chose is persisted on it for the async run.
	 *
	 * @param int             $parent_op_id The operation to undo.
	 * @param Conflict_Policy $policy       How to resolve drifted objects.
	 * @param int             $user_id      Owner user id.
	 * @return int The new undo operation id.
	 *
	 * @throws InvalidArgumentException When the parent is missing or still active.
	 * @throws License_Limited          When undo is used without a paid plan.
	 */
	public function undo( int $parent_op_id, Conflict_Policy $policy, int $user_id ): int {
		if ( ! $this->license->can_undo() ) {
			throw new License_Limited( 'Undo is a paid-plan feature.' );
		}

		$parent = $this->operations->find( $parent_op_id );

		if ( null === $parent ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		if ( $parent->status->is_active() ) {
			throw new InvalidArgumentException( 'A running operation cannot be undone; cancel it first.' );
		}

		return $this->operations->create(
			new Filter(),
			array(),
			$parent->mode,
			Operation_Source::UNDO,
			$user_id,
			$parent_op_id,
			$policy
		);
	}

	/**
	 * Preview an undo without writing: the total number of recorded changes that
	 * would be reverted, and a sample showing, per object, whether it would revert
	 * or be skipped as drift (CONTEXT §3). The exact skipped count is not computed
	 * here — that would mean reading every target object, which the architecture
	 * reserves for execution; the sample conveys the shape and the run reports the
	 * exact figure.
	 *
	 * @param int             $parent_op_id The operation to undo.
	 * @param Conflict_Policy $policy       Policy to reflect in the sample's action.
	 * @param int             $limit        Maximum sample rows.
	 * @return array{parent_op_id: int, total: int, conflict_policy: string, sample: list<array{id: int, field: string, current: ?string, restore_to: ?string, drift: bool, action: string}>}
	 *
	 * @throws InvalidArgumentException When the parent operation is missing.
	 * @throws License_Limited          When undo is used without a paid plan.
	 */
	public function preview_undo( int $parent_op_id, Conflict_Policy $policy, int $limit = 20 ): array {
		if ( ! $this->license->can_undo() ) {
			throw new License_Limited( 'Undo is a paid-plan feature.' );
		}

		$parent = $this->operations->find( $parent_op_id );

		if ( null === $parent ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		$total  = $this->changes->counts( $parent_op_id )['applied'];
		$sample = array();

		foreach ( $this->changes->applied_sample( $parent_op_id, max( 0, $limit ) ) as $row ) {
			$resolved = $this->providers->for_storage( $row->field_type, $row->field_key );
			$product  = wc_get_product( $row->object_id );

			$current = null;
			if ( null !== $resolved && $product instanceof \WC_Product ) {
				$current = Values::to_string( $resolved['provider']->read( $product, $resolved['key'] ) );
			}

			$drift = ! Values::equal( $current, $row->new_value );

			$sample[] = array(
				'id'         => $row->object_id,
				'field'      => null === $resolved ? $row->field_key : $resolved['key'],
				'current'    => $current,
				'restore_to' => $row->old_value,
				'drift'      => $drift,
				'action'     => ( $drift && Conflict_Policy::SKIP === $policy ) ? 'skip' : 'revert',
			);
		}

		return array(
			'parent_op_id'    => $parent_op_id,
			'total'           => $total,
			'conflict_policy' => $policy->value,
			'sample'          => $sample,
		);
	}

	/**
	 * Freeze an operation's targets and hand it to the scheduler. For a normal
	 * operation this resolves the filter exactly once and seeds one row per
	 * (object, field); for an undo it copies the parent's applied deltas as the
	 * frozen target list (CONTEXT §2). From here both drive through the identical
	 * chunked pipeline.
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

		// The lock is held across freezing and handed to the async runner on
		// success. The finally releases it on every other exit — the settle-now
		// path (nothing to do) and any thrown failure, such as a free-tier
		// object-cap breach in freeze_edit() — so the catalog is never left
		// wedged against future operations.
		$handed_off = false;

		try {
			$target = $operation->is_undo()
				? $this->freeze_undo( $operation )
				: $this->freeze_edit( $op_id, $operation );

			if ( 0 === $target ) {
				// Nothing to do: settle immediately (the finally frees the lock).
				$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
				return;
			}

			$this->operations->set_target_count( $op_id, $target );
			$this->operations->set_batch_size( $op_id, self::DEFAULT_BATCH );
			$this->operations->set_status( $op_id, Operation_Status::QUEUED );
			$this->operations->touch( $op_id );

			$this->scheduler->enqueue_chunk( $op_id, self::DEFAULT_BATCH );
			$handed_off = true;
		} finally {
			if ( ! $handed_off ) {
				$this->lock->release( $op_id );
			}
		}
	}

	/**
	 * Freeze a normal operation's targets: resolve the filter once and seed one
	 * pending row per (object, field).
	 *
	 * @param int       $op_id     Operation id.
	 * @param Operation $operation The operation.
	 * @return int Number of target objects frozen.
	 *
	 * @throws License_Limited When the target count exceeds the free-tier cap.
	 */
	private function freeze_edit( int $op_id, Operation $operation ): int {
		$actions = $operation->actions();
		$this->assert_fields_supported( $actions );

		$filter = $operation->filter();

		// The one-and-only filter resolution (CONTEXT §2), narrowed to the objects
		// the edit can actually change: those carrying every field it reads, and
		// those whose new value WooCommerce will keep rather than override on save.
		// An object failing either would be skipped at execution, so leaving it out
		// here makes the frozen target count equal what is applied — and what an
		// undo later reverts — so progress never disagrees with the outcome. These
		// are the same requirements {@see preview()} counted, so the number the user
		// was shown is the number that runs.
		$ids = $this->engine->resolve( $filter, $this->rules->requirements( $actions ) );

		if ( array() === $ids ) {
			return 0;
		}

		// Enforce the free-tier object cap before seeding, so a free site cannot
		// stage a 68k-row operation it is not licensed to run (CONTEXT §5). The
		// count is known from the single resolution above — no extra query.
		$max = $this->license->max_objects_per_op();

		if ( count( $ids ) > $max ) {
			throw new License_Limited(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
				sprintf( 'This operation targets %1$d objects; the free plan is limited to %2$d per operation.', count( $ids ), $max )
			);
		}

		$this->changes->seed( $op_id, $this->seed_rows( $ids, $actions, $filter->scope()->value ) );

		return count( $ids );
	}

	/**
	 * Freeze an undo's targets: copy the parent operation's applied deltas as the
	 * frozen revert list.
	 *
	 * @param Operation $operation The undo operation.
	 * @return int Number of deltas to revert.
	 */
	private function freeze_undo( Operation $operation ): int {
		return $this->changes->seed_from_parent( $operation->id, (int) $operation->parent_op_id );
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
	 * Build the cross-product of target ids and action fields as seed rows. The
	 * object_type is stamped from the filter's scope, so a variation operation's
	 * deltas record `variation` — which is what undo and the audit log read back,
	 * and what tells the runner it is loading a variation (CONTEXT §4).
	 *
	 * @param int[]                                   $ids         Frozen target ids.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions     Actions to apply.
	 * @param string                                  $object_type product|variation.
	 * @return array<int, array{object_type: string, object_id: int, field_type: string, field_key: string}>
	 */
	private function seed_rows( array $ids, array $actions, string $object_type ): array {
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
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'field_type'  => $spec['field_type'],
					'field_key'   => $spec['field_key'],
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

	/**
	 * Fail fast if a formula action is used without a paid plan (CONTEXT §5): the
	 * free tier gets Set_Value edits, not computed formulas.
	 *
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to check.
	 *
	 * @throws License_Limited When a formula action is used on the free tier.
	 */
	private function assert_formulas_allowed( array $actions ): void {
		if ( $this->license->can_use_formulas() ) {
			return;
		}

		foreach ( $actions as $action ) {
			if ( $action instanceof Formula ) {
				throw new License_Limited( 'Formulas are a paid-plan feature.' );
			}
		}
	}
}
