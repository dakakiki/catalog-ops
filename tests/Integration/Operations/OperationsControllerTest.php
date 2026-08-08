<?php
/**
 * Integration tests for the operations REST endpoints.
 *
 * Dispatches through the REST server (rest_do_request), so route registration,
 * permissions, and the real container wiring are all exercised. The recording
 * scheduler is not used here — queueing hands off to Action Scheduler, whose
 * writes roll back with the per-test transaction and whose queue never runs.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Operations;
use WC_Product_Simple;
use WP_REST_Request;

/**
 * @covers \CatalogOps\Rest\Operations_Controller
 */
final class OperationsControllerTest extends Operations_Database_Case {

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
	}

	public function tear_down(): void {
		delete_option( 'catalogops_active_operation' );
		parent::tear_down();
	}

	public function test_preview_reports_target_count_and_changes(): void {
		$this->make_product( 10 );
		$this->make_product( 30 );

		$response = $this->post(
			'/catalogops/v1/operations/preview',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 20 ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '9.99' ) ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 1, $data['target_count'] );
		$this->assertCount( 1, $data['sample'] );
		$this->assertSame( 'regular_price', $data['sample'][0]['changes'][0]['field'] );
		$this->assertSame( '9.99', $data['sample'][0]['changes'][0]['new'] );
	}

	public function test_create_queues_and_returns_201_with_progress(): void {
		$this->make_product( 30 );
		$this->make_product( 40 );

		$response = $this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 20 ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '5.00' ) ),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'queued', $data['status'] );
		$this->assertSame( 2, $data['target_count'] );
		$this->assertSame( 0, $data['percent'] );

		// The operation is retrievable through the show route.
		$show = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $data['id'] ) );
		$this->assertSame( 200, $show->get_status() );
		$this->assertSame( $data['id'], $show->get_data()['id'] );
	}

	public function test_create_rejects_unsupported_field_with_400(): void {
		$response = $this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array(),
				'actions' => array( array( 'type' => 'set', 'field' => 'bogus', 'value' => 'x' ) ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_create_rejects_empty_actions_with_400(): void {
		$response = $this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array(),
				'actions' => array(),
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_show_returns_404_for_unknown_operation(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations/999999' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_endpoint_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$response = $this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array(),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '1.00' ) ),
			)
		);

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_index_lists_created_operations(): void {
		$this->make_product( 30 );
		$this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 20 ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '5.00' ) ),
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['items'] );
	}

	/**
	 * POST a JSON body to a route and return the response.
	 *
	 * @param string               $route The REST route.
	 * @param array<string, mixed> $body  Body parameters.
	 */
	private function post( string $route, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	/**
	 * Create a simple, in-stock product at a given price.
	 *
	 * @param float $price Regular price.
	 * @return int Product id.
	 */
	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		return $product->save();
	}
}
