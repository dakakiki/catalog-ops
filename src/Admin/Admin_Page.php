<?php
/**
 * The CatalogOps admin screen that hosts the React app.
 *
 * @package CatalogOps\Admin
 */

namespace CatalogOps\Admin;

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
	 * Build the admin page.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
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
			'dashicons-editor-table',
			56
		);
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
		// Depend on wp-components so the FormTokenField controls in the filter get
		// their WordPress styling (the script dependency is added automatically via
		// the import; the stylesheet is not, so it is named here).
		if ( file_exists( $base . 'style-admin.css' ) ) {
			wp_enqueue_style( 'catalogops-admin', $url . 'style-admin.css', array( 'wp-components' ), $meta['version'] );
			wp_style_add_data( 'catalogops-admin', 'rtl', 'replace' );
		}

		wp_localize_script(
			'catalogops-admin',
			'catalogopsConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( 'catalogops/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
