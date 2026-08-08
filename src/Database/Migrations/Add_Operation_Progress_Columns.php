<?php
/**
 * Migration 3 — heartbeat and adaptive-batch columns on the operations table.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds two columns the M2 write engine needs on {prefix}catalogops_operations:
 *
 *   - last_progress_at — updated every chunk so the watchdog can spot an
 *     operation that has stopped making progress (CONTEXT §3, 10-minute rule).
 *   - batch_size       — the adaptive chunk size carried between chunks, shrunk
 *     when a chunk runs long and grown when it runs fast.
 *
 * Migration 1 already created the table, so this alters in place. MySQL 5.7 has
 * no `ADD COLUMN IF NOT EXISTS`, so each column is guarded by an
 * information_schema check, keeping the migration safe to re-run.
 */
final class Add_Operation_Progress_Columns implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 3;
	}

	/**
	 * Add the columns if they are not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_operations';

		$this->add_column_if_missing( $wpdb, $table, 'last_progress_at', 'datetime DEFAULT NULL' );
		$this->add_column_if_missing( $wpdb, $table, 'batch_size', 'int(11) NOT NULL DEFAULT 0' );
	}

	/**
	 * Add one column when it does not yet exist on the table.
	 *
	 * @param wpdb   $wpdb       WordPress database handle.
	 * @param string $table      Fully-qualified table name.
	 * @param string $column     Column name.
	 * @param string $definition Column definition (type and default).
	 */
	private function add_column_if_missing( wpdb $wpdb, string $table, string $column, string $definition ): void {
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
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
