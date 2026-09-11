<?php
/**
 * Uninstall cleanup across a network: every site loses its tables, not just the
 * one that happened to be current.
 *
 * @package CatalogOps\Tests\Integration
 */

namespace CatalogOps\Tests\Integration;

use CatalogOps\Database\Schema;
use CatalogOps\Operations\Retention;
use CatalogOps\Rest\Settings_Controller;
use CatalogOps\Uninstaller;
use WP_UnitTestCase;

/**
 * The plugin keeps **per-site** tables, keyed by `$wpdb->prefix`, so a network
 * install has as many sets of them as it has sites. An uninstall that cleaned
 * only the current site would leave every other site's data behind with nothing
 * left to remove it — and nobody would find out, because uninstalling is the one
 * path where there is no screen afterwards to report a failure to.
 *
 * This became worth a test the day the logic moved out of `uninstall.php`: what
 * runs is unchanged, but *what reaches it* changed, and the loop had never been
 * exercised by anything.
 *
 * Like the other schema tests it opts out of the temporary-table rewrite. This
 * one has to: it is about real `DROP TABLE` against `information_schema`, which
 * cannot see a temporary table, so under the rewrite every assertion here would
 * pass while proving nothing.
 *
 * @covers \CatalogOps\Uninstaller
 */
final class UninstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Single site: the tables, the options and the tour flag all go.
	 */
	public function test_it_removes_the_schema_and_the_options(): void {
		global $wpdb;

		$schema = new Schema( $wpdb );
		$schema->install();

		update_option( Retention::OPTION, 45 );
		update_option( Settings_Controller::BACKUP_OPTION, array( 'at' => '2026-09-11' ) );

		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Settings_Controller::TOUR_META, 1 );

		$this->assertTrue(
			$this->table_exists( $schema->operations_table() ),
			'Guard: the schema should exist before the uninstall runs, or this test proves nothing.'
		);

		Uninstaller::run();

		foreach ( $this->tables( $schema ) as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "$table should be gone after an uninstall." );
		}

		$this->assertFalse( get_option( Retention::OPTION ), 'The retention option should be gone.' );
		$this->assertFalse( get_option( Settings_Controller::BACKUP_OPTION ), 'The backup acknowledgement should be gone.' );
		$this->assertSame( '', get_user_meta( $user_id, Settings_Controller::TOUR_META, true ), 'The tour flag should be gone from every user.' );
	}

	/**
	 * A network: the loop reaches a site that is not the current one.
	 *
	 * This is the assertion the single-site case cannot make. Cleaning only the
	 * current site would pass every check above and still leave the sub-site's
	 * tables standing.
	 *
	 * @group ms
	 */
	public function test_it_removes_the_schema_from_every_site_of_a_network(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite installation (WP_MULTISITE=1).' );
		}

		global $wpdb;

		$root = new Schema( $wpdb );
		$root->install();

		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );
		$sub       = new Schema( $wpdb );
		$sub->install();
		$sub_table = $sub->operations_table();
		restore_current_blog();

		$this->assertTrue( $this->table_exists( $sub_table ), 'Guard: the sub-site should have its own tables before the uninstall.' );
		$this->assertNotSame( $root->operations_table(), $sub_table, 'Guard: the two sites must not share a table, or the loop is untested.' );

		// Run it from the root site, which is the situation that matters: the
		// sub-site is reached only because the loop switches to it.
		Uninstaller::run();

		$this->assertFalse( $this->table_exists( $root->operations_table() ), 'The root site should lose its tables.' );
		$this->assertFalse( $this->table_exists( $sub_table ), 'The SUB-SITE should lose its tables too — this is the whole point of the loop.' );

		wp_delete_site( $blog_id );
	}

	/**
	 * Every table the plugin owns.
	 *
	 * @param Schema $schema The schema.
	 * @return array<int, string> Table names.
	 */
	private function tables( Schema $schema ): array {
		return array(
			$schema->operations_table(),
			$schema->changes_table(),
			$schema->saved_filters_table(),
			$schema->schedules_table(),
		);
	}

	/**
	 * Whether a table exists, asked of information_schema rather than of a SELECT
	 * — a missing table makes a SELECT an error rather than an empty result.
	 *
	 * @param string $table Fully-prefixed table name.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		) > 0;
	}
}
