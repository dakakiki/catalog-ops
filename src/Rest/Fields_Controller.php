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
	 * Internal key prefixes to hide. `attribute_` covers the per-variation
	 * attribute meta (e.g. `attribute_pa_size`) — plumbing that records which
	 * value a variation is, never something to bulk-edit as a custom field.
	 */
	private const BLOCKED_PREFIXES = array( '_edit', '_wp_', '_oembed', '_pingme', '_encloseme', '_menu_item', '_wc_', '_woocommerce_', 'attribute_' );

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

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/brands',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'brands' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'categories' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/attributes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'attributes' ),
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
	 * The brands present in the catalog, for the filter's brand dropdown, plus the
	 * filter field they map to. Which meta key holds the brand is catalog-specific
	 * (the seed uses `_catalogops_brand`); it is overridable so a real store's
	 * brand source can be pointed at without touching the UI. Returning the field
	 * with the values keeps the client from having to know the key.
	 *
	 * @return WP_REST_Response
	 */
	public function brands(): WP_REST_Response {
		/**
		 * Filters the meta key that holds a product's brand.
		 *
		 * @param string $key Brand meta key.
		 */
		$key      = (string) apply_filters( 'catalogops_brand_meta_key', '_catalogops_brand' );
		$postmeta = $this->wpdb->postmeta;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value ASC LIMIT %d",
				$key,
				self::SCAN_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response(
			array(
				'field'  => 'meta:' . $key,
				'brands' => array_map( 'strval', $values ),
			)
		);
	}

	/**
	 * The product categories, for the filter's category picker.
	 */
	public function categories(): WP_REST_Response {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$categories = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'count' => (int) $term->count,
				);
			}
		}

		return new WP_REST_Response( array( 'categories' => $categories ) );
	}

	/**
	 * The global product attributes and their terms, for the filter's
	 * attribute/value dropdowns (the M5 reframe: filtering variations by
	 * size/colour belongs in the filter, CONTEXT §3). Each attribute maps to the
	 * `attribute:<taxonomy>` filter field the query engine understands; a
	 * variation matches on its own chosen value, a product on having the term.
	 */
	public function attributes(): WP_REST_Response {
		$attributes = array();

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return new WP_REST_Response( array( 'attributes' => $attributes ) );
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);

			$values = array();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$values[] = array(
						'slug' => $term->slug,
						'name' => $term->name,
					);
				}
			}

			if ( array() === $values ) {
				continue;
			}

			$attributes[] = array(
				'field'    => 'attribute:' . $taxonomy,
				'taxonomy' => $taxonomy,
				'label'    => '' !== $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name,
				'terms'    => $values,
			);
		}

		return new WP_REST_Response( array( 'attributes' => $attributes ) );
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
