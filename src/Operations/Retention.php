<?php
/**
 * The retention window for recorded deltas, and the job that enforces it.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * How long an operation's deltas are kept before they are purged (CONTEXT §3):
 * 30 days by default, configurable between 7 and 180. This is at once the undo
 * window and the audit retention, so it must be explicit in the UI — a user needs
 * to know that undoing a two-month-old operation is no longer possible.
 *
 * Only the changes rows are purged; the operations themselves remain as the
 * lasting audit record of what ran and when. The purge is driven by a daily
 * Action Scheduler job.
 */
final class Retention {

	/**
	 * Option holding the configured retention window, in days.
	 */
	public const OPTION = 'catalogops_retention_days';

	/**
	 * Default retention window (CONTEXT §3).
	 */
	public const DEFAULT_DAYS = 30;

	/**
	 * Shortest window a user may configure.
	 */
	public const MIN_DAYS = 7;

	/**
	 * Longest window a user may configure.
	 */
	public const MAX_DAYS = 180;

	/**
	 * Changes repository.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Build the service.
	 *
	 * @param Changes $changes Changes repository.
	 */
	public function __construct( Changes $changes ) {
		$this->changes = $changes;
	}

	/**
	 * The configured retention window, clamped into the allowed range so a stray
	 * option value can never widen or eliminate the window.
	 */
	public function days(): int {
		return $this->clamp( (int) get_option( self::OPTION, self::DEFAULT_DAYS ) );
	}

	/**
	 * Set the retention window, clamped to the allowed range.
	 *
	 * @param int $days Requested window, in days.
	 * @return int The value actually stored.
	 */
	public function set_days( int $days ): int {
		$clamped = $this->clamp( $days );
		update_option( self::OPTION, $clamped );

		return $clamped;
	}

	/**
	 * Purge the deltas of operations that fell out of the retention window.
	 * Hooked to the recurring `catalogops_retention` action.
	 *
	 * @param int $max_operations Maximum operations to purge this run.
	 * @return array{operations: int, rows: int}
	 */
	public function purge( int $max_operations = 100 ): array {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $this->days() * DAY_IN_SECONDS );

		return $this->changes->purge_completed_before( $cutoff, $max_operations );
	}

	/**
	 * Clamp a day count into the configurable range.
	 *
	 * @param int $days Requested window.
	 */
	private function clamp( int $days ): int {
		return max( self::MIN_DAYS, min( self::MAX_DAYS, $days ) );
	}
}
