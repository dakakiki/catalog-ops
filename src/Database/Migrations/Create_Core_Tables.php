<?php
/**
 * Migration 1 — the two core tables described in CONTEXT section 3.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Creates {prefix}catalogops_operations and {prefix}catalogops_changes.
 *
 * The `changes` table is deliberately lightly indexed (only the primary key
 * plus the op/object lookup): it takes a bulk INSERT of one row per targeted
 * object during the seed phase, and every extra index slows that hot path.
 *
 * MySQL 5.7 constraints from CONTEXT: no CTEs, window functions, functional
 * indexes, or DESC indexes are used here — the schema is plain B-tree indexes.
 */
final class Create_Core_Tables implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 1;
	}

	/**
	 * Create the operations and changes tables.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause from wpdb::get_charset_collate().
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$operations = $wpdb->prefix . 'catalogops_operations';
		$changes    = $wpdb->prefix . 'catalogops_changes';

		// status: draft|queued|running|paused|completed|failed|reverted.
		// source: ui|cli|schedule|undo. mode: safe|fast.
		$operations_sql = "CREATE TABLE {$operations} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL,
	completed_at datetime DEFAULT NULL,
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'draft',
	source varchar(20) NOT NULL DEFAULT 'ui',
	parent_op_id bigint(20) unsigned DEFAULT NULL,
	filter_json longtext,
	actions_json longtext,
	target_count int(11) NOT NULL DEFAULT 0,
	processed int(11) NOT NULL DEFAULT 0,
	failed int(11) NOT NULL DEFAULT 0,
	mode varchar(10) NOT NULL DEFAULT 'safe',
	PRIMARY KEY  (id),
	KEY status (status),
	KEY created_at (created_at)
) ENGINE=InnoDB {$charset_collate};";

		// object_type: product|variation. field_type: post_field|meta|term|attribute.
		// status: 0 pending, 1 applied, 2 failed, 3 skipped.
		$changes_sql = "CREATE TABLE {$changes} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	operation_id bigint(20) unsigned NOT NULL,
	object_type varchar(20) NOT NULL DEFAULT 'product',
	object_id bigint(20) unsigned NOT NULL,
	field_type varchar(20) NOT NULL,
	field_key varchar(191) NOT NULL,
	old_value longtext,
	new_value longtext,
	status tinyint(4) NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	KEY op_obj (operation_id, object_id)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $operations_sql );
		dbDelta( $changes_sql );
	}
}
