<?php
/**
 * Integration tests for the query engine's correctness.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\Query
 */

namespace CatalogOps\Tests\Integration\Query;

use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Attribute;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Query_Engine
 * @covers \CatalogOps\Query\Filter
 * @covers \CatalogOps\Query\Condition
 */
final class QueryEngineTest extends WP_UnitTestCase {

	private Query_Engine $engine;
	private int $cat_a;
	private int $cat_b;
	private string $color_tax;
	private int $red;
	private int $blue;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$this->reset_attribute_state();

		global $wpdb;
		$this->engine = new Query_Engine( $wpdb );

		$this->cat_a = $this->ensure_term( 'QE Cat A', 'product_cat' );
		$this->cat_b = $this->ensure_term( 'QE Cat B', 'product_cat' );

		$this->color_tax = $this->ensure_color_attribute();
		$this->red       = $this->ensure_term( 'Red', $this->color_tax );
		$this->blue      = $this->ensure_term( 'Blue', $this->color_tax );
	}

	public function tear_down(): void {
		$this->reset_attribute_state();
		parent::tear_down();
	}

	public function test_empty_filter_matches_every_published_product(): void {
		$a = $this->make_product( array( 'price' => 10 ) );
		$b = $this->make_product( array( 'price' => 20 ) );

		$this->assertEqualsCanonicalizing( array( $a, $b ), $this->engine->resolve( new Filter() ) );
		$this->assertSame( 2, $this->engine->count( new Filter() ) );
	}

	public function test_price_greater_than(): void {
		$cheap = $this->make_product( array( 'price' => 10 ) );
		$mid   = $this->make_product( array( 'price' => 50 ) );
		$dear  = $this->make_product( array( 'price' => 100 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 40 ) ) )
		);

		$this->assertEqualsCanonicalizing( array( $mid, $dear ), $ids );
		$this->assertNotContains( $cheap, $ids );
	}

	public function test_price_between(): void {
		$this->make_product( array( 'price' => 10 ) );
		$in = $this->make_product( array( 'price' => 50 ) );
		$this->make_product( array( 'price' => 100 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'price', Operator::BETWEEN, array( 40, 60 ) ) ) )
		);

		$this->assertSame( array( $in ), $ids );
	}

	public function test_stock_status_in(): void {
		$in1 = $this->make_product( array( 'stock_status' => 'instock' ) );
		$out = $this->make_product( array( 'stock_status' => 'outofstock' ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'stock_status', Operator::IN, array( 'outofstock' ) ) ) )
		);

		$this->assertSame( array( $out ), $ids );
		$this->assertNotContains( $in1, $ids );
	}

	public function test_stock_quantity_less_than(): void {
		$low  = $this->make_product( array( 'stock_qty' => 3 ) );
		$high = $this->make_product( array( 'stock_qty' => 200 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'stock_quantity', Operator::LESS_THAN, 10 ) ) )
		);

		$this->assertSame( array( $low ), $ids );
		$this->assertNotContains( $high, $ids );
	}

	public function test_category_membership(): void {
		$in_a = $this->make_product( array( 'category' => $this->cat_a ) );
		$in_b = $this->make_product( array( 'category' => $this->cat_b ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::IN, array( $this->cat_a ) ) ) )
		);

		$this->assertSame( array( $in_a ), $ids );
		$this->assertNotContains( $in_b, $ids );
	}

	public function test_attribute_term(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'attribute:' . $this->color_tax, Operator::IN, array( $this->red ) ) ) )
		);

		$this->assertSame( array( $red_product ), $ids );
		$this->assertNotContains( $blue_product, $ids );
	}

	public function test_attribute_in_multiple_terms(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );
		$plain        = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter(
				array(
					new Condition( 'attribute:' . $this->color_tax, Operator::IN, array( $this->red, $this->blue ) ),
				)
			)
		);

		$this->assertEqualsCanonicalizing( array( $red_product, $blue_product ), $ids );
		$this->assertNotContains( $plain, $ids );
	}

	public function test_attribute_exists_matches_any_value(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );
		$plain        = $this->make_product( array( 'price' => 10 ) );

		// "Filter by Colour, any value": every product that has a colour term.
		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'attribute:' . $this->color_tax, Operator::EXISTS ) ) )
		);

		$this->assertEqualsCanonicalizing( array( $red_product, $blue_product ), $ids );
		$this->assertNotContains( $plain, $ids );
	}

	public function test_category_in_multiple_terms(): void {
		$in_a    = $this->make_product( array( 'category' => $this->cat_a ) );
		$in_b    = $this->make_product( array( 'category' => $this->cat_b ) );
		$neither = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'category', Operator::IN, array( $this->cat_a, $this->cat_b ) ) )
			)
		);

		$this->assertEqualsCanonicalizing( array( $in_a, $in_b ), $ids );
		$this->assertNotContains( $neither, $ids );
	}

	public function test_meta_in_multiple_values(): void {
		$acme   = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$globex = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );
		$hooli  = $this->make_product( array( 'meta' => array( '_brand' => 'Hooli' ) ) );

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'meta:_brand', Operator::IN, array( 'Acme', 'Globex' ) ) )
			)
		);

		$this->assertEqualsCanonicalizing( array( $acme, $globex ), $ids );
		$this->assertNotContains( $hooli, $ids );
	}

	public function test_sku_contains_search(): void {
		$alpha = $this->make_product( array( 'sku' => 'COPS-ALPHA-1' ) );
		$beta  = $this->make_product( array( 'sku' => 'COPS-BETA-2' ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'sku', Operator::CONTAINS, 'ALPHA' ) ) )
		);

		$this->assertSame( array( $alpha ), $ids );
		$this->assertNotContains( $beta, $ids );
	}

	public function test_meta_equals(): void {
		$acme  = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$other = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::EQUALS, 'Acme' ) ) )
		);

		$this->assertSame( array( $acme ), $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function test_meta_numeric_greater_than(): void {
		$this->make_product( array( 'meta' => array( '_cost' => '5.00' ) ) );
		$expensive = $this->make_product( array( 'meta' => array( '_cost' => '42.50' ) ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_cost', Operator::GREATER_THAN, 10 ) ) )
		);

		$this->assertSame( array( $expensive ), $ids );
	}

	public function test_multiple_conditions_are_anded(): void {
		$match     = $this->make_product( array( 'price' => 50, 'category' => $this->cat_a ) );
		$wrong_cat = $this->make_product( array( 'price' => 50, 'category' => $this->cat_b ) );
		$too_cheap = $this->make_product( array( 'price' => 5, 'category' => $this->cat_a ) );

		$filter = new Filter(
			array(
				new Condition( 'price', Operator::GREATER_OR_EQUAL, 20 ),
				new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
			)
		);

		$ids = $this->engine->resolve( $filter );

		$this->assertSame( array( $match ), $ids );
		$this->assertNotContains( $wrong_cat, $ids );
		$this->assertNotContains( $too_cheap, $ids );
	}

	/**
	 * Create a simple product with controlled attributes.
	 *
	 * @param array<string, mixed> $args price, stock_status, stock_qty, category, color, meta.
	 * @return int Product ID.
	 */
	private function make_product( array $args ): int {
		$status = (string) ( $args['stock_status'] ?? 'instock' );
		// Keep quantity and status consistent: WooCommerce forces "instock" on
		// save when managing stock with a positive quantity.
		$quantity = (int) ( $args['stock_qty'] ?? ( 'outofstock' === $status ? 0 : 5 ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'QE Product' );
		$product->set_regular_price( (string) ( $args['price'] ?? 10 ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $status );

		if ( ! empty( $args['sku'] ) ) {
			$product->set_sku( (string) $args['sku'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$product->set_category_ids( array_map( 'intval', (array) $args['category'] ) );
		}

		if ( ! empty( $args['color'] ) ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) );
			$attribute->set_name( $this->color_tax );
			$attribute->set_options( array_map( 'intval', (array) $args['color'] ) );
			$attribute->set_visible( true );
			$product->set_attributes( array( $attribute ) );
		}

		foreach ( (array) ( $args['meta'] ?? array() ) as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	private function ensure_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy );

		return (int) $created['term_id'];
	}

	private function ensure_color_attribute(): string {
		$taxonomy = wc_attribute_taxonomy_name( 'color' );

		if ( ! wc_attribute_taxonomy_id_by_name( 'color' ) ) {
			wc_create_attribute(
				array(
					'name' => 'Color',
					'slug' => 'color',
					'type' => 'select',
				)
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'query_var' => true ) );
		}

		return $taxonomy;
	}

	private function reset_attribute_state(): void {
		if ( taxonomy_exists( 'pa_color' ) ) {
			unregister_taxonomy( 'pa_color' );
		}

		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );
	}
}
