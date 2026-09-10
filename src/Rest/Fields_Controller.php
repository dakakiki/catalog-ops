<?php
/**
 * REST endpoint for discovering editable fields.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Admin\Wpml_Context;
use CatalogOps\Query\Fields\Filter_Providers;
use WP_REST_Request;
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
	 * Module filter-field registry, or null when no modules can register.
	 *
	 * @var Filter_Providers|null
	 */
	private ?Filter_Providers $providers;

	/**
	 * Reads the language a term list should be offered in.
	 *
	 * @var Wpml_Context
	 */
	private Wpml_Context $wpml;

	/**
	 * Build the controller.
	 *
	 * @param wpdb                  $wpdb      WordPress database handle.
	 * @param Filter_Providers|null $providers Module field registry. Null answers
	 *                                         an empty list, which is what an
	 *                                         installation with no modules is.
	 * @param Wpml_Context|null     $wpml      The language reader; the default is
	 *                                         the real one, which changes nothing
	 *                                         on a site without WPML.
	 */
	public function __construct( wpdb $wpdb, ?Filter_Providers $providers = null, ?Wpml_Context $wpml = null ) {
		$this->wpdb      = $wpdb;
		$this->providers = $providers;
		$this->wpml      = $wpml ?? new Wpml_Context();
	}

	/**
	 * The filterable fields every registered module offers.
	 *
	 * The first production caller {@see Filter_Providers::all_fields()} has ever
	 * had, and the reason M7's clause seam is not invisible: a module could ship a
	 * perfectly compiled field and no user could reach it, because the filter's
	 * control list is written by hand in the React bundle.
	 *
	 * **Unavailable fields are listed, not omitted.** A field whose module the
	 * licence does not cover comes back with `available: false` so the UI can show
	 * it locked. Dropping it would be worse than it looks: a saved filter or a
	 * schedule that already names the field would then open against a control list
	 * that does not contain it, and a filter the user cannot see is a filter they
	 * cannot correct — while the engine goes on refusing it. Locked-and-explained
	 * is the only honest shape.
	 *
	 * The core fields are deliberately absent. They are hardcoded in the client and
	 * stay that way for now; serving them too would mean rewriting eight tested
	 * controls in the same change as introducing the mechanism, and the spec's
	 * de-risking advice is to append a module section below them instead.
	 */
	public function filterable(): WP_REST_Response {
		if ( null === $this->providers ) {
			return new WP_REST_Response( array( 'fields' => array() ) );
		}

		$fields = array();

		foreach ( $this->providers->all_fields() as $entry ) {
			$field = $entry['field'];

			$fields[] = array(
				'key'           => $field->key,
				'label'         => $field->label,
				'control'       => $field->control->value,
				// Sent as the persisted tokens, not as objects: the client posts
				// these straight back in filter_json, and a shape that has to be
				// translated in both directions is a shape that can be translated
				// wrongly in one of them.
				'operators'     => array_map( static fn( $operator ): string => $operator->value, $field->operators ),
				'scopes'        => array_map( static fn( $scope ): string => $scope->value, $field->scopes ),
				'options_route' => $field->options_route,
				// The format the value is STORED in, so a date control can send what
				// the column holds rather than what a browser's date input produces.
				'value_format'  => $field->value_format,
				'column_label'  => $field->column_label,
				'module'        => $entry['module'],
				// The heading the client groups this field under. Served rather than
				// derived, so the admin bundle never learns a module's name.
				'module_label'  => $entry['label'],
				'available'     => $entry['available'],
			);
		}

		return new WP_REST_Response( array( 'fields' => $fields ) );
	}

	/**
	 * Register the routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/filterable',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'filterable' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

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
				'args'                => Language::args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'categories' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => Language::args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/attributes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'attributes' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => Language::args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/fields/tags',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tags' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => Language::args(),
			)
		);
	}

	/**
	 * A taxonomy's terms, in the language the request is being made in.
	 *
	 * Every one of these four lists feeds a control whose selection becomes term
	 * ids in filter_json, so all four have to answer the same way — one method
	 * rather than the same four lines written four times, which is how a rule ends
	 * up applying to three of them.
	 *
	 * **A translated term is a different row with a different id.** On the
	 * development catalogue Accessories is term 18 in English and 73 in Serbian,
	 * with the same name; `product_cat` holds 26 terms for 13 categories. So a
	 * picker filled in one language hands the engine ids that no product in the
	 * other language carries, and the run comes back empty — measured, before this:
	 * `category IN (18, 16, 26)` is 4,596 products unconstrained, 4,594 in English
	 * and **0** in Serbian. Nothing about that looks like a bug; it looks like a
	 * filter that found nothing.
	 *
	 * A term with no translation in the requested language is left out. The list a
	 * language gets is that language's list: a term nobody has translated labels
	 * nothing here and could match nothing here, and translating it is the shop's
	 * business rather than something for this control to disguise.
	 *
	 * Without WPML, or with no language on the request, this is `get_terms()` and
	 * nothing else — the same call these routes have always made.
	 *
	 * @param string          $taxonomy The taxonomy to list.
	 * @param WP_REST_Request $request  The request, for its language.
	 * @return list<\WP_Term>
	 */
	private function terms_for( string $taxonomy, WP_REST_Request $request ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				// A category with no products is still one a user may want to filter
				// by, and the honest answer to that filter is nothing rather than a
				// missing entry they cannot choose.
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$language = Language::from_request( $request );

		if ( null === $language ) {
			return $terms;
		}

		$localized = array();

		foreach ( $terms as $term ) {
			$id = $this->wpml->term_in_language( (int) $term->term_id, $taxonomy, $language );

			if ( null === $id ) {
				continue;
			}

			// Keyed by the translated id, because the source list may already hold
			// both languages' terms — WPML filters `get_terms()` by the current
			// language, and a REST request's current language is not the one being
			// asked about here. Both rows then map to the same translation, and the
			// user would be offered the same category twice.
			if ( isset( $localized[ $id ] ) ) {
				continue;
			}

			$translated = get_term( $id, $taxonomy );

			if ( $translated instanceof \WP_Term ) {
				$localized[ $id ] = $translated;
			}
		}

		// Ordered by name again: the translations were fetched one at a time, and
		// their names need not sort the way the originals did.
		$localized = array_values( $localized );

		usort(
			$localized,
			static fn( \WP_Term $a, \WP_Term $b ): int => strcasecmp( $a->name, $b->name )
		);

		return $localized;
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
	 * WooCommerce's brands, for the filter's brand picker.
	 *
	 * `product_brand` is WooCommerce core, registered by `WC_Brands` since 9.6.
	 * This endpoint used to serve the distinct values of a meta key instead —
	 * `_catalogops_brand`, invented by the seed command for its fake catalogue and
	 * then adopted by the UI because it was the brand data the UI could see. The
	 * consequence was that on a shop using WooCommerce's own brands, which is
	 * nearly all of them, the brand filter listed nothing at all unless the site
	 * owner wrote a PHP filter. Asking a shop owner to write code for the most
	 * ordinary case is not a configuration option, it is a defect.
	 *
	 * Terms are returned with `hide_empty` false: a brand with no products is
	 * still one a user may want to filter by, and the honest answer to that filter
	 * is nothing rather than a missing entry they cannot choose.
	 *
	 * @param WP_REST_Request $request The request, for the language its list
	 *                                 should be offered in.
	 * @return WP_REST_Response
	 */
	public function brands( WP_REST_Request $request ): WP_REST_Response {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			// WooCommerce older than 9.6, or a shop whose brands come from a plugin
			// registering something else. An empty list leaves the control empty
			// rather than filled with things that cannot be matched.
			return new WP_REST_Response( array( 'brands' => array() ) );
		}

		$terms = $this->terms_for( 'product_brand', $request );

		$brands = array();

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$brands[] = array(
					'id'   => (int) $term->term_id,
					// Decoded, because a term name is stored HTML-encoded and a
					// brand called "Marks & Spencer" would otherwise read as
					// "Marks &amp;amp; Spencer" — the defect the category list was
					// fixed for in 0.7.2.
					'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
				);
			}
		}

		return new WP_REST_Response( array( 'brands' => $brands ) );
	}

	/**
	 * The product categories, for the filter's category picker.
	 *
	 * @param WP_REST_Request $request The request, for the language its list
	 *                                 should be offered in.
	 */
	public function categories( WP_REST_Request $request ): WP_REST_Response {
		$terms = $this->terms_for( 'product_cat', $request );

		$categories = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'    => (int) $term->term_id,
					'name'  => $this->decode( $term->name ),
					'count' => (int) $term->count,
				);
			}
		}

		return new WP_REST_Response( array( 'categories' => $categories ) );
	}

	/**
	 * The product tags, for the filter's tag picker. Tags are a flat taxonomy a
	 * store uses for cross-cutting groupings ("sale", "collection", "clearance"),
	 * which makes them a natural bulk-edit target alongside categories.
	 *
	 * @param WP_REST_Request $request The request, for the language its list
	 *                                 should be offered in.
	 */
	public function tags( WP_REST_Request $request ): WP_REST_Response {
		$terms = $this->terms_for( 'product_tag', $request );

		$tags = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$tags[] = array(
					'id'    => (int) $term->term_id,
					'name'  => $this->decode( $term->name ),
					'count' => (int) $term->count,
				);
			}
		}

		return new WP_REST_Response( array( 'tags' => $tags ) );
	}

	/**
	 * The global product attributes and their terms, for the filter's
	 * attribute/value dropdowns (the M5 reframe: filtering variations by
	 * size/colour belongs in the filter, CONTEXT §3). Each attribute maps to the
	 * `attribute:<taxonomy>` filter field the query engine understands; a
	 * variation matches on its own chosen value, a product on having the term.
	 *
	 * @param WP_REST_Request $request The request, for the language its lists
	 *                                 should be offered in.
	 */
	public function attributes( WP_REST_Request $request ): WP_REST_Response {
		$attributes = array();

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return new WP_REST_Response( array( 'attributes' => $attributes ) );
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = $this->terms_for( $taxonomy, $request );

			$values = array();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$values[] = array(
						'id'   => (int) $term->term_id,
						'slug' => $term->slug,
						'name' => $this->decode( $term->name ),
					);
				}
			}

			if ( array() === $values ) {
				continue;
			}

			$attributes[] = array(
				'field'    => 'attribute:' . $taxonomy,
				'taxonomy' => $taxonomy,
				'label'    => $this->decode( '' !== $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name ),
				'terms'    => $values,
			);
		}

		return new WP_REST_Response( array( 'attributes' => $attributes ) );
	}

	/**
	 * Decode HTML entities in a taxonomy/attribute label. WordPress stores term
	 * names with entities encoded ("Home &amp; Kitchen"); the admin app renders
	 * the value as React text (escaped again on output), so it needs the plain
	 * form or the entity shows literally. Safe for these fields because they are
	 * display-only — the filter matches categories and attributes by id, not name.
	 *
	 * @param string $text Possibly entity-encoded text.
	 */
	private function decode( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
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
