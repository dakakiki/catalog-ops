<?php
/**
 * Integration tests for what the plugin tells WooCommerce about itself.
 *
 * These are declarations rather than behaviour, which is exactly why they need
 * pinning: nothing in the plugin stops working if one is dropped, so nothing else
 * would notice. What breaks is on WooCommerce's side of the fence — a store owner
 * warned off enabling a feature, or not warned about a version that cannot run
 * this — and neither shows up in a run of the pipeline.
 *
 * @package CatalogOps\Tests\Integration
 */

namespace CatalogOps\Tests\Integration;

use WP_UnitTestCase;

/**
 * @covers ::catalogops
 */
final class PluginHeadersTest extends WP_UnitTestCase {

	/**
	 * The declaration is honest: this plugin filters and writes products and
	 * variations, and never reads or touches an order or the tables one lives in.
	 *
	 * Making it is still necessary. WooCommerce treats silence as incompatibility
	 * on its HPOS screen, so an undeclared plugin holds back a setting that has
	 * nothing to do with it.
	 *
	 * Asserted as "this file hooks the only action the declaration can ride" rather
	 * than by reading WooCommerce's registry back, and the reason is the test
	 * environment rather than a shortcut. `FeaturesController` normalizes the file
	 * it is handed through `get_wp_plugin_id()`, which resolves against
	 * `WP_PLUGIN_DIR`; the suite requires `catalogops.php` straight out of the repo,
	 * so the plugin has no id to normalize to and the declaration is dropped with a
	 * log line. Asserting the registry here would fail for a reason that has nothing
	 * to do with this plugin, and pass only by installing the repo into a plugins
	 * directory to satisfy a test.
	 *
	 * What can be pinned is the thing that would actually be lost — somebody
	 * deleting the `add_action` — so this looks for a callback on that hook that was
	 * defined in the plugin file itself. The registration proper is verified on a
	 * real install.
	 */
	public function test_the_plugin_hooks_its_hpos_declaration(): void {
		global $wp_filter;

		$this->assertArrayHasKey( 'before_woocommerce_init', $wp_filter );

		$from_plugin_file = false;

		foreach ( $wp_filter['before_woocommerce_init']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! $callback['function'] instanceof \Closure ) {
					continue;
				}

				$defined_in = ( new \ReflectionFunction( $callback['function'] ) )->getFileName();

				if ( CATALOGOPS_FILE === $defined_in ) {
					$from_plugin_file = true;
				}
			}
		}

		$this->assertTrue(
			$from_plugin_file,
			'nothing in catalogops.php hooks before_woocommerce_init, so HPOS compatibility is never declared'
		);
	}

	/**
	 * The two headers WooCommerce reads to decide whether it should let this run at
	 * all. They are comments, so nothing enforces them but a test.
	 */
	public function test_the_plugin_names_the_woocommerce_versions_it_supports(): void {
		$data = get_file_data(
			CATALOGOPS_FILE,
			array(
				'requires' => 'WC requires at least',
				'tested'   => 'WC tested up to',
			)
		);

		$this->assertNotSame( '', $data['requires'], 'WC requires at least is missing from the plugin header' );
		$this->assertNotSame( '', $data['tested'], 'WC tested up to is missing from the plugin header' );

		// A floor above the ceiling would refuse every store, including the one it
		// was tested on.
		$this->assertTrue(
			version_compare( $data['requires'], $data['tested'], '<=' ),
			'WC requires at least is higher than WC tested up to'
		);
	}
}
