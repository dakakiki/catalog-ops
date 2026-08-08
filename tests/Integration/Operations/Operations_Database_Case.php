<?php
/**
 * Shared setup for integration tests that hit the operations/changes tables.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Database\Schema;
use WP_UnitTestCase;

/**
 * Installs the plugin schema once per test class as real tables — before the
 * per-test transactions begin, so the CREATE/ALTER DDL (which our migration
 * verifies through information_schema) runs against real tables rather than the
 * CREATE TEMPORARY TABLEs WP_UnitTestCase would otherwise substitute. Test
 * bodies then do only DML, so each test's writes roll back for isolation.
 *
 * A leading drop() clears any version-option/table mismatch a previous DDL-heavy
 * class may have committed, so the install is never skipped by the version gate.
 *
 * The file name intentionally does not end in `Test.php`, so PHPUnit does not
 * collect this abstract base as a test case.
 */
abstract class Operations_Database_Case extends WP_UnitTestCase {

	/**
	 * Schema handle for the current test.
	 *
	 * @var Schema
	 */
	protected Schema $schema;

	/**
	 * Create the schema as real tables before any test in the class runs.
	 *
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		global $wpdb;

		$schema = new Schema( $wpdb );
		$schema->drop();
		$schema->install();
	}

	/**
	 * Drop the schema once the class is done.
	 */
	public static function wpTearDownAfterClass(): void {
		global $wpdb;

		( new Schema( $wpdb ) )->drop();
	}

	/**
	 * Provide a per-test schema handle.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->schema = new Schema( $wpdb );
	}
}
