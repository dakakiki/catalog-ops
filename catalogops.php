<?php
/**
 * Plugin Name:       CatalogOps
 * Plugin URI:        https://github.com/dakakiki/catalog-ops
 * Description:       Bulk operations for large WooCommerce catalogs — filter, preview, snapshot, execute, undo.
 * Version:           0.6.0
 * Requires PHP:      8.1
 * Requires at least: 6.0
 * Requires Plugins:  woocommerce
 * Text Domain:       catalogops
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package CatalogOps
 */

defined( 'ABSPATH' ) || exit;

define( 'CATALOGOPS_VERSION', '0.6.0' );
define( 'CATALOGOPS_MIN_PHP', '8.1' );
define( 'CATALOGOPS_FILE', __FILE__ );
define( 'CATALOGOPS_PATH', plugin_dir_path( __FILE__ ) );
define( 'CATALOGOPS_URL', plugin_dir_url( __FILE__ ) );

/*
 * Guard: minimum PHP version.
 *
 * The plugin relies on enums and readonly properties, so it must refuse to
 * load on anything below 8.1 rather than fatal mid-request.
 */
if ( version_compare( PHP_VERSION, CATALOGOPS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version. */
						__( 'CatalogOps requires PHP %1$s or newer. This site runs PHP %2$s.', 'catalogops' ),
						CATALOGOPS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

/*
 * Guard: Composer autoloader.
 *
 * The repo ships without `vendor/`; a fresh checkout needs `composer install`
 * before the plugin can boot.
 */
$catalogops_autoloader = CATALOGOPS_PATH . 'vendor/autoload.php';

if ( ! is_readable( $catalogops_autoloader ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'CatalogOps is missing its autoloader. Run "composer install" in the plugin directory.', 'catalogops' )
			);
		}
	);

	return;
}

require $catalogops_autoloader;

/*
 * Create/upgrade the database schema on activation. On a network-wide activation
 * WordPress passes $network_wide = true, so the schema is installed on every site
 * in the network rather than just the one that ran the activation.
 */
register_activation_hook(
	CATALOGOPS_FILE,
	static function ( bool $network_wide = false ): void {
		\CatalogOps\Plugin::instance( CATALOGOPS_FILE )->activate( $network_wide );
	}
);

/*
 * Boot on `plugins_loaded` so WooCommerce, translations, and other
 * dependencies are already available.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		\CatalogOps\Plugin::instance( CATALOGOPS_FILE )->boot();
	}
);
