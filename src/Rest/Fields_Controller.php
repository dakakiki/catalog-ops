<?php
/**
 * REST endpoint for discovering editable fields.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use WP_REST_Response;
use WP_REST_Server;
use wpdb;

/**
 * Helps the bulk editor answer "which custom field do I type?" by listing the
 * meta keys actually present in the catalog, so the UI can offer them as
 * suggestions instead of asking the user to remember an internal key. Core
 * WooCommerce/WordPress bookkeeping keys are filtered out — they either have
 * dedicated fields already or are never something a user bulk-edits.
 */
final class Fields_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * How many distinct keys to scan before filtering.
	 */
	private const SCAN_LIMIT = 500;

	/**
	 * Exact internal keys to hide (they have dedicated fields or are bookkeeping).
	 */
	private const BLOCKED_KEYS = array(
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_stock',
		'_stock_status',
		'_manage_stock',
		'_backorders',
		'_sku',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_tax_status',
		'_tax_class',
		'_virtual',
		'_downloadable',
		'_sold_individually',
		'_featured',
		'_visibility',
		'_product_version',
		'_thumbnail_id',
		'_product_image_gallery',
		'total_sales',
		'_wp_page_template',
		'_wp_old_slug',
		'_wp_old_date',
	);

	/**
	 * Internal key prefixes to hide.
	 */
	private const BLOCKED_PREFIXES = array( '_edit', '_wp_', '_oembed', '_pingme', '_encloseme', '_menu_item', '_wc_', '_woocommerce_' );

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Build the controller.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Register the routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/meta-keys',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'meta_keys' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Whether the current user may discover fields.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * The catalog's custom meta keys, filtered and sorted for suggestions.
	 */
	public function meta_keys(): WP_REST_Response {
		$postmeta = $this->wpdb->postmeta;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT meta_key FROM {$postmeta} WHERE meta_key <> '' ORDER BY meta_key ASC LIMIT %d",
				self::SCAN_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$filtered = array_values( array_filter( $keys, array( $this, 'is_user_key' ) ) );

		return new WP_REST_Response( array( 'keys' => $filtered ) );
	}

	/**
	 * Whether a meta key is a user-facing custom field rather than internal
	 * bookkeeping.
	 *
	 * @param string $key Meta key.
	 */
	private function is_user_key( string $key ): bool {
		if ( in_array( $key, self::BLOCKED_KEYS, true ) ) {
			return false;
		}

		foreach ( self::BLOCKED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return false;
			}
		}

		return true;
	}
}
