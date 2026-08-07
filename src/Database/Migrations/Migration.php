<?php
/**
 * Contract for a single, versioned schema migration.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * One migration = one integer version + the change it applies.
 *
 * Migrations run in ascending version order, and only those whose version is
 * greater than the installed schema version are applied. Versions are dense
 * integers (1, 2, 3, …); the next migration simply returns the next number.
 */
interface Migration {

	/**
	 * The schema version this migration brings the database up to.
	 */
	public function version(): int;

	/**
	 * Apply the migration.
	 *
	 * Implementations should be safe to re-run: use dbDelta() for tables so a
	 * partially-applied schema converges instead of erroring.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Result of wpdb::get_charset_collate().
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void;
}
