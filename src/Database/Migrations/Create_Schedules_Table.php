<?php
/**
 * Migration 5 — storage for scheduled and recurring operations.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Creates {prefix}catalogops_schedules: one row per scheduled operation (M5,
 * CONTEXT §4). Each row is a filter + actions template plus a recurrence and the
 * next fire time; the supervisor fires due rows through the normal pipeline.
 *
 * `next_run` is indexed because the supervisor's hot query is "active schedules
 * whose next_run has passed".
 */
final class Create_Schedules_Table implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * Create the schedules table.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause from wpdb::get_charset_collate().
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_schedules';

		$sql = "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	name varchar(191) NOT NULL DEFAULT '',
	filter_json longtext,
	actions_json longtext,
	mode varchar(10) NOT NULL DEFAULT 'safe',
	recurrence varchar(20) NOT NULL DEFAULT 'once',
	status varchar(20) NOT NULL DEFAULT 'active',
	next_run datetime NOT NULL,
	last_run datetime DEFAULT NULL,
	last_op_id bigint(20) unsigned DEFAULT NULL,
	notify_email varchar(191) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY status (status),
	KEY next_run (next_run)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql );
	}
}
