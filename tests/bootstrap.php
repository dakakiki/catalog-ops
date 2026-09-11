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
	// A `wpdb` symbol, `__()` and `maybe_unserialize()`, so pure logic sitting on a
	// class that merely holds a database handle is unit-testable. Only on this
	// branch: core guards neither `__()` nor `wpdb`, so defining them before the
	// real WordPress loads would be a redeclare fatal across the whole integration
	// suite.
	require __DIR__ . '/stubs/wp.php';

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

// Install WooCommerce's own tables once WordPress is far enough along, and stand
// in WPML's translations table beside them.
tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		catalogops_create_translations_table();
	}
);

/**
 * Create WPML's `icl_translations` table, because CI has no WPML.
 *
 * The language frame reads this table and nothing else about WPML: the engine
 * asks the database whether the table exists rather than asking whether a plugin
 * is loaded, precisely so that the thing under test is durable state a test can
 * create rather than a plugin a test would have to counterfeit.
 *
 * **It is created here, once, and that is not a stylistic choice.** WordPress's
 * test case wraps every test in a transaction and rolls it back, and MySQL
 * commits the open transaction implicitly when it meets DDL. A `CREATE TABLE`
 * inside a test would therefore commit whatever that test had already written and
 * leave it behind for every test after it — a failure that shows up somewhere
 * else entirely. The bootstrap runs before the first transaction is opened, so
 * here it costs nothing.
 *
 * `ENGINE=InnoDB` is pinned for the mirror image of that reason: this database's
 * default engine is not guaranteed (the development machine's MySQL defaults to
 * MyISAM), and a MyISAM table ignores the surrounding transaction — rows a test
 * inserted into it would survive the rollback and be seen by the next test. The
 * rest of the definition is WPML 4.9.7's own, column for column, including the
 * unique key that makes the engine's join safe to write without a DISTINCT and
 * the `element_id` nullability the exclusion shapes depend on.
 */
function catalogops_create_translations_table(): void { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Prefixed; the sniff does not read the bootstrap's prefix list.
	global $wpdb;

	$table = $wpdb->prefix . 'icl_translations';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query(
		"CREATE TABLE IF NOT EXISTS {$table} (
			translation_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
			element_type VARCHAR(60) NOT NULL DEFAULT 'post_post',
			element_id BIGINT NULL DEFAULT NULL,
			trid BIGINT NOT NULL,
			language_code VARCHAR(7) NOT NULL,
			source_language_code VARCHAR(7),
			UNIQUE KEY el_type_id (element_type, element_id),
			UNIQUE KEY trid_lang (trid, language_code),
			KEY trid (trid),
			KEY id_type_language (element_id, element_type, language_code)
		) ENGINE=InnoDB"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

require $catalogops_tests_dir . '/includes/bootstrap.php';
