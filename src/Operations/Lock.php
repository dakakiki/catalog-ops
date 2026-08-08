<?php
/**
 * The single-writer lock: one writing operation per site.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * Enforces CONTEXT §3's rule that only one operation writes to the catalog at a
 * time. The lock is a single option naming the holding operation. Acquisition
 * steals the lock when the recorded holder is gone or has reached a terminal
 * state, so a crashed operation cannot wedge the site forever — the watchdog
 * fails the stalled operation, and the next queue steals its abandoned lock.
 */
final class Lock {

	/**
	 * Option storing the id of the operation currently holding the lock.
	 */
	private const OPTION = 'catalogops_active_operation';

	/**
	 * Operations repository, to test whether the current holder is still alive.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Build the lock.
	 *
	 * @param Operations $operations Operations repository.
	 */
	public function __construct( Operations $operations ) {
		$this->operations = $operations;
	}

	/**
	 * Acquire the lock for an operation. Reentrant for the current holder; steals
	 * a lock whose holder no longer exists or has finished.
	 *
	 * @param int $op_id Operation asking for the lock.
	 * @return bool Whether the lock is now held by this operation.
	 */
	public function acquire( int $op_id ): bool {
		$holder = $this->holder();

		if ( $holder === $op_id ) {
			return true;
		}

		if ( $holder > 0 ) {
			$existing = $this->operations->find( $holder );

			if ( null !== $existing && $existing->status->is_active() ) {
				return false;
			}
		}

		update_option( self::OPTION, $op_id, false );

		return true;
	}

	/**
	 * Release the lock, but only if this operation holds it.
	 *
	 * @param int $op_id Operation releasing the lock.
	 */
	public function release( int $op_id ): void {
		if ( $this->holder() === $op_id ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The operation id currently holding the lock, or 0 when free.
	 */
	public function holder(): int {
		return (int) get_option( self::OPTION, 0 );
	}
}
