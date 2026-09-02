<?php
/**
 * Integration tests for the query REST endpoint.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Query_Controller
 */
final class QueryControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		if ( taxonomy_exists( 'pa_size' ) ) {
			unregister_taxonomy( 'pa_size' );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_query_returns_matching_products_with_columns(): void {
		$cheap = $this->make_product( 10 );
		$dear  = $this->make_product( 100 );

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 50 ) ) )
		);

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['items'] );

		$item = $data['items'][0];
		$this->assertSame( $dear, $item['id'] );
		$this->assertNotSame( $cheap, $item['id'] );
		$this->assertArrayHasKey( 'sku', $item );
		$this->assertArrayHasKey( 'price', $item );
		$this->assertArrayHasKey( 'stock_status', $item );
	}

	public function test_empty_filter_counts_the_whole_catalog(): void {
		$this->make_product( 10 );
		$this->make_product( 20 );

		$data = $this->dispatch( array() );

		$this->assertSame( 2, $data['total'] );
		$this->assertCount( 2, $data['items'] );
	}

	public function test_pagination_slices_the_frozen_ordered_list(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->make_product( 10 + $i );
		}

		$data = $this->dispatch( array(), 1, 2 );

		$this->assertSame( 5, $data['total'] );
		$this->assertCount( 2, $data['items'] );
		$this->assertSame(
			array_slice( $ids, 0, 2 ),
			array_map( static fn( array $item ): int => $item['id'], $data['items'] )
		);
	}

	public function test_endpoint_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$response = rest_do_request( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_variation_scope_returns_variations_with_parent_context(): void {
		list( $parent, $variations ) = $this->make_variable_product();

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'scope'    => 'variation',
				'filter'   => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) ),
				'per_page' => 25,
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'variation', $data['scope'] );
		$this->assertSame( 1, $data['total'] );

		$item = $data['items'][0];
		$this->assertSame( $variations['Large'], $item['id'] );
		$this->assertSame( $parent, $item['parent_id'] );
		// The label carries the parent title and the variation's attribute value.
		$this->assertStringContainsString( 'QC Variable', $item['name'] );
		$this->assertStringContainsString( 'large', $item['name'] );
	}

	public function test_an_empty_product_result_points_at_the_variations_that_match(): void {
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) )
		);

		// A variable parent keeps no price of its own, so the Products scope has
		// nothing to match here — what the user is looking for is one scope over,
		// and an empty table alone would never say so.
		$this->assertSame( 0, $data['total'] );
		$this->assertSame(
			array(
				'scope' => 'variation',
				'total' => 1,
			),
			$data['other_scope']
		);
	}

	public function test_an_empty_variation_result_points_back_at_products(): void {
		$this->make_product( 100 );

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'scope'  => 'variation',
				'filter' => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 50 ) ) ),
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// The suggestion runs both ways: a catalogue of simple products has no
		// variations to find, and saying so beats an unexplained empty table.
		$this->assertSame( 0, $data['total'] );
		$this->assertSame(
			array(
				'scope' => 'product',
				'total' => 1,
			),
			$data['other_scope']
		);
	}

	public function test_a_result_with_matches_suggests_nothing(): void {
		$this->make_product( 100 );
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) )
		);

		// Variations match this too, but the user is looking at results — there is
		// nothing to point out, and the second count is never even run.
		$this->assertSame( 1, $data['total'] );
		$this->assertNull( $data['other_scope'] );
	}

	public function test_a_filter_that_matches_nowhere_suggests_nothing(): void {
		$this->make_product( 10 );
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 500 ) ) )
		);

		// Too narrow, not pointed at the wrong scope. Offering a switch to an
		// equally empty table would be noise.
		$this->assertSame( 0, $data['total'] );
		$this->assertNull( $data['other_scope'] );
	}

	/**
	 * Create a variable product with a Small and a Large variation.
	 *
	 * @return array{0: int, 1: array<string, int>}
	 */
	private function make_variable_product(): array {
		$size_tax = wc_attribute_taxonomy_name( 'size' );
		if ( ! wc_attribute_taxonomy_id_by_name( 'size' ) ) {
			wc_create_attribute( array( 'name' => 'Size', 'slug' => 'size', 'type' => 'select' ) );
		}
		if ( ! taxonomy_exists( $size_tax ) ) {
			register_taxonomy( $size_tax, array( 'product' ), array( 'hierarchical' => false, 'query_var' => true ) );
		}

		$small = (int) wp_insert_term( 'Small', $size_tax )['term_id'];
		$large = (int) wp_insert_term( 'Large', $size_tax )['term_id'];

		$parent = new WC_Product_Variable();
		$parent->set_name( 'QC Variable' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$attribute->set_name( $size_tax );
		$attribute->set_options( array( $small, $large ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent_id = $parent->save();

		$variations = array();
		foreach ( array( 'Small' => 10, 'Large' => 90 ) as $name => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $size_tax => sanitize_title( $name ) ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 5 );
			$variation->set_stock_status( 'instock' );
			$variations[ $name ] = $variation->save();
		}

		return array( $parent_id, $variations );
	}

	/**
	 * Dispatch a query request and assert a 200, returning the payload.
	 *
	 * @param array<string, mixed> $filter   Filter array.
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Page size.
	 * @return array<string, mixed>
	 */
	private function dispatch( array $filter, int $page = 1, int $per_page = 25 ): array {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter'   => $filter,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		return $product->save();
	}
}
