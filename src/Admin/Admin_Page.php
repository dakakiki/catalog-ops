<?php
/**
 * The CatalogOps admin screen that hosts the React app.
 *
 * @package CatalogOps\Admin
 */

namespace CatalogOps\Admin;

use CatalogOps\Licensing\License;
use CatalogOps\Operations\Scheduler;

/**
 * Registers the top-level admin menu, renders the mount point, and enqueues the
 * built React bundle (when present) with its REST config.
 */
final class Admin_Page {

	public const MENU_SLUG = 'catalogops';

	private const CAPABILITY = 'manage_woocommerce';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plan gating, surfaced to the React app so the free tier sees upsell prompts
	 * instead of paid-only controls.
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * Build the admin page.
	 *
	 * @param string       $plugin_file Absolute path to the main plugin file.
	 * @param License|null $license     Plan gating; defaults to unlimited
	 *                                  (unlicensed development and tests).
	 */
	public function __construct( string $plugin_file, ?License $license = null ) {
		$this->plugin_file = $plugin_file;
		$this->license     = $license ?? License::unlimited();
	}

	/**
	 * Register the top-level menu. Hook to admin_menu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'CatalogOps', 'catalogops' ),
			__( 'CatalogOps', 'catalogops' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			$this->menu_icon(),
			56
		);

		// The icon is a white badge; WordPress dims an inactive menu icon to ~0.6,
		// which would grey it out. One rule keeps it crisp in every state.
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
	}

	/**
	 * The admin-menu icon: a black-and-white version of the brand "layers" mark,
	 * shipped as a static SVG file (assets/menu-icon.svg). A linked file renders its
	 * colours exactly as authored — the solid dark top layer survives admin
	 * environments that recolour inline data-URI menu-icon fills.
	 */
	private function menu_icon(): string {
		return plugins_url( 'assets/menu-icon.svg', $this->plugin_file );
	}

	/**
	 * Keep the white brand badge at full opacity whether or not CatalogOps is the
	 * current screen. Hooked to admin_head from {@see register_menu()}.
	 */
	public function print_menu_icon_style(): void {
		echo '<style id="catalogops-menu-icon">#adminmenu #toplevel_page_' . esc_attr( self::MENU_SLUG ) . ' .wp-menu-image img{opacity:1;display:block;box-sizing:content-box;padding:7px 0;margin:0 auto}</style>';
	}

	/**
	 * Render the mount point the React app attaches to.
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="catalogops-app"></div></div>';
	}

	/**
	 * Enqueue the built app on our screen only. Hook to admin_enqueue_scripts.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$base   = plugin_dir_path( $this->plugin_file ) . 'assets/dist/';
		$url    = plugin_dir_url( $this->plugin_file ) . 'assets/dist/';
		$script = $base . 'admin.js';
		$asset  = $base . 'admin.asset.php';

		if ( ! file_exists( $script ) || ! file_exists( $asset ) ) {
			// Build not present yet: `npm run build` produces assets/dist/.
			return;
		}

		$meta = require $asset;

		wp_enqueue_script( 'catalogops-admin', $url . 'admin.js', $meta['dependencies'], $meta['version'], true );

		// Load the app's translations (the __()/sprintf calls in index.js). WP reads
		// languages/catalogops-{locale}-{md5}.json, built from the .po via
		// `wp i18n make-json`.
		wp_set_script_translations( 'catalogops-admin', 'catalogops', plugin_dir_path( $this->plugin_file ) . 'languages' );

		// wp-scripts extracts styles imported from the entry to `style-admin.css`.
		// wp-components used to be a dependency for the filter's token fields; the
		// filter now uses our own multiselect, so the whole package — script and
		// stylesheet — is gone from the page.
		if ( file_exists( $base . 'style-admin.css' ) ) {
			// dashicons backs the history row's icon buttons. WordPress loads it on
			// admin screens anyway; naming it means the icons cannot silently turn
			// into empty boxes if that ever stops being true.
			wp_enqueue_style( 'catalogops-admin', $url . 'style-admin.css', array( 'dashicons' ), $meta['version'] );
			wp_style_add_data( 'catalogops-admin', 'rtl', 'replace' );
		}

		wp_localize_script(
			'catalogops-admin',
			'catalogopsConfig',
			array(
				'restUrl'      => esc_url_raw( rest_url( 'catalogops/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				// What the current plan permits, so the app offers paid-only
				// controls (undo, formulas, scheduling) only where they will work
				// and shows an upsell otherwise. The REST layer still enforces the
				// real limits (402); this only decides what to render.
				'capabilities' => array(
					'isPremium'       => $this->license->is_premium(),
					'canUndo'         => $this->license->can_undo(),
					'canSchedule'     => $this->license->can_schedule(),
					'canUseFormulas'  => $this->license->can_use_formulas(),
					// null means unbounded (paid); a number is the free-tier cap.
					'maxObjectsPerOp' => $this->license->is_premium() ? null : $this->license->max_objects_per_op(),
				),
				'cron'         => $this->cron_config(),
				// The shop's own currency symbol, so an amount field can be
				// labelled in the unit it is counted in. A generic label would
				// leave "By (amount)" indistinguishable from the percentage
				// beside it.
				'currency'     => function_exists( 'get_woocommerce_currency_symbol' )
					? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
					: '',
			)
		);
	}

	/**
	 * What the Scheduling panel needs to print working setup commands for *this*
	 * install rather than a generic example.
	 *
	 * A schedule is only as reliable as whatever drives WordPress's background
	 * queue, so the Scheduling panel shows the one-time task or cron entry that
	 * does it — with this site's URL filled in, to be copied rather than adapted.
	 *
	 * Both platforms fetch the same URL with curl, which needs nothing installed:
	 * shop owners have a Task Scheduler dialog or a hosting panel, not a shell and
	 * not WP-CLI, and an instruction they cannot follow is not an instruction.
	 *
	 * @return array<string, mixed>
	 */
	private function cron_config(): array {
		return array(
			'cronUrl'           => site_url( 'wp-cron.php?doing_wp_cron=1' ),
			// Which tab to open first. Only a default — both are always available,
			// since the browser's OS need not be the server's.
			'isWindows'         => 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ),
			// How often the queue must be driven for schedules to fire on time.
			'supervisorMinutes' => (int) ( Scheduler::SCHEDULES_INTERVAL / MINUTE_IN_SECONDS ),
		);
	}
}
