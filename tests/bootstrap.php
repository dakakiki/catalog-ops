<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit suite needs only the Composer autoloader. The integration suite
 * additionally loads the WordPress test framework (and WooCommerce, when its
 * path is provided) — pointed to by the WP_TESTS_DIR environment variable.
 *
 * @package CatalogOps\Tests
 */

$catalogops_root = dirname( __DIR__ );

$catalogops_autoloader = $catalogops_root . '/vendor/autoload.php';

if ( ! file_exists( $catalogops_autoloader ) ) {
	fwrite( STDERR, "Could not find vendor/autoload.php. Run \"composer install\" first.\n" );
	exit( 1 );
}

require $catalogops_autoloader;

// WP-CLI is not loaded under PHPUnit; provide minimal stubs so command classes
// can be exercised directly. Guarded so the real runtime always wins.
if ( ! class_exists( 'WP_CLI' ) ) {
	require __DIR__ . '/stubs/wp-cli.php';
}

$catalogops_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $catalogops_tests_dir ) {
	// No WordPress test framework available. Provide a stand-in base class so the
	// integration test files still parse and skip themselves; unit tests run
	// normally.
	if ( ! class_exists( 'WP_UnitTestCase' ) ) {
		require __DIR__ . '/stubs/wp-unit-testcase.php';
	}

	return;
}

// Let WordPress find the PHPUnit Polyfills shipped via Composer.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $catalogops_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $catalogops_tests_dir . '/includes/functions.php';

// Load WooCommerce (if provided) and this plugin into the test WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $catalogops_root ) {
		$catalogops_wc = getenv( 'WC_PLUGIN_DIR' );

		if ( $catalogops_wc && file_exists( $catalogops_wc . '/woocommerce.php' ) ) {
			require $catalogops_wc . '/woocommerce.php';
		}

		require $catalogops_root . '/catalogops.php';
	}
);

// Run the suite as fully licensed. When the Freemius SDK is bundled locally it
// reports the test site as free (no opt-in), which would gate the pipeline and
// fail tests that exercise undo/formulas/large operations. Override the
// container's License with an unlimited one once the plugin has booted; the
// gating itself is covered by LicenseGatingTest, which builds services with
// explicit free/unlimited licenses rather than the container's.
tests_add_filter(
	'catalogops_booted',
	static function ( $catalogops_plugin ) {
		$catalogops_plugin->container()->instance(
			\CatalogOps\Licensing\License::class,
			\CatalogOps\Licensing\License::unlimited()
		);
	}
);

// Install WooCommerce's own tables once WordPress is far enough along.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
	}
);

require $catalogops_tests_dir . '/includes/bootstrap.php';
