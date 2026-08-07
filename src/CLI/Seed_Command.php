<?php
/**
 * WP-CLI: generate a realistic test catalog.
 *
 * @package CatalogOps\CLI
 */

namespace CatalogOps\CLI;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_CLI;

/**
 * `wp catalogops seed` — populate the store with WooCommerce products whose
 * price, stock, category, attribute, and meta values vary enough for the M1
 * query engine to be measured against something realistic.
 *
 * Products are written through WooCommerce CRUD (not raw SQL) so the meta
 * lookup table, term relationships, and category counts are all populated the
 * way a real catalog would be. That is slower — expect roughly 40–80 products
 * per second — but the seed runs once and correctness is the point.
 *
 * Every seeded object carries a `_catalogops_seeded` meta marker so `--reset`
 * can find and remove exactly what this command created.
 */
final class Seed_Command {

	/**
	 * Product categories to spread products across.
	 */
	private const CATEGORIES = array(
		'Apparel',
		'Footwear',
		'Accessories',
		'Home & Kitchen',
		'Electronics',
		'Toys & Games',
		'Sports',
		'Beauty',
		'Garden',
		'Office',
		'Automotive',
		'Pet Supplies',
	);

	/**
	 * Global "Color" attribute terms.
	 */
	private const COLORS = array( 'Red', 'Blue', 'Green', 'Black', 'White', 'Yellow' );

	/**
	 * Global "Size" attribute terms — also the variation axis.
	 */
	private const SIZES = array( 'S', 'M', 'L', 'XL' );

	/**
	 * Brand values written to the `_catalogops_brand` meta field.
	 */
	private const BRANDS = array( 'Acme', 'Globex', 'Initech', 'Umbrella', 'Soylent', 'Hooli', 'Stark', 'Wayne' );

