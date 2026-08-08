<?php
/**
 * Integration tests for the schema installer / migration runner.
 *
 * @package CatalogOps\Tests\Integration\Database
 */

namespace CatalogOps\Tests\Integration\Database;

use CatalogOps\Database\Schema;
use WP_UnitTestCase;

/**
 * These tests issue real DDL against the test database, so they clean up
 * explicitly: DDL implicitly commits, which escapes the per-test transaction
 * that WP_UnitTestCase would otherwise roll back.
 *
 * @covers \CatalogOps\Database\Schema
 */
final class SchemaTest extends WP_UnitTestCase {

	private Schema $schema;

	public function set_up(): void {
		parent::set_up();

		// dbDelta issues real CREATE TABLE statements. WP_UnitTestCase otherwise
		// rewrites those to CREATE TEMPORARY TABLE, which information_schema (used
		// by our assertions) cannot see — so we opt out for these tests.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->schema->drop();
	}

	public function tear_down(): void {
		$this->schema->drop();
		parent::tear_down();
	}

	public function test_install_creates_both_tables(): void {
		$this->assertFalse( $this->table_exists( $this->schema->operations_table() ) );

		$this->schema->install();

		$this->assertTrue( $this->table_exists( $this->schema->operations_table() ) );
		$this->assertTrue( $this->table_exists( $this->schema->changes_table() ) );
	}

	public function test_tables_use_innodb(): void {
		$this->schema->install();

		$this->assertSame( 'InnoDB', $this->table_engine( $this->schema->operations_table() ) );
		$this->assertSame( 'InnoDB', $this->table_engine( $this->schema->changes_table() ) );
	}

	public function test_install_records_the_target_version(): void {
		$this->assertSame( 0, $this->schema->installed_version() );

		$this->schema->install();

		$this->assertSame( $this->schema->target_version(), $this->schema->installed_version() );
		$this->assertSame( $this->schema->target_version(), (int) get_option( 'catalogops_db_version' ) );
	}

	public function test_install_is_idempotent(): void {
		$this->schema->install();
		$columns_first = $this->column_count( $this->schema->operations_table() );

		// A second install must not error or alter the shape.
		$this->schema->install();
		$columns_second = $this->column_count( $this->schema->operations_table() );

		$this->assertSame( $columns_first, $columns_second );
		$this->assertTrue( $this->table_exists( $this->schema->operations_table() ) );
	}

	public function test_maybe_upgrade_installs_when_behind(): void {
		$this->assertFalse( $this->table_exists( $this->schema->changes_table() ) );

		$this->schema->maybe_upgrade();

		$this->assertTrue( $this->table_exists( $this->schema->changes_table() ) );
	}

	public function test_drop_removes_tables_and_version(): void {
		$this->schema->install();
		$this->assertTrue( $this->table_exists( $this->schema->operations_table() ) );

		$this->schema->drop();

		$this->assertFalse( $this->table_exists( $this->schema->operations_table() ) );
		$this->assertFalse( $this->table_exists( $this->schema->changes_table() ) );
		$this->assertFalse( get_option( 'catalogops_db_version' ) );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);

		return $count > 0;
	}

	private function table_engine( string $table ): string {
		global $wpdb;

		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
	}

	private function column_count( string $table ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
	}
}
