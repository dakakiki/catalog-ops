<?php
/**
 * Action Scheduler wrapper for the write engine.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * Isolates the Action Scheduler API (bundled with WooCommerce) behind one class.
 * Chunks run asynchronously as `catalogops_run_chunk` actions grouped per
 * operation, so a whole operation's remaining work can be cancelled at once
 * (CONTEXT §3). A single recurring `catalogops_watchdog` action supervises them.
 *
 * Every call guards on the function existing, so the plugin degrades to a no-op
 * (rather than fataling) if Action Scheduler is somehow unavailable.
 */
final class Scheduler implements Operation_Scheduler {

	/**
	 * Hook fired for each chunk of work.
	 */
	public const CHUNK_HOOK = 'catalogops_run_chunk';

	/**
	 * Hook fired periodically to supervise running operations.
	 */
	public const WATCHDOG_HOOK = 'catalogops_watchdog';

	/**
	 * Hook fired periodically to purge deltas past the retention window.
	 */
	public const RETENTION_HOOK = 'catalogops_retention';

	/**
	 * Hook fired periodically to fire due schedules (M5).
	 */
	public const SCHEDULES_HOOK = 'catalogops_run_schedules';

	/**
	 * Action Scheduler group for the watchdog and other plugin-wide actions.
	 */
	private const GROUP = 'catalogops';

	/**
	 * How often the watchdog runs, in seconds.
	 */
	private const WATCHDOG_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How often the retention purge runs, in seconds.
	 */
	private const RETENTION_INTERVAL = DAY_IN_SECONDS;

	/**
	 * How often the schedule supervisor runs, in seconds. Interval presets are
	 * hourly at the finest, so a 5-minute cadence is timely without flooding the
	 * queue; the exact firing time depends on the host's cron regardless.
	 */
	private const SCHEDULES_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Enqueue the next chunk of an operation, in its own cancellable group.
	 *
	 * @param int $op_id      Operation id.
	 * @param int $batch_size Batch size for the next chunk.
	 */
	public function enqueue_chunk( int $op_id, int $batch_size ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		as_enqueue_async_action(
			self::CHUNK_HOOK,
			array(
				'op_id' => $op_id,
				'batch' => $batch_size,
			),
			$this->group( $op_id )
		);
	}

	/**
	 * Cancel every pending chunk for an operation.
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel_operation( int $op_id ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( self::CHUNK_HOOK, array(), $this->group( $op_id ) );
	}

	/**
	 * Ensure the recurring watchdog action is scheduled exactly once.
	 */
	public function ensure_watchdog(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::WATCHDOG_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::WATCHDOG_INTERVAL,
			self::WATCHDOG_INTERVAL,
			self::WATCHDOG_HOOK,
			array(),
			self::GROUP
		);
	}

	/**
	 * Ensure the recurring retention purge is scheduled exactly once.
	 */
	public function ensure_retention(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::RETENTION_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::RETENTION_INTERVAL,
			self::RETENTION_INTERVAL,
			self::RETENTION_HOOK,
			array(),
			self::GROUP
		);
	}

	/**
	 * Ensure the recurring schedule supervisor is scheduled exactly once.
	 */
	public function ensure_schedules(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::SCHEDULES_HOOK, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + self::SCHEDULES_INTERVAL,
			self::SCHEDULES_INTERVAL,
			self::SCHEDULES_HOOK,
			array(),
			self::GROUP
		);
	}

	/**
	 * The per-operation group name (enables mass cancellation).
	 *
	 * @param int $op_id Operation id.
	 */
	private function group( int $op_id ): string {
		return self::GROUP . '_' . $op_id;
	}
}
