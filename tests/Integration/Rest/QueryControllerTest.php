<?php
/**
 * Integration tests for the query REST endpoint.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use WC_Product_Simple;
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
