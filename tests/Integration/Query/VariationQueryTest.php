<?php
/**
 * Integration tests for querying variations as a first-class object (M4).
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
use CatalogOps\Query\Query_Scope;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Query_Engine
 * @covers \CatalogOps\Query\Filter
 * @covers \CatalogOps\Query\Query_Scope
 */
final class VariationQueryTest extends WP_UnitTestCase {

	private Query_Engine $engine;
	private string $size_tax;
	private int $small;
	private int $medium;
	private int $large;
	private int $cat_a;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$this->reset_attribute_state();

		global $wpdb;
		$this->engine = new Query_Engine( $wpdb );

		$this->cat_a    = $this->ensure_term( 'VQ Cat A', 'product_cat' );
		$this->size_tax = $this->ensure_size_attribute();
		$this->small    = $this->ensure_term( 'Small', $this->size_tax );
		$this->medium   = $this->ensure_term( 'Medium', $this->size_tax );
		$this->large    = $this->ensure_term( 'Large', $this->size_tax );
	}

	public function tear_down(): void {
		$this->reset_attribute_state();
		parent::tear_down();
	}

	public function test_variations_get_their_own_lookup_row(): void {
		list( , $variations ) = $this->make_variable_product();

		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		foreach ( $variations as $vid ) {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT product_id FROM {$lookup} WHERE product_id = %d", $vid ) );
			$this->assertSame( $vid, (int) $row, 'variation should have a lookup row' );
		}
	}

	public function test_variation_scope_resolves_variations_not_the_parent(): void {
		list( $parent, $variations ) = $this->make_variable_product(
			array( 'Small' => 10, 'Medium' => 50, 'Large' => 90 )
		);

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'price', Operator::GREATER_THAN, 40 ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			)
		);

		$this->assertEqualsCanonicalizing( array( $variations['Medium'], $variations['Large'] ), $ids );
		$this->assertNotContains( $parent, $ids );
		$this->assertNotContains( $variations['Small'], $ids );
	}

	public function test_product_scope_resolves_the_parent_not_its_variations(): void {
		list( $parent, $variations ) = $this->make_variable_product();

		$ids = $this->engine->resolve( new Filter() ); // default product scope

		$this->assertContains( $parent, $ids );
		foreach ( $variations as $vid ) {
			$this->assertNotContains( $vid, $ids );
		}
	}

	public function test_variation_inherits_category_from_the_parent(): void {
		list( , $variations ) = $this->make_variable_product(
			array( 'Small' => 10, 'Medium' => 50 ),
			$this->cat_a
		);

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'category', Operator::IN, array( $this->cat_a ) ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			)
		);

		$this->assertEqualsCanonicalizing( array_values( $variations ), $ids );
	}

	public function test_variation_attribute_matches_only_the_chosen_value(): void {
		list( , $variations ) = $this->make_variable_product(
			array( 'Small' => 10, 'Medium' => 50, 'Large' => 90 )
		);

		// Filtering variations by size = Large returns only the Large variation,
		// not every variation of a parent that offers a Large.
		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'attribute:' . $this->size_tax, Operator::IN, array( $this->large ) ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			)
		);

		$this->assertSame( array( $variations['Large'] ), $ids );
	}

	public function test_variation_meta_filters_on_the_variations_own_meta(): void {
		list( , $variations ) = $this->make_variable_product( array( 'Small' => 10, 'Medium' => 50 ) );

		// The Medium variation carries a higher cost meta.
		$m = wc_get_product( $variations['Medium'] );
		$m->update_meta_data( '_vq_cost', '30.00' );
		$m->save();
		$s = wc_get_product( $variations['Small'] );
		$s->update_meta_data( '_vq_cost', '4.00' );
		$s->save();

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'meta:_vq_cost', Operator::GREATER_THAN, 10 ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			)
		);

		$this->assertSame( array( $variations['Medium'] ), $ids );
	}

	/**
	 * Create a variable product with one variation per named size.
	 *
	 * @param array<string, float> $sizes    size name => price.
	 * @param int|null             $category Optional category term id for the parent.
	 * @return array{0: int, 1: array<string, int>} Parent id and size => variation id.
	 */
	private function make_variable_product( array $sizes = array( 'Small' => 10, 'Medium' => 50, 'Large' => 90 ), ?int $category = null ): array {
		$term_id_for = array(
			'Small'  => $this->small,
			'Medium' => $this->medium,
			'Large'  => $this->large,
		);

		$parent = new WC_Product_Variable();
		$parent->set_name( 'VQ Variable' );

		if ( null !== $category ) {
			$parent->set_category_ids( array( $category ) );
		}

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$attribute->set_name( $this->size_tax );
		$attribute->set_options( array_values( array_intersect_key( $term_id_for, $sizes ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent_id = $parent->save();

		$variations = array();
		foreach ( $sizes as $name => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $this->size_tax => get_term( $term_id_for[ $name ] )->slug ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 7 );
			$variation->set_stock_status( 'instock' );
			$variations[ $name ] = $variation->save();
		}

		return array( $parent_id, $variations );
	}

	private function ensure_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy );

		return (int) $created['term_id'];
	}

	private function ensure_size_attribute(): string {
		$taxonomy = wc_attribute_taxonomy_name( 'size' );

		if ( ! wc_attribute_taxonomy_id_by_name( 'size' ) ) {
			wc_create_attribute(
				array(
					'name' => 'Size',
					'slug' => 'size',
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
		if ( taxonomy_exists( 'pa_size' ) ) {
			unregister_taxonomy( 'pa_size' );
		}

		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );
	}
}
