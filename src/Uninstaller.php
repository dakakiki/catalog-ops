<?php
/**
 * What CatalogOps removes when it is deleted.
 *
 * @package CatalogOps
 */

namespace CatalogOps;

use CatalogOps\Database\Schema;
use CatalogOps\Operations\Retention;
use CatalogOps\Operations\Scheduler;
use CatalogOps\Rest\Settings_Controller;

/**
 * Drops the plugin's tables, cancels its recurring background actions and
 * forgets its options — once per site, because the data is per site.
 *
 * **This used to live in `uninstall.php`, and could not stay there.** WordPress
 * gives that file priority over the uninstall hook, so a plugin that ships one
 * silently prevents the Freemius SDK's own uninstall work from running at all —
 * which is why Freemius refuses a deployment that contains the file. The logic
 * is unchanged; only where it is reached from has moved, and it is now reached
 * from two places depending on whether the SDK is present (see catalogops.php).
 *
 * Runs only on delete, never on deactivate.
 */
final class Uninstaller {

	/**
	 * Remove everything, on every site of a network.
	 *
	 * The loop is not an optimisation of a single-site path — the plugin keeps
	 * per-site tables keyed by `$wpdb->prefix`, so a network install has as many
	 * sets of them as it has sites, and cleaning only the current one would leave
	 * every other site's data behind with nothing left to remove it.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::clean_current_site();

			return;
		}

		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $sites as $site_id ) {
			switch_to_blog( (int) $site_id );

			// `finally`, so one site that throws does not strand the switch and
			// take every site after it down with it.
			try {
				self::clean_current_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Remove the plugin's data for whichever site is current.
	 */
	private static function clean_current_site(): void {
		global $wpdb;

		( new Schema( $wpdb ) )->drop();
		( new Scheduler() )->unschedule_all();

		// The schema version option is removed by Schema::drop(), so it is not
		// repeated here. The tour flag is user meta rather than an option, and is
		// deleted across every user at once.
		delete_option( Retention::OPTION );
		delete_option( Settings_Controller::BACKUP_OPTION );
		delete_metadata( 'user', 0, Settings_Controller::TOUR_META, '', true );
	}
}
