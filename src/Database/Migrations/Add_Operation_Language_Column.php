<?php
/**
 * Migration 10 — the language a run was made in.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `language` to {prefix}catalogops_operations.
 *
 * Under WPML the user's language is the frame the whole plugin works inside, not
 * a choice offered anywhere: whichever language they are in is the catalogue they
 * see, filter, edit and schedule. A run therefore belongs to a language the way it
 * belongs to a user, and the history has to be able to say which — otherwise a
 * shopkeeper working in Serbian opens the history and finds runs against the
 * English catalogue mixed into it with nothing to tell them apart.
 *
 * **This column is denormalised on purpose, and it is not a snapshot in the sense
 * {@see Add_Operation_Note_And_Schedule_Name_Columns} means.** `schedule_name`
 * exists because the fact would otherwise be *lost*. This one is never lost:
 * `filter_json` already carries the language, frozen with the rest of the filter
 * at creation, and that remains the record of what the run was told to do. The
 * column exists because the history list has to filter on it — with paging, on
 * every load — and a WHERE clause cannot reach inside a longtext of JSON on MySQL
 * 5.7 without reading every row it is trying to avoid reading.
 *
 * It is written from `Filter::language()` rather than passed in beside it, so the
 * column and the filter it summarises cannot drift apart: there is one value and
 * the column is a copy of it.
 *
 * **NULL means "not confined to a language", and it means it for three different
 * sites at once** — one with no WPML at all, one where the user was on WPML's
 * "All languages", and every run made before this migration existed. All three
 * are the same statement about the run, so they get the same NULL, and the
 * history shows a NULL run under every language. That last part is the migration
 * working: a shop that upgrades must not open its history and find it empty.
 *
 * `varchar(7)` matches `icl_translations.language_code` exactly, which is the
 * value this column holds a copy of and the only vocabulary it can be compared
 * against. It is wide enough for everything WPML can produce, because WPML
 * declares the same 7 itself. Not indexed: the history reads at most a few
 * hundred rows per site ordered by id, and an index on a column with two or three
 * distinct values would not be used for that.
 *
 * Migration 1 created the table, so this alters in place, guarded by an
 * information_schema check because MySQL 5.7 has no `ADD COLUMN IF NOT EXISTS` —
 * which keeps the migration safe to re-run.
 */
final class Add_Operation_Language_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 10;
	}

	/**
	 * Add the column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		unset( $charset_collate );

		$table = $wpdb->prefix . 'catalogops_operations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'language'
			)
		);

		if ( 0 === $exists ) {
			// The table identifier is a code constant, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN language varchar(7) DEFAULT NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}
}
