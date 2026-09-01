<?php
/**
 * Migration 6 — the skip-reason column on the changes table.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `skip_reason` to {prefix}catalogops_changes: for a row the runner did not
 * write, which rule left it untouched ({@see \CatalogOps\Operations\Skip_Reason}).
 * A skipped row without a reason cannot be acted on, so the audit log, the result
 * bar, and the completion email all read this column.
 *
 * Nullable with no default: rows written before this migration keep a NULL, which
 * every reader renders as an unspecified skip rather than inventing a reason.
 *
 * Migration 1 created the table, so this alters in place. MySQL 5.7 has no
 * `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an information_schema
 * check, keeping the migration safe to re-run.
 */
final class Add_Change_Skip_Reason_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 6;
	}

	/**
	 * Add the column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_changes';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'skip_reason'
			)
		);

		if ( 0 === $exists ) {
			// The table and column identifiers are code constants, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN skip_reason varchar(32) DEFAULT NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
