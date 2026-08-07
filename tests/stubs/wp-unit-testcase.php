<?php
/**
 * Stand-in for WP_UnitTestCase used when the WordPress test suite is not loaded.
 *
 * Without it, PHPUnit fatals while parsing the integration test files (they
 * extend a class that does not exist). With it, those files load and each test
 * reports itself skipped, so `composer test` runs the unit suite cleanly on a
 * plain checkout while the integration suite still runs on CI.
 *
 * @package CatalogOps\Tests
 */

if ( ! class_exists( 'WP_UnitTestCase' ) ) {

	/**
	 * Minimal skip-everything base for integration tests off-CI.
	 */
	class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {

		/**
		 * Skip every integration test when WordPress is unavailable.
		 */
		protected function setUp(): void {
			parent::setUp();
			$this->markTestSkipped(
				'Integration tests require the WordPress test suite; set WP_TESTS_DIR to run them.'
			);
		}
	}
}
