<?php
/**
 * Supervises running operations and fails the ones that have stalled.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The safety net from CONTEXT §3: if a running operation stops making progress
 * for ten minutes — the Action Scheduler chain died, the process was killed, the
 * host stopped running cron — nothing else would ever move it off `running`, and
 * its write-lock would wedge the site. The watchdog runs periodically, finds
 * operations whose heartbeat has gone cold, marks them `failed`, and frees their
 * lock so the catalog is writable again.
 *
 * It deliberately does not touch the changes rows: those stay exactly as the run
 * left them, applied ones applied and the rest pending, which is what makes a
 * failed operation resumable.
 *
 * Resuming is an explicit act, not something a later chunk drifts into. `failed`
 * is terminal ({@see Operation_Status::is_terminal()}) and
 * {@see Chunk_Runner::run()} turns away at the door for any status that is not
 * active, so a stray queued chunk cannot restart a run behind the user's back.
 * {@see Operation_Service::resume()} is the way back, and it matters that it
 * exists: without it the user is left with a part-changed catalogue and only two
 * poor moves — undo the fraction that landed, or run the whole filter again
 * against a catalogue that has since moved on.
 */
final class Watchdog {

	/**
	 * How long without progress marks an operation stalled (CONTEXT §3).
	 */
	private const STALL_THRESHOLD = 10 * MINUTE_IN_SECONDS;

	/**
	 * Operations repository.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Single-writer lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Build the watchdog.
	 *
	 * @param Operations $operations Operations repository.
	 * @param Lock       $lock       Single-writer lock.
	 */
	public function __construct( Operations $operations, Lock $lock ) {
		$this->operations = $operations;
		$this->lock       = $lock;
	}

	/**
	 * Fail every running operation whose heartbeat is older than the threshold.
	 * Hooked to the recurring `catalogops_watchdog` Action Scheduler action.
	 *
	 * @return int Number of operations failed.
	 */
	public function run(): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - self::STALL_THRESHOLD );
		$stalled = $this->operations->stalled_before( $cutoff );

		foreach ( $stalled as $operation ) {
			$this->operations->set_status( $operation->id, Operation_Status::FAILED );
			$this->lock->release( $operation->id );
		}

		return count( $stalled );
	}
}