	/**
	 * Generate a test catalog of WooCommerce products.
	 *
	 * ## OPTIONS
	 *
	 * [--products=<number>]
	 * : How many parent products to create. Presets in mind: 500, 5000, 50000.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--variable=<percent>]
	 * : Percentage of products that are variable, each with one variation per
	 * size. Variations are extra posts on top of the product count.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--batch=<number>]
	 * : Flush caches and the query log every N products, to bound memory.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--reset]
	 * : Delete previously seeded products (and their variations) first.
	 *
	 * ## EXAMPLES
	 *
	 *     # The M0 target.
	 *     wp catalogops seed --products=50000
	 *
	 *     # A quick set with a quarter variable products, starting clean.
	 *     wp catalogops seed --products=500 --variable=25 --reset
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			WP_CLI::error( 'WooCommerce is not active; nothing to seed.' );
		}

		$count    = max( 0, (int) ( $assoc_args['products'] ?? 500 ) );
		$variable = min( 100, max( 0, (int) ( $assoc_args['variable'] ?? 0 ) ) );
		$batch    = max( 1, (int) ( $assoc_args['batch'] ?? 200 ) );
		$do_reset = isset( $assoc_args['reset'] );

		if ( $do_reset ) {
			$removed = $this->reset_seeded();
			WP_CLI::log( sprintf( 'Removed %d previously seeded object(s).', $removed ) );
		}

		if ( 0 === $count ) {
			WP_CLI::success( 'Nothing to create (--products=0).' );
			return;
		}

		// Bulk-write hygiene: defer the expensive per-write bookkeeping.
		wc_set_time_limit( 0 );
		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$categories = $this->ensure_terms( 'product_cat', self::CATEGORIES );
		$color_tax  = $this->ensure_global_attribute( 'Color', 'color' );
		$colors     = $this->ensure_terms( $color_tax, self::COLORS );
		$size_tax   = $this->ensure_global_attribute( 'Size', 'size' );
		$sizes      = $this->ensure_terms( $size_tax, self::SIZES );

		$progress        = WP_CLI\Utils\make_progress_bar( sprintf( 'Seeding %d products', $count ), $count );
		$variations_made = 0;
		$failures        = 0;

		for ( $i = 1; $i <= $count; $i++ ) {
			$is_variable = wp_rand( 1, 100 ) <= $variable;

			try {
				if ( $is_variable ) {
					$variations_made += $this->create_variable_product( $i, $categories, $size_tax, $sizes );
				} else {
					$this->create_simple_product( $i, $categories, $color_tax, $colors );
				}
			} catch ( \Throwable $e ) {
				++$failures;
				WP_CLI::warning( sprintf( 'Product %d failed: %s', $i, $e->getMessage() ) );
			}

			if ( 0 === $i % $batch ) {
				$this->free_memory();
			}

			$progress->tick();
		}

		$progress->finish();

		// Restore normal behaviour and settle the deferred bookkeeping.
		wp_suspend_cache_invalidation( false );
		wp_defer_term_counting( false );
		$all_terms = $categories + $colors + $sizes;
		wp_update_term_count_now( array_values( $all_terms ), 'product_cat' );

		WP_CLI::success(
			sprintf(
				'Created %d products (%d variations)%s.',
				$count - $failures,
				$variations_made,
				$failures > 0 ? sprintf( ', %d failed', $failures ) : ''
			)
		);
	}

	/**
	 * Create one simple product with varied price, stock, category, colour, and
	 * meta.
	 *
	 * @param int                $i          Sequence number.
	 * @param array<string, int> $categories name => term_id.
	 * @param string             $color_tax  Colour attribute taxonomy.
	 * @param array<string, int> $colors     name => term_id.
	 */
	private function create_simple_product( int $i, array $categories, string $color_tax, array $colors ): void {
		$price = wp_rand( 499, 49999 ) / 100;

		$product = new WC_Product_Simple();
		$product->set_name( sprintf( 'CatalogOps Product %d', $i ) );
		$product->set_sku( sprintf( 'COPS-%d', $i ) );
		$product->set_regular_price( wc_format_decimal( $price, 2 ) );

		if ( wp_rand( 1, 100 ) <= 20 ) {
			$product->set_sale_price( wc_format_decimal( $price * 0.85, 2 ) );
		}

		$in_stock = wp_rand( 1, 100 ) <= 85;
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $in_stock ? wp_rand( 1, 300 ) : 0 );
		$product->set_stock_status( $in_stock ? 'instock' : 'outofstock' );

		$product->set_category_ids( array( $this->pick( $categories ) ) );
		$product->set_attributes( array( $this->build_attribute( $color_tax, array( $this->pick( $colors ) ), false ) ) );

