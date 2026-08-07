<?php
/**
 * Minimal WP-CLI stubs for exercising command classes under PHPUnit, where the
 * real WP-CLI runtime is not loaded. Only the surface the plugin's commands use
 * is implemented.
 *
 * @package CatalogOps\Tests
 */

namespace {

	/**
	 * Raised in place of WP_CLI::error(), which halts the real runtime.
	 */
	class CatalogOps_WP_CLI_Halt extends \RuntimeException {}

	if ( ! class_exists( 'WP_CLI' ) ) {

		/**
		 * Stand-in for the WP_CLI facade.
		 */
		class WP_CLI {

			/**
			 * Registered commands, keyed by name. Inspectable from tests.
			 *
			 * @var array<string, mixed>
			 */
			public static array $commands = array();

			/**
			 * Record a command registration.
			 *
			 * @param string $name    Command name.
			 * @param mixed  $handler Command handler.
			 * @param array  $args    Registration args.
			 */
			public static function add_command( $name, $handler, $args = array() ) {
				self::$commands[ $name ] = $handler;
			}

			/**
			 * Swallow an informational line.
			 *
			 * @param string $message Message.
			 */
			public static function log( $message ) {}

			/**
			 * Swallow a success line.
			 *
			 * @param string $message Message.
			 */
			public static function success( $message ) {}

			/**
			 * Swallow a warning line.
			 *
			 * @param string $message Message.
			 */
			public static function warning( $message ) {}

			/**
			 * Halt, mirroring the real runtime, so tests can assert on failure.
			 *
			 * @param string $message Message.
			 *
			 * @throws \CatalogOps_WP_CLI_Halt Always.
			 */
			public static function error( $message ) {
				throw new \CatalogOps_WP_CLI_Halt( $message );
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\\Utils\\make_progress_bar' ) ) {
		/**
		 * No-op progress bar matching the tick()/finish() surface.
		 *
		 * @param string $message Label (ignored).
		 * @param int    $count   Total (ignored).
		 * @return object
		 */
		function make_progress_bar( $message, $count ) {
			return new class() {
				/**
				 * Advance the bar.
				 */
				public function tick() {}

				/**
				 * Finish the bar.
				 */
				public function finish() {}
			};
		}
	}
}
