<?php
/**
 * Migration 9 — the two columns that let the history say who and why.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `schedule_name` and `note` to {prefix}catalogops_operations.
 *
 * Both answer questions the history could not, and they arrive together because
 * two columns on one table are one migration or two, and there is no reason for
 * it to be two.
 *
 * **`schedule_name` — a snapshot, not a lookup.** {@see Add_Operation_Schedule_Column}
 * added `schedule_id` and said plainly what it left unfinished: a deleted
 * schedule leaves its id dangling on the runs it made, and "what is lost is only
 * the name those runs could have been shown under". This is that name, written
 * once when the run is created and never touched again.
 *
 * A join would have cost no column and been wrong for an audit log twice over. A
 * schedule that is deleted takes the name of every run it ever made with it —
 * and this plugin deletes schedules routinely, twelve of them in one afternoon on
 * the dev catalogue. A schedule that is *renamed* silently relabels its own
 * history, so a run from March would be shown under a name invented in
 * September. A record of what happened has to say what things were called when
 * they happened; the id stays for anything that needs the live schedule.
 *
 * **`note` — the one thing the history can never reconstruct.** The rows already
 * carry what changed, when, by whom, and how many. They cannot carry *why*, and
 * that is the question asked three months later when somebody wants to know why
 * three thousand prices moved. It is written by the user before Apply, optional
 * always: a note demanded on the last step before a destructive action is a note
 * filled in with a full stop.
 *
 * Both are `varchar(191)`, matching `schedules.name`, `schedules.paused_reason`
 * and `notify_email`. 191 rather than 255 is this schema's habit for a short
 * string, and it is the utf8mb4 index-safe length should either ever need one.
 * Neither is indexed: both are read off a row already fetched by primary key.
 *
 * Nullable with no default, so every row written before this migration keeps a
 * NULL. That reads correctly — those runs have no note and their schedule's name
 * at the time is not recoverable, and inventing either would be worse than
 * admitting it. The UI shows nothing rather than a blank, and for a run that has
 * a `schedule_id` but no name it can still fall back to the live schedule.
 *
 * Migration 1 created the table, so this alters in place. MySQL 5.7 has no
 * `ADD COLUMN IF NOT EXISTS`, so each column is guarded by an information_schema
 * check, keeping the migration safe to re-run.
 */
final class Add_Operation_Note_And_Schedule_Name_Columns implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 9;
	}

	/**
	 * Add each column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		unset( $charset_collate );

		$table = $wpdb->prefix . 'catalogops_operations';

		foreach ( array( 'schedule_name', 'note' ) as $column ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$table,
					$column
				)
			);

			if ( 0 === $exists ) {
				// The table and column identifiers are code constants, not user
				// input — the loop above is a literal array.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} varchar(191) DEFAULT NULL" );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
