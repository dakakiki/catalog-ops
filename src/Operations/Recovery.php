<?php
/**
 * Puts a run back to work when the process that was writing it disappeared.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The supervisor of last resort, and deliberately not a scheduled job.
 *
 * {@see Watchdog} was supposed to be this. It cannot be, and the reason is
 * circular: it is itself an Action Scheduler action, and the failure it exists to
 * clean up is one that stops Action Scheduler running anything. A process killed
 * mid-chunk leaves its queue entry claimed and unfinished; Action Scheduler allows
 * one batch at a time, so nothing else starts — including the watchdog. Measured
 * twice on 2026-09-04: forty-eight minutes with the admin screen open and nothing
 * moved, until the queue was run by hand.
 *
 * So this one runs on `init`, which is to say on any request that reaches PHP at
 * all: a shop page, an admin screen, the REST poll the operations list already
 * makes every couple of seconds, WP-CLI, and — the one that matters for a site
 * nobody is looking at — the cron request the setup instructions require anyway.
 * A site whose schedules can fire is by definition a site that gets requests, so
 * there is no configuration in which scheduling works and recovery does not.
 *
 * What it will not do is decide anything expensive. The common case, by an
 * enormous margin, is that nothing is running, and that case costs one integer
 * comparison against an autoloaded option ({@see Lock::watching()}). Only a
 * request that finds a writer registered goes on to read the operation row.
 *
 * It is careful about what it concludes. A cold heartbeat is evidence, not proof —
 * a process can be blocked rather than dead — so recovery never destroys anything:
 * it does not fail the operation, does not touch the frozen target list, and does
 * not go near the schedule that spawned the run. It releases the abandoned queue
 * entry, mints a new lock generation, and asks for the next chunk. The old worker,
 * if it was alive after all, discovers on its next pulse that the run is no longer
 * its own and stops ({@see Chunk_Runner}).
 *
 * The schedule is left strictly alone, and that is a rule rather than an omission.
 * A user's Stop or Undo pauses a schedule because it is a decision about the
 * change; a server that restarted is not a decision about anything, and an hourly
 * schedule left paused by one would mean somebody has to get up in the night to
 * start it again. {@see Schedule_Runner::fire()} already reasons this way about a
 * lock race: "nothing is wrong with this schedule".
 */
final class Recovery {

	/**
	 * How long a run may go without a heartbeat before this class steps in.
	 *
	 * Two minutes against a pulse of {@see Chunk_Runner::PULSE_SECONDS} — around
	 * twenty-four missed beats, which no working process produces. It can be this
	 * short only because the heartbeat now comes from inside the object loop; while
	 * it was written at chunk boundaries the longest *healthy* silence was one whole
	 * chunk, which is why {@see Watchdog::STALL_THRESHOLD} had to be ten minutes.
	 *
	 * Deliberately shorter than the watchdog's threshold, and the order matters:
	 * this runs first and tries to continue the work, and only a run that survives
	 * being nudged reaches the watchdog, which does the irreversible thing.
	 */
	public const COLD_AFTER = 2 * MINUTE_IN_SECONDS;

	/**
	 * Operations repository.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Changes repository, to see whether any frozen work is left.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Single-writer lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Queue hand-off.
	 *
	 * @var Operation_Scheduler
	 */
	private Operation_Scheduler $scheduler;

	/**
	 * Build the recovery.
	 *
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Lock                $lock       Single-writer lock.
	 * @param Operation_Scheduler $scheduler  Queue hand-off.
	 */
	public function __construct( Operations $operations, Changes $changes, Lock $lock, Operation_Scheduler $scheduler ) {
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->lock       = $lock;
		$this->scheduler  = $scheduler;
	}

	/**
	 * Look, and put the run back to work if it has gone quiet.
	 *
	 * @param int|null $now Unix time; defaults to now. For tests.
	 * @return bool Whether a run was handed on.
	 */
	public function run( ?int $now = null ): bool {
		$op_id = $this->lock->watching();

		if ( $op_id <= 0 ) {
			return false;
		}

		$operation = $this->operations->find( $op_id );

		if ( null === $operation || ! $operation->status->is_active() ) {
			// The registered writer has settled without saying so — the flag outlived
			// its run. Clear it rather than reading this row on every request for ever.
			$this->lock->release( $op_id );

			return false;
		}

		if ( ! $this->is_cold( $operation, $now ?? time() ) ) {
			return false;
		}

		if ( 0 === $this->changes->pending_count( $op_id ) ) {
			// Nothing frozen is left, so there is nothing to hand on. The run is owed
			// only its ending, which the watchdog gives it.
			return false;
		}

		// Order matters. The abandoned queue entry goes first, because while it is
		// held nothing can be started at all and a fresh chunk would simply queue
		// behind it. The new generation goes second, so that from this instant the
		// old worker fails its own check. Only then is more work asked for.
		$this->scheduler->release_stuck_chunks( $op_id );
		$this->lock->acquire( $op_id );

		$batch = $operation->batch_size > 0 ? $operation->batch_size : Operation_Service::DEFAULT_BATCH;

		$this->operations->touch( $op_id );
		$this->scheduler->enqueue_chunk( $op_id, $batch );
		$this->scheduler->kick();

		/**
		 * Fires when a run whose writer disappeared has been handed to a new one.
		 *
		 * @param int $op_id The operation put back to work.
		 */
		do_action( 'catalogops_operation_recovered', $op_id );

		return true;
	}

	/**
	 * Whether a run has stopped reporting for longer than this class tolerates.
	 *
	 * A `queued` run counts too, and it is the case every supervisor has missed:
	 * {@see Operations::stalled_before()} selects on `status = 'running'`, so an
	 * operation whose process died before its first chunk ever ran stays queued for
	 * ever, invisible, holding the lock. It has no heartbeat of its own, so its
	 * creation time stands in as one.
	 *
	 * @param Operation $operation The operation to judge.
	 * @param int       $now       Unix time.
	 */
	private function is_cold( Operation $operation, int $now ): bool {
		$last = $operation->last_progress_at ?? $operation->created_at;

		return strtotime( $last . ' UTC' ) < $now - self::COLD_AFTER;
	}
}
