<?php
/**
 * Migration 7 — the paused-reason column on the schedules table.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `paused_reason` to {prefix}catalogops_schedules: when the supervisor pauses
 * a schedule because it could not fire, what stopped it.
 *
 * A schedule that pauses itself and says nothing is barely better than one that
 * never runs — the user is left with a status badge and a Resume button that will
 * stop it again on the next tick. Until now the only account of such a failure
 * lived outside the plugin: the exception escaped as far as Action Scheduler,
 * which logged its message against a failed action. Containing that throw is the
 * right fix, and it takes the log entry with it, so the reason needs somewhere of
 * its own to live.
 *
 * Nullable with no default: an active schedule, and every row written before this
 * migration, keeps a NULL, which the admin table renders as no explanation rather
 * than inventing one. It is cleared on resume and on a successful run, so the
 * column only ever describes the pause a schedule is currently in.
 *
 * Migration 5 created the table, so this alters in place. MySQL 5.7 has no
 * `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an information_schema
 * check, keeping the migration safe to re-run.
 */
final class Add_Schedule_Paused_Reason_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 7;
	}

	/**
	 * Add the column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_schedules';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'paused_reason'
			)
		);

		if ( 0 === $exists ) {
			// The table and column identifiers are code constants, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN paused_reason varchar(191) DEFAULT NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