		$this->tag_seeded( $product, $price );
		$product->save();
	}

	/**
	 * Create one variable product with a variation per size.
	 *
	 * @param int                $i          Sequence number.
	 * @param array<string, int> $categories name => term_id.
	 * @param string             $size_tax   Size attribute taxonomy.
	 * @param array<string, int> $sizes      name => term_id.
	 * @return int Number of variations created.
	 */
	private function create_variable_product( int $i, array $categories, string $size_tax, array $sizes ): int {
		$product = new WC_Product_Variable();
		$product->set_name( sprintf( 'CatalogOps Variable %d', $i ) );
		$product->set_sku( sprintf( 'COPS-V-%d', $i ) );
		$product->set_category_ids( array( $this->pick( $categories ) ) );
		$product->set_attributes( array( $this->build_attribute( $size_tax, array_values( $sizes ), true ) ) );

		$this->tag_seeded( $product, 0.0 );
		$parent_id = $product->save();

		$made = 0;
		foreach ( $sizes as $name => $term_id ) {
			$price     = wp_rand( 499, 49999 ) / 100;
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $size_tax => sanitize_title( $name ) ) );
			$variation->set_regular_price( wc_format_decimal( $price, 2 ) );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( wp_rand( 0, 200 ) );
			$this->tag_seeded( $variation, $price );
			$variation->save();
			++$made;
		}

		return $made;
	}

	/**
	 * Build a global-attribute object for a product.
	 *
	 * @param string          $taxonomy    Attribute taxonomy, e.g. `pa_color`.
	 * @param array<int, int> $term_ids    Selected term ids.
	 * @param bool            $is_variation Whether the attribute drives variations.
	 */
	private function build_attribute( string $taxonomy, array $term_ids, bool $is_variation ): WC_Product_Attribute {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( $term_ids );
		$attribute->set_visible( true );
		$attribute->set_variation( $is_variation );

		return $attribute;
	}

	/**
	 * Attach the seed marker and test meta fields shared by all objects.
	 *
	 * @param WC_Product_Simple|WC_Product_Variable|WC_Product_Variation $product Product or variation.
	 * @param float                                                      $price   Base price, for a derived cost.
	 */
	private function tag_seeded( $product, float $price ): void {
		$product->update_meta_data( '_catalogops_seeded', CATALOGOPS_VERSION );
		$product->update_meta_data( '_catalogops_brand', self::BRANDS[ array_rand( self::BRANDS ) ] );

		if ( $price > 0 ) {
			$cost = $price / ( 1 + wp_rand( 20, 60 ) / 100 );
			$product->update_meta_data( '_catalogops_cost', wc_format_decimal( $cost, 2 ) );
		}
	}

	/**
	 * Ensure a global product attribute exists and its taxonomy is registered
	 * for use within this same request.
	 *
	 * @param string $label Human label, e.g. "Color".
	 * @param string $slug  Attribute slug, e.g. "color".
	 * @return string The attribute taxonomy name, e.g. `pa_color`.
	 */
	private function ensure_global_attribute( string $label, string $slug ): string {
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
			$result = wc_create_attribute(
				array(
					'name'         => $label,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( sprintf( 'Could not create the %s attribute: %s', $label, $result->get_error_message() ) );
			}
		}

		// A freshly created attribute taxonomy is not registered yet this request.
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}

		return $taxonomy;
	}

	/**
	 * Ensure each named term exists in a taxonomy; return name => term_id.
	 *
	 * @param string             $taxonomy Taxonomy name.
	 * @param array<int, string> $names    Term names.
	 * @return array<string, int>
	 */
	private function ensure_terms( string $taxonomy, array $names ): array {
		$ids = array();

		foreach ( $names as $name ) {
			$existing = get_term_by( 'name', $name, $taxonomy );

			if ( $existing ) {
				$ids[ $name ] = (int) $existing->term_id;
				continue;
			}

			$created = wp_insert_term( $name, $taxonomy );

			if ( is_wp_error( $created ) ) {
				WP_CLI::warning( sprintf( 'Term "%s" in %s: %s', $name, $taxonomy, $created->get_error_message() ) );
				continue;
			}

			$ids[ $name ] = (int) $created['term_id'];
		}

		return $ids;
	}

	/**
	 * Delete every object carrying the seed marker, in batches.
	 *
	 * @return int Number of objects removed.
	 */
	private function reset_seeded(): int {
		$removed = 0;

		do {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded batch; loops until empty.
					'fields'         => 'ids',
					'meta_key'       => '_catalogops_seeded', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Seed marker lookup during teardown only.
					'no_found_rows'  => true,
				)
			);

			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
				++$removed;
			}

			$this->free_memory();
		} while ( ! empty( $ids ) );

		return $removed;
	}

	/**
	 * Return a random value from a name => id map.
	 *
	 * @param array<string, int> $map Values to pick from.
	 */
	private function pick( array $map ): int {
		return (int) $map[ array_rand( $map ) ];
	}

	/**
	 * Release memory accumulated across a batch: WordPress' object cache and,
	 * because wp-config enables SAVEQUERIES here, the in-memory query log.
	 */
	private function free_memory(): void {
		global $wpdb;

		$wpdb->queries = array();
		wp_cache_flush();
	}
}
