<?php
/**
 * Multisite behaviour of the schema installer: a site created while the plugin
 * is network active receives the per-site schema.
 *
 * @package CatalogOps\Tests\Integration\Database
 */

namespace CatalogOps\Tests\Integration\Database;

use CatalogOps\Database\Schema;
use WP_UnitTestCase;

/**
 * The plugin keeps per-site data (tables are keyed by $wpdb->prefix), so a
 * network needs the schema installed on every site — on activation and on each
 * new site. This exercises the new-site path through the real wp_initialize_site
 * hook the booted plugin registers.
 *
 * Runs only under a multisite test installation (WP_MULTISITE=1); it self-skips
 * on single site. Like SchemaTest, it opts out of the temporary-table rewrite so
 * information_schema can see the created tables, and cleans up its own DDL.
 *
 * @group ms
 * @covers \CatalogOps\Plugin
 */
final class MultisiteSchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires a multisite installation (WP_MULTISITE=1).' );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_new_network_site_receives_the_schema(): void {
		// Plugin::on_new_site only installs when the plugin is network active.
		update_site_option(
			'active_sitewide_plugins',
			array( plugin_basename( CATALOGOPS_FILE ) => time() )
		);

		// Creating the site fires wp_initialize_site, which the booted plugin
		// hooks to install the schema on the new site.
		$blog_id = self::factory()->blog->create();

		switch_to_blog( $blog_id );

		global $wpdb;
		$schema     = new Schema( $wpdb );
		$table      = $schema->operations_table();
		$installed  = $this->table_exists( $table );
		$is_subsite = str_contains( $table, (string) $blog_id );

		// Drop our tables on the sub-site before it is torn down: wp_delete_site
		// removes only core blog tables, not the plugin's custom ones.
		$schema->drop();
		restore_current_blog();

		wp_delete_site( $blog_id );
		delete_site_option( 'active_sitewide_plugins' );

		$this->assertTrue( $installed, 'A newly created network site should receive the CatalogOps schema.' );
		$this->assertTrue( $is_subsite, 'Sub-site tables should use the per-site table prefix.' );
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
}
