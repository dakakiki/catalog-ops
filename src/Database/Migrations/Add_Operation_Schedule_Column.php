<?php
/**
 * Migration 8 — the schedule column on the operations table.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `schedule_id` to {prefix}catalogops_operations: which schedule, if any,
 * spawned this run.
 *
 * Until now a schedule and the operations it produced were linked in one
 * direction only, and one row deep. `schedules.last_op_id` names the operation
 * the most recent fire created, and {@see \CatalogOps\Operations\Schedules::record_run()}
 * overwrites it on the next one — so of a schedule's X runs exactly one, the
 * newest, could be traced back to it. Nothing on the operation pointed anywhere.
 *
 * That was survivable while the only reader was the completion email, which asks
 * about the run it has just finished. It is not survivable for undo. A user who
 * reverts a scheduled run and leaves the schedule firing gets their revert
 * overwritten on the next tick, and the code could not even name the schedule to
 * pause — for any run but the last, the question had no answer in the database.
 * This column is what makes {@see \CatalogOps\Operations\Operation_Service::undo()}
 * able to stop the thing that would undo the undo.
 *
 * Nullable with no default, because most operations have no schedule: a run
 * started from the admin screen, an undo, a CLI run. Every row written before
 * this migration keeps a NULL, which reads correctly — those runs cannot be
 * attributed now and inventing an attribution would be worse than admitting it.
 *
 * Deliberately unindexed. The column is read off a row already fetched by
 * primary key, which no index improves, and indexing it would put a write on
 * every operation insert to serve a reverse lookup ("every run this schedule
 * made") that nothing asks for yet. When something does, that is the commit that
 * should pay for it.
 *
 * A deleted schedule leaves its id behind on the runs it made, and nothing
 * detaches it. That is not the treatment the neighbouring link gets:
 * {@see \CatalogOps\Operations\Operations::detach_children()} nulls `parent_op_id`
 * before a parent operation is deleted, and {@see \CatalogOps\Operations\Schedules::delete()}
 * has no counterpart. The dangling id is harmless because both of its readers
 * look the schedule up and cope with its absence — the undo preview reports no
 * schedule, and the pause declines to pause one — so what is lost is only the
 * name those runs could have been shown under. Worth knowing before anything
 * starts trusting the id to resolve.
 *
 * Migration 1 created the table, so this alters in place. MySQL 5.7 has no
 * `ADD COLUMN IF NOT EXISTS`, so the column is guarded by an information_schema
 * check, keeping the migration safe to re-run.
 */
final class Add_Operation_Schedule_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 8;
	}

	/**
	 * Add the column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		$table = $wpdb->prefix . 'catalogops_operations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'schedule_id'
			)
		);

		if ( 0 === $exists ) {
			// The table and column identifiers are code constants, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN schedule_id bigint(20) unsigned DEFAULT NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
