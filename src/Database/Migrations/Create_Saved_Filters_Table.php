<?php
/**
 * Migration 2 — storage for named, reusable filters.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Creates {prefix}catalogops_saved_filters: one row per named filter a user
 * stores, holding the same filter_json shape the operations table uses.
 */
final class Create_Saved_Filters_Table implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * Create the saved_filters table.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause from wpdb::get_charset_collate().
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_saved_filters';

		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(191) NOT NULL,
	filter_json longtext,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY user_id (user_id)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}
}
