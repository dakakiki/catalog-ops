<?php
/**
 * Migration 4 — the conflict-policy column undo needs on the operations table.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `conflict_policy` to {prefix}catalogops_operations. An undo operation runs
 * asynchronously in chunks, so the skip/force choice the user made when starting
 * it (CONTEXT §3) must be persisted on the operation rather than held in the
 * request that launched it. The column is null for every operation that is not an
 * undo.
 *
 * Migration 1 created the table, so this alters in place; MySQL 5.7 has no
 * `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an information_schema
 * check, keeping the migration safe to re-run.
 */
final class Add_Conflict_Policy_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * Add the conflict_policy column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table  = $wpdb->prefix . 'catalogops_operations';
		$column = 'conflict_policy';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		if ( 0 === $exists ) {
			// Table and column identifiers are code constants, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} varchar(10) DEFAULT NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
