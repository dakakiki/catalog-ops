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
 * The operations and changes tables are queried through information_schema by
 * our own migration, which cannot see the CREATE TEMPORARY TABLEs WP_UnitTestCase
 * would otherwise substitute. So — like {@see \CatalogOps\Tests\Integration\Database\SchemaTest}
 * — these tests opt out of the temporary-table rewrite and manage real tables,
 * dropping and re-installing a clean schema around each test.
 *
 * DDL implicitly commits, so the per-test transaction does not roll data back;
 * dropping the tables in tear_down removes both schema and rows. Subclasses that
 * also create posts (products) must clean those up themselves.
 *
 * The file name intentionally does not end in `Test.php`, so PHPUnit does not
 * collect this abstract base as a test case.
 */
abstract class Operations_Database_Case extends WP_UnitTestCase {

	/**
	 * Schema under a real (non-temporary) table regime.
	 *
	 * @var Schema
	 */
	protected Schema $schema;

	/**
	 * Install a clean schema on real tables.
	 */
	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;
		$this->schema = new Schema( $wpdb );
		$this->schema->drop();
		$this->schema->install();
	}

	/**
	 * Drop the plugin tables (and thus the rows written during the test).
	 */
	public function tear_down(): void {
		$this->schema->drop();
		parent::tear_down();
	}
}
