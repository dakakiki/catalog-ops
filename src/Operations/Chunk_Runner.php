<?php
/**
 * Executes one chunk of an operation and schedules the next.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

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
	 * How often a running chunk marks itself alive, in seconds.
	 *
	 * The heartbeat used to be written only at the chunk's edges — once on entry and
	 * once after the whole object loop — so the longest a *healthy* run could stay
	 * silent was one entire chunk, and a chunk may hold {@see MAX_BATCH} objects.
	 * Every threshold built on that silence had to be longer than the slowest
	 * imaginable chunk, which is why {@see Watchdog::STALL_THRESHOLD} is ten minutes:
	 * not because ten minutes of quiet means death, but because less than that could
	 * not be told apart from work.
	 *
	 * Beating from inside the loop separates the two for the first time. A live
	 * process now says so every few seconds however long its chunk runs, so silence
	 * becomes evidence rather than a guess — which lets the wait shrink honestly, and
	 * closes the hazard in the other direction too: a legitimately slow chunk could
	 * previously outlast the watchdog and be failed while it was still writing.
	 *
	 * Five seconds is chosen against the measured cadence — objects take on the order
	 * of 80ms in safe mode, so this is a write every sixty-odd objects, against sixty
	 * object saves. Cheaper than the counters the same loop already keeps.
	 */
	private const PULSE_SECONDS = 5;

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
	 * The rules that name why a write did not stick.
	 *
	 * @var Write_Rules
	 */
	private Write_Rules $rules;

	/**
	 * Build the runner.
	 *
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Field_Providers     $providers  Field provider registry.
	 * @param Operation_Scheduler $scheduler  Scheduler for the next chunk.
	 * @param Lock                $lock       Single-writer lock.
	 * @param Write_Rules|null    $rules      Rules explaining a refused write; the
	 *                                        default set is stateless, so it is built
	 *                                        when omitted.
	 */
	public function __construct(
		Operations $operations,
		Changes $changes,
		Field_Providers $providers,
		Operation_Scheduler $scheduler,
		Lock $lock,
		?Write_Rules $rules = null
	) {
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->providers  = $providers;
		$this->scheduler  = $scheduler;
		$this->lock       = $lock;
		$this->rules      = $rules ?? new Write_Rules();
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
			$this->finalize( $operation );
			return;
		}

		$started = microtime( true );

		$by_object = array();
		foreach ( $rows as $row ) {
			$by_object[ $row->object_id ][] = $row;
		}

		$plan = $this->plan_for( $operation, array_map( 'intval', array_keys( $by_object ) ) );

		// Progress is counted in the unit this operation's own target was set in, and
		// the two kinds differ: an edit freezes a target of *objects*, which is the
		// number its preview promised ("1,855 products will change"), while an undo
		// freezes the parent's applied rows and its preview says "143 changes will be
		// reverted". Counting objects for both — which is what this did — left an
		// undo of a multi-field operation reporting two against a target of four, so
		// its bar stopped at half on a run that had finished.
		//
		// Making everything rows was tried and is wrong: it puts the frozen target of
		// an ordinary edit above the number the preview promised, and "the previewed
		// count is the count the run delivers" is the promise the whole pipeline is
		// built to keep. Each path stays in the unit its own preview speaks.
		$counts_rows = $operation->is_undo();
		$processed   = 0;
		$failed      = 0;

		// How much of the above has reached the row, so each flush sends only what
		// has happened since the last one. {@see Operations::record_progress()} adds
		// to the stored counters rather than setting them, so sending the running
		// total on every beat would count the early objects once per beat.
		$flushed_processed = 0;
		$flushed_failed    = 0;

		// The pulse starts with the chunk: the touch above is this loop's first beat.
		$pulsed_at = microtime( true );

		// The generation this worker is writing under. Recovery can decide, wrongly,
		// that this process is dead — a timeout is evidence, never proof — and hand
		// the same operation to a new worker. Carrying the generation is what lets
		// this one find out and stop, rather than write beside it.
		$generation  = $this->lock->generation();
		$surrendered = false;

		/**
		 * Filters how often a running chunk marks itself alive and checks that the
		 * run is still its own, in seconds.
		 *
		 * A real seam rather than a hook for testing's sake: the default is set
		 * against a catalogue whose objects save in about 80ms, and a site whose
		 * saves are an order of magnitude slower or faster has a different idea of
		 * how much silence is normal. Zero pulses on every object, which is what the
		 * fence's own test needs to reach the check without waiting five seconds.
		 *
		 * @param float $seconds How long between beats.
		 * @param int   $op_id   The operation being written.
		 */
		$pulse = (float) apply_filters( 'catalogops_pulse_seconds', self::PULSE_SECONDS, $op_id );

		foreach ( $by_object as $object_id => $object_rows ) {
			$counts_as = $counts_rows ? count( $object_rows ) : 1;

			try {
				$this->apply_object( (int) $object_id, $object_rows, $plan );
				$processed += $counts_as;
			} catch ( Throwable $e ) {
				foreach ( $object_rows as $row ) {
					$this->changes->mark_failed( $row->id );
				}
				$failed += $counts_as;

				/**
				 * Fires when a single object in a chunk fails to update.
				 *
				 * @param int       $op_id     Operation id.
				 * @param int       $object_id The product id that failed.
				 * @param Throwable $e         The error thrown.
				 */
				do_action( 'catalogops_chunk_object_failed', $op_id, (int) $object_id, $e );
			}

			if ( microtime( true ) - $pulsed_at >= $pulse ) {
				// The beat carries the count, rather than only saying "still alive".
				//
				// The count used to be written once, after the loop, so a process killed
				// mid-chunk lost every object it had already saved: the change rows were
				// right and the number was not. Measured on the live catalogue —
				// 21,058 reported against 21,366 actually applied, every row `applied`
				// and none failed.
				//
				// That used to be a transient wrong number on a run that was over
				// anyway. It stopped being transient when {@see Recovery} made mid-chunk
				// death survivable: the run comes back and finishes, and the counter it
				// carries for the rest of its life is short by whatever the dead worker
				// had done since its last chunk boundary. A progress bar that never
				// reaches its target on a run that completed is worse than no bar.
				//
				// It costs nothing. This beat already spent one UPDATE on the heartbeat,
				// and {@see Operations::record_progress()} writes `last_progress_at`
				// itself — so this is the same single statement, carrying more.
				$this->operations->record_progress(
					$op_id,
					$processed - $flushed_processed,
					$failed - $flushed_failed
				);

				$flushed_processed = $processed;
				$flushed_failed    = $failed;

				$pulsed_at = microtime( true );

				// Checked on the pulse rather than per object: the same cadence that
				// says "alive" is the natural one to ask "still mine?", and it keeps
				// the extra read to one every few seconds instead of one per save.
				// The exposure is therefore a few seconds of writing after a wrongful
				// hand-over, not the rest of the chunk.
				// An empty generation means this worker never had an identity to lose,
				// not that it has lost one. Fencing on it would be the worst of both:
				// `still_held('')` is false by construction, so the very first pulse
				// would break the chain of a run nothing was actually contending —
				// silently, since a surrendering worker neither enqueues nor errors.
				// Without an identity there is simply nothing to check.
				if ( '' !== $generation && ! $this->lock->still_held( $generation ) ) {
					$surrendered = true;
					break;
				}
			}
		}

		// Whatever the beats have not carried yet — the objects saved since the last
		// one, and on a chunk shorter than a single pulse that is all of them.
		// Recorded either way: what this worker wrote before it let go really was
		// written, and the counters have to describe the catalogue rather than the
		// worker's fate. It runs even when the remainder is zero, so the chunk still
		// ends on a fresh heartbeat.
		$this->operations->record_progress(
			$op_id,
			$processed - $flushed_processed,
			$failed - $flushed_failed
		);

		if ( $surrendered ) {
			// Everything below belongs to whoever holds the lock now. Enqueueing would
			// put this worker back in a chain it has been removed from, and finalizing
			// would settle — or complete — an operation another process is still
			// writing. Leaving quietly is the whole of this worker's remaining job.
			return;
		}

		$next = $this->adapt_batch_size( $batch_size, microtime( true ) - $started, count( $by_object ) );
		$this->operations->set_batch_size( $op_id, $next );

		if ( $this->changes->pending_count( $op_id ) > 0 ) {
			$this->scheduler->enqueue_chunk( $op_id, $next );
			return;
		}

		$this->finalize( $operation );
	}

	/**
	 * Build the plan that decides each object's new value for this operation: the
	 * forward apply plan from the operation's actions, or — for an undo — the
	 * revert plan from the parent operation's recorded deltas (CONTEXT §2, one
	 * pipeline). The undo plan is handed the parent deltas for exactly this chunk's
	 * objects, fetched in a single query.
	 *
	 * @param Operation $operation  The operation being run.
	 * @param int[]     $object_ids Object ids in this chunk.
	 */
	private function plan_for( Operation $operation, array $object_ids ): Chunk_Plan {
		if ( $operation->is_undo() ) {
			$parent_deltas = $this->changes->applied_index( (int) $operation->parent_op_id, $object_ids );
			$policy        = $operation->conflict_policy ?? Conflict_Policy::safe_default();

			return new Revert_Plan( $this->providers, $parent_deltas, $policy );
		}

		return new Apply_Plan( $operation->actions(), $this->providers, $this->rules );
	}

	/**
	 * Snapshot, evaluate, and save one object's pending changes in a single save.
	 * The plan decides what to write (an edit) or revert (an undo) and stages it;
	 * the runner persists one save and claims each row (CONTEXT §3).
	 *
	 * @param int        $object_id   The product id.
	 * @param Change[]   $object_rows The object's pending change rows.
	 * @param Chunk_Plan $plan        The plan for this operation.
	 *
	 * @throws \RuntimeException When the product cannot be loaded.
	 */
	private function apply_object( int $object_id, array $object_rows, Chunk_Plan $plan ): void {
		$product = wc_get_product( $object_id );

		if ( ! $product instanceof WC_Product ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal message, surfaced only via the failure hook.
			throw new \RuntimeException( sprintf( 'Product %d could not be loaded.', $object_id ) );
		}

		$outcome = $plan->evaluate( $product, $object_rows );

		// Record skips before the save, mirroring the pre-refactor ordering.
		foreach ( $outcome->skipped() as $skip ) {
			$this->changes->mark_skipped( $skip['row']->id, $skip['old'], $skip['reason'] );
		}

		if ( ! $outcome->has_writes() ) {
			return;
		}

		// One write per object; in safe mode this refreshes the lookup table and
		// clears transients (CONTEXT §3).
		$product->save();

		// Record what WooCommerce actually persisted, not what was intended. On
		// save WooCommerce can silently override a value — it forces `outofstock`
		// when stock is managed at zero, and drops a sale price at or above the
		// regular price — mutating the in-memory product to the value it kept. So
		// each field is read back from the saved product: a value WooCommerce left
		// unchanged is a skip, not a false apply, and a value it altered is
		// recorded as-persisted. That keeps progress, history, and undo in step
		// with reality (CONTEXT §3), the same invariant the pre-write applicability
		// check upholds — carried across the save.
		//
		// With the applicability rules narrowing the frozen list up front, a skip
		// here is the exception rather than the rule: a formula result that could
		// not be predicted in SQL, or another plugin overriding the value. Each one
		// is named ({@see Write_Rules::explain()}) so the audit log can say why
		// instead of leaving the user with a bare count.
		foreach ( $outcome->applied() as $change ) {
			$row      = $change['row'];
			$resolved = $this->providers->for_storage( $row->field_type, $row->field_key );
			$field    = null !== $resolved ? $resolved['key'] : $row->field_key;
			$actual   = null !== $resolved
				? Values::to_string( $resolved['provider']->read( $product, $field ) )
				: $change['new'];

			if ( Values::equal( $actual, $change['old'] ) ) {
				$this->changes->mark_skipped(
					$row->id,
					$change['old'],
					$this->rules->explain( $product, $field, $change['new'], $change['old'] )
				);
			} else {
				$this->changes->mark_applied( $row->id, $change['old'], $actual );
			}
		}
	}

	/**
	 * Complete an operation and release the write-lock. When the operation is an
	 * undo, its parent moves to `reverted` — the operation the undo just rolled
	 * back (CONTEXT §3).
	 *
	 * @param Operation $operation The operation that has finished.
	 */
	private function finalize( Operation $operation ): void {
		// The last word on what this run did, taken from the rows rather than from
		// the running total. Beating the count out on every pulse narrowed the loss a
		// violent death causes; it cannot abolish it, because whatever a worker did
		// between its last beat and being killed was never reported by anyone. That
		// remainder used to survive to the end and be read for ever after: measured
		// on the live catalogue after a host was stopped mid-run — 581 rows applied,
		// 581 objects, every one of them written, and a completed operation whose bar
		// read 523 of 581.
		//
		// Here it can simply be asked. The rows are the record, they are complete the
		// moment nothing is pending, and one query buys a number that no longer
		// depends on which process happened to survive. See
		// {@see Changes::settled_counts()} for why the unit is the operation's own.
		$settled = $this->changes->settled_counts( $operation->id, $operation->is_undo() );
		$this->operations->set_progress( $operation->id, $settled['processed'], $settled['failed'] );

		$this->operations->set_status( $operation->id, Operation_Status::COMPLETED, true );

		if ( $operation->is_undo() && null !== $operation->parent_op_id ) {
			$this->operations->set_status( (int) $operation->parent_op_id, Operation_Status::REVERTED );
		}

		$this->lock->release( $operation->id );

		/**
		 * Fires once an operation has settled as completed. The M5 notifier listens
		 * here to email a report for scheduled operations (CONTEXT §4). Passing the
		 * id (not the stale snapshot) lets listeners read the final counters.
		 *
		 * @param int $op_id The completed operation's id.
		 */
		do_action( 'catalogops_operation_completed', $operation->id );
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
