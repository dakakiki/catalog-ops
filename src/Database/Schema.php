<?php
/**
 * Schema installer and migration runner.
 *
 * @package CatalogOps\Database
 */

namespace CatalogOps\Database;

use CatalogOps\Database\Migrations\Create_Core_Tables;
use CatalogOps\Database\Migrations\Create_Saved_Filters_Table;
use CatalogOps\Database\Migrations\Migration;
use wpdb;

/**
 * Owns the plugin's database schema: the list of migrations, the stored schema
 * version, and the table-name helpers the rest of the plugin builds queries on.
 *
 * On activation {@see Schema::install()} runs every pending migration; on a
 * normal admin request {@see Schema::maybe_upgrade()} does the same only when
 * the installed version is behind, so a plugin update applies new migrations
 * without a deactivate/reactivate cycle.
 */
final class Schema {

	/**
	 * Option holding the installed schema version.
	 */
	private const VERSION_OPTION = 'catalogops_db_version';

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Migrations in ascending version order.
	 *
	 * @var list<Migration>
	 */
	private array $migrations;

	/**
	 * Build the schema with its ordered list of migrations.
	 *
	 * @param wpdb $wpdb WordPress database handle (usually the $wpdb global).
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;

		$this->migrations = array(
			new Create_Core_Tables(),
			new Create_Saved_Filters_Table(),
		);

		usort(
			$this->migrations,
			static fn( Migration $a, Migration $b ): int => $a->version() <=> $b->version()
		);
	}

	/**
	 * Fully-qualified name of the operations table.
	 */
	public function operations_table(): string {
		return $this->wpdb->prefix . 'catalogops_operations';
	}

	/**
	 * Fully-qualified name of the changes table.
	 */
	public function changes_table(): string {
		return $this->wpdb->prefix . 'catalogops_changes';
	}

	/**
	 * Fully-qualified name of the saved filters table.
	 */
	public function saved_filters_table(): string {
		return $this->wpdb->prefix . 'catalogops_saved_filters';
	}

	/**
	 * The schema version this build targets (the newest migration's version).
	 */
	public function target_version(): int {
		$versions = array_map(
			static fn( Migration $migration ): int => $migration->version(),
			$this->migrations
		);

		return array() === $versions ? 0 : max( $versions );
	}

	/**
	 * The schema version currently installed in this database.
	 */
	public function installed_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Install or bring the schema fully up to date. Idempotent — dbDelta only
	 * applies the diff, so calling this against an already-current database is
	 * a no-op. Intended for the activation hook.
	 */
	public function install(): void {
		$this->run_pending();
	}

	/**
	 * Run pending migrations only when the installed version is behind. Cheap
	 * to call on every admin request: the version check is a single autoloaded
	 * option read.
	 */
	public function maybe_upgrade(): void {
		if ( $this->installed_version() < $this->target_version() ) {
			$this->run_pending();
		}
	}

	/**
	 * Drop the plugin's tables and forget the schema version. Destructive —
	 * for uninstall and integration-test teardown, never normal operation.
	 */
	public function drop(): void {
		$operations    = $this->operations_table();
		$changes       = $this->changes_table();
		$saved_filters = $this->saved_filters_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$this->wpdb->query( "DROP TABLE IF EXISTS {$saved_filters}" );
		$this->wpdb->query( "DROP TABLE IF EXISTS {$changes}" );
		$this->wpdb->query( "DROP TABLE IF EXISTS {$operations}" );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Apply every migration newer than the installed version, then record the
	 * new version.
	 */
	private function run_pending(): void {
		$installed = $this->installed_version();
		$target    = $this->target_version();

		if ( $installed >= $target ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();

		foreach ( $this->migrations as $migration ) {
			if ( $migration->version() > $installed ) {
				$migration->up( $this->wpdb, $charset_collate );
			}
		}

		update_option( self::VERSION_OPTION, $target );
	}
}
