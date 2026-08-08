<?php
/**
 * Executes one chunk of an operation and schedules the next.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Fields\Field_Providers;
use Throwable;
use WC_Product;

/**
 * The middle of the pipeline (CONTEXT §2): Snapshot → Execution → Verification,
 * for one chunk. Invoked by Action Scheduler as `catalogops_run_chunk`.
 *
 * Per object it loads the product once, reads each targeted field (the snapshot's
 * old_value), applies the action to get the new value, stages it, and saves once
 * — so in safe mode WooCommerce refreshes the meta lookup and transients a single
 * time per object (CONTEXT §3). Each applied change is claimed with the guarded
 * PENDING→APPLIED flip, so a re-run after an interruption never double-applies.
 *
 * The chunk is self-continuing: after processing it measures its own duration,
 * adapts the next batch size (shrink past a time budget, grow when fast), and
 * either enqueues the next chunk or finalizes. It refreshes the operation's
 * heartbeat so the watchdog can tell a live-but-slow run from a stalled one.
 */
final class Chunk_Runner {

	/**
	 * Shrink the next batch when a chunk runs longer than this (CONTEXT §3).
	 */
	private const SLOW_SECONDS = 15.0;

	/**
	 * Grow the next batch when a chunk finishes faster than this.
	 */
	private const FAST_SECONDS = 5.0;

	/**
	 * Smallest adaptive batch size.
	 */
	private const MIN_BATCH = 25;

	/**
	 * Largest adaptive batch size.
	 */
	private const MAX_BATCH = 1000;

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
	 * Scheduler for the next chunk.
	 *
	 * @var Operation_Scheduler
	 */
	private Operation_Scheduler $scheduler;

	/**
	 * Single-writer lock, released on finalize.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Build the runner.
	 *
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Field_Providers     $providers  Field provider registry.
	 * @param Operation_Scheduler $scheduler  Scheduler for the next chunk.
	 * @param Lock                $lock       Single-writer lock.
	 */
	public function __construct(
		Operations $operations,
		Changes $changes,
		Field_Providers $providers,
		Operation_Scheduler $scheduler,
		Lock $lock
	) {
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->providers  = $providers;
		$this->scheduler  = $scheduler;
		$this->lock       = $lock;
	}

	/**
	 * Process one chunk of an operation. Safe to call again on the same
	 * operation: it only ever touches still-pending rows.
	 *
	 * @param int $op_id      Operation id.
	 * @param int $batch_size Rows to process this chunk.
	 */
	public function run( int $op_id, int $batch_size ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation || ! $operation->status->is_active() ) {
			// Missing, paused, cancelled, or already finished — stop the chain.
			return;
		}

		if ( Operation_Status::QUEUED === $operation->status ) {
			$this->operations->set_status( $op_id, Operation_Status::RUNNING );
		}

		// Heartbeat up front so a slow first chunk is not mistaken for a stall.
		$this->operations->touch( $op_id );

		$rows = $this->changes->pending_chunk( $op_id, $batch_size );

		if ( array() === $rows ) {
			$this->finalize( $op_id );
			return;
		}

		$actions = $operation->actions();
		$started = microtime( true );

		$by_object = array();
		foreach ( $rows as $row ) {
			$by_object[ $row->object_id ][] = $row;
		}

		$processed = 0;
		$failed    = 0;

		foreach ( $by_object as $object_id => $object_rows ) {
			try {
				$this->apply_object( (int) $object_id, $object_rows, $actions );
				++$processed;
			} catch ( Throwable $e ) {
				foreach ( $object_rows as $row ) {
					$this->changes->mark_failed( $row->id );
				}
				++$failed;

				/**
				 * Fires when a single object in a chunk fails to update.
				 *
				 * @param int       $op_id     Operation id.
				 * @param int       $object_id The product id that failed.
				 * @param Throwable $e         The error thrown.
				 */
				do_action( 'catalogops_chunk_object_failed', $op_id, (int) $object_id, $e );
			}
		}

		$this->operations->record_progress( $op_id, $processed, $failed );

		$next = $this->adapt_batch_size( $batch_size, microtime( true ) - $started, count( $by_object ) );
		$this->operations->set_batch_size( $op_id, $next );

		if ( $this->changes->pending_count( $op_id ) > 0 ) {
			$this->scheduler->enqueue_chunk( $op_id, $next );
			return;
		}

		$this->finalize( $op_id );
	}

	/**
	 * Snapshot, apply, and save one object's pending changes in a single save.
	 *
	 * @param int      $object_id   The product id.
	 * @param Change[] $object_rows The object's pending change rows.
	 * @param Action[] $actions     The operation's actions.
	 *
	 * @throws \RuntimeException When the product cannot be loaded.
	 */
	private function apply_object( int $object_id, array $object_rows, array $actions ): void {
		$product = wc_get_product( $object_id );

		if ( ! $product instanceof WC_Product ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, surfaced only via the failure hook.
			throw new \RuntimeException( sprintf( 'Product %d could not be loaded.', $object_id ) );
		}

		// Index this object's rows by their storage identity so each action can
		// find the row it should claim.
		$row_for = array();
		foreach ( $object_rows as $row ) {
			$row_for[ $row->field_type->value . '|' . $row->field_key ] = $row;
		}

		// Staged changes to claim after a successful save: each is
		// array{row: Change, old: ?string, new: ?string}.
		$applied = array();

		foreach ( $actions as $action ) {
			$provider = $this->providers->for( $action->field() );

			if ( null === $provider ) {
				continue;
			}

			$key = $provider->field_type( $action->field() )->value . '|' . $provider->storage_key( $action->field() );

			if ( ! isset( $row_for[ $key ] ) ) {
				continue;
			}

			$row     = $row_for[ $key ];
			$current = $provider->read( $product, $action->field() );
			$new     = $action->apply( $current );

			if ( null === $new ) {
				// Nothing to write (e.g. a formula over an empty field): skip and
				// record it (CONTEXT §3), never coerce to zero.
				$this->changes->mark_skipped( $row->id, Values::to_string( $current ) );
				continue;
			}

			$provider->stage( $product, $action->field(), $new );
			$applied[] = array(
				'row' => $row,
				'old' => Values::to_string( $current ),
				'new' => Values::to_string( $new ),
			);
		}

		if ( array() === $applied ) {
			return;
		}

		// One write per object; in safe mode this refreshes the lookup table and
		// clears transients (CONTEXT §3).
		$product->save();

		foreach ( $applied as $change ) {
			$this->changes->mark_applied( $change['row']->id, $change['old'], $change['new'] );
		}
	}

	/**
	 * Complete an operation and release the write-lock.
	 *
	 * @param int $op_id Operation id.
	 */
	private function finalize( int $op_id ): void {
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
		$this->lock->release( $op_id );
	}

	/**
	 * Choose the next batch size from how long this chunk took (CONTEXT §3).
	 *
	 * @param int   $current Current batch size.
	 * @param float $elapsed Seconds the chunk took.
	 * @param int   $objects Objects processed this chunk.
	 */
	private function adapt_batch_size( int $current, float $elapsed, int $objects ): int {
		if ( $objects <= 0 ) {
			return $current;
		}

		if ( $elapsed > self::SLOW_SECONDS ) {
			return max( self::MIN_BATCH, intdiv( $current, 2 ) );
		}

		if ( $elapsed < self::FAST_SECONDS ) {
			return min( self::MAX_BATCH, (int) ceil( $current * 1.5 ) );
		}

		return $current;
	}
}
