<?php
/**
 * Migration 11 — the language a schedule belongs to.
 *
 * @package CatalogOps\Database\Migrations
 */

namespace CatalogOps\Database\Migrations;

use wpdb;

/**
 * Adds `language` to {prefix}catalogops_schedules.
 *
 * The sibling of {@see Add_Operation_Language_Column}, for the same reason and
 * under the same rules: a schedule is authored inside a language, it belongs
 * under that language in the list, and the list has to be able to filter on it in
 * SQL rather than by reading every row and deciding in PHP.
 *
 * **Denormalised, and the filter remains the record.** `filter_json` already
 * carries the language, frozen with the rest of the template when the schedule is
 * written, and it is what the engine reads at each fire. This column is a copy of
 * it, written from `Filter::language()` so the two cannot drift, and it exists
 * only because the schedules list pages — and polls, every eight seconds — so
 * "read them all and filter in PHP" is a full table scan on every tick, and the
 * count that pages the list would need the same scan again.
 *
 * **NULL means "not confined to a language"**, said by three situations at once:
 * a site with no WPML, a user who authored the schedule on WPML's "All
 * languages", and every schedule written before this migration. A NULL schedule
 * is listed under every language, or a shop that upgrades would open the page and
 * find its schedules gone.
 *
 * **Nothing about firing changes, and that is deliberate.** {@see \CatalogOps\Operations\Schedules::due()}
 * does not know this column exists: a cron tick has no language of its own, and a
 * due list narrowed by one would leave every schedule outside the site's default
 * language never firing at all — silently, for as long as nobody noticed. What
 * each run touches is decided by the language inside its own frozen filter, which
 * is where it has always been decided.
 *
 * `varchar(7)` matches `icl_translations.language_code`, the vocabulary this holds
 * a copy of. Not indexed: a shop has tens of schedules, not millions, and an index
 * on a column with two or three distinct values would not be used for reading all
 * of them in id order.
 *
 * Migration 5 created the table, so this alters in place, guarded by an
 * information_schema check because MySQL 5.7 has no `ADD COLUMN IF NOT EXISTS` —
 * which keeps the migration safe to re-run.
 */
final class Add_Schedule_Language_Column implements Migration {

	/**
	 * The schema version this migration installs.
	 */
	public function version(): int {
		return 11;
	}

	/**
	 * Add the column if it is not already present.
	 *
	 * @param wpdb   $wpdb            WordPress database handle.
	 * @param string $charset_collate Charset/collate clause (unused; ALTER inherits the table's).
	 */
	public function up( wpdb $wpdb, string $charset_collate ): void {
		unset( $charset_collate );

		$table = $wpdb->prefix . 'catalogops_schedules';

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
