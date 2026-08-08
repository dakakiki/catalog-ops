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
 * left them (applied ones applied, the rest pending), which is what makes a
 * failed operation resumable — a later chunk simply picks up the pending rows.
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
