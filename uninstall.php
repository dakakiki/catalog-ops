<?php
/**
 * Uninstall cleanup: drop CatalogOps tables, forget the schema version, and
 * cancel the plugin's recurring background actions. Multisite-aware — the plugin
 * keeps per-site tables, so every site is cleaned in turn.
 *
 * Runs only when WordPress uninstalls the plugin (never on deactivate). The main
 * plugin file is not loaded in this context, so the autoloader is required here.
 *
 * @package CatalogOps
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$catalogops_autoloader = __DIR__ . '/vendor/autoload.php';

if ( ! is_readable( $catalogops_autoloader ) ) {
	return;
}

require $catalogops_autoloader;

/**
 * Drop the plugin's data for the current site.
 */
$catalogops_cleanup = static function (): void {
	global $wpdb;

	( new \CatalogOps\Database\Schema( $wpdb ) )->drop();
	( new \CatalogOps\Operations\Scheduler() )->unschedule_all();

	// Plugin options and the per-user onboarding flag (the schema version option
	// is removed by Schema::drop()).
	delete_option( \CatalogOps\Operations\Retention::OPTION );
	delete_option( \CatalogOps\Rest\Settings_Controller::BACKUP_OPTION );
	delete_metadata( 'user', 0, \CatalogOps\Rest\Settings_Controller::TOUR_META, '', true );
};

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $catalogops_site_id ) {
		switch_to_blog( (int) $catalogops_site_id );

		try {
			$catalogops_cleanup();
		} finally {
			restore_current_blog();
		}
	}

	return;
}

$catalogops_cleanup();
