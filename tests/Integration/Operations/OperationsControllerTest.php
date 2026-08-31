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

use CatalogOps\Licensing\License;
use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Rest\Operations_Controller;
use WC_Product_Simple;
use WP_Error;
use WP_REST_Request;

/**
 * @covers \CatalogOps\Rest\Operations_Controller
 */
final class OperationsControllerTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
	}

	public function tear_down(): void {
		delete_option( 'catalogops_active_operation' );
		parent::tear_down();
	}

	public function test_preview_reports_matched_and_applicable_counts(): void {
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
		// A literal Set reads nothing, so every match is applicable — none omitted.
		$this->assertSame( 1, $data['matched'] );
		$this->assertSame( 1, $data['applicable'] );
		$this->assertSame( 0, $data['omitted'] );
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

	public function test_changes_endpoint_returns_deltas_and_counts(): void {
		$op_id = $this->completed_operation( array( 501, 502 ) );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id . '/changes' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 2, $data['counts']['applied'] );
		$this->assertSame( 'regular_price', $data['items'][0]['field_key'] );
		$this->assertSame( 'applied', $data['items'][0]['status'] );
		$this->assertSame( '19.90', $data['items'][0]['old_value'] );
		$this->assertSame( '9.99', $data['items'][0]['new_value'] );
	}

	public function test_changes_endpoint_enriches_with_sku_and_supports_sku_search(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_sku( 'COPS-AUDIT-1' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$pid = $product->save();

		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);
		$this->changes->seed(
			$op_id,
			array(
				array( 'object_id' => $pid, 'field_type' => 'post_field', 'field_key' => 'regular_price' ),
			)
		);
		foreach ( $this->changes->pending_chunk( $op_id, 10 ) as $row ) {
			$this->changes->mark_applied( $row->id, '10', '9.99' );
		}
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );

		// The row carries the product's SKU and name, not just an internal id.
		$all  = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id . '/changes' ) );
		$item = $all->get_data()['items'][0];
		$this->assertSame( 'COPS-AUDIT-1', $item['sku'] );
		$this->assertNotSame( '', $item['name'] );

		// SKU search finds it.
		$hit = new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id . '/changes' );
		$hit->set_param( 'sku', 'AUDIT' );
		$found = rest_do_request( $hit )->get_data();
		$this->assertCount( 1, $found['items'] );
		$this->assertSame( $pid, $found['items'][0]['object_id'] );

		// A non-matching SKU returns nothing.
		$miss = new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id . '/changes' );
		$miss->set_param( 'sku', 'NOPE' );
		$this->assertCount( 0, rest_do_request( $miss )->get_data()['items'] );

		wp_delete_post( $pid, true );
	}

	public function test_undo_preview_endpoint_reports_total(): void {
		$op_id = $this->completed_operation( array( 601, 602 ) );

		$response = $this->post( '/catalogops/v1/operations/' . $op_id . '/undo/preview', array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['total'] );
		$this->assertSame( 'skip', $response->get_data()['conflict_policy'] );
	}

	public function test_undo_preview_unknown_operation_is_404(): void {
		$response = $this->post( '/catalogops/v1/operations/999999/undo/preview', array() );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_undo_endpoint_creates_and_queues_the_inverse_operation(): void {
		$op_id = $this->completed_operation( array( 701, 702 ) );

		$response = $this->post(
			'/catalogops/v1/operations/' . $op_id . '/undo',
			array( 'conflict_policy' => 'force' )
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'undo', $data['source'] );
		$this->assertSame( $op_id, $data['parent_op_id'] );
		$this->assertSame( 'force', $data['conflict_policy'] );
		// Its targets were frozen from the parent's two applied deltas.
		$this->assertSame( 2, $data['target_count'] );
	}

	public function test_create_with_formula_on_free_plan_returns_402(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/operations' );
		$request->set_body_params(
			array(
				'filter'  => array(),
				'actions' => array(
					array( 'type' => 'formula', 'field' => 'regular_price', 'expression' => 'regular_price * 0.9' ),
				),
			)
		);

		$response = $this->free_controller()->create( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'catalogops_upgrade_required', $response->get_error_code() );
		$this->assertSame( 402, $response->get_error_data()['status'] );
	}

	public function test_undo_on_free_plan_returns_402(): void {
		// The license gate fires before the parent lookup, so any id reaches it.
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/operations/123/undo' );
		$request->set_param( 'id', 123 );

		$response = $this->free_controller()->undo( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'catalogops_upgrade_required', $response->get_error_code() );
		$this->assertSame( 402, $response->get_error_data()['status'] );
	}

	public function test_undo_preview_on_free_plan_returns_402(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/operations/123/undo/preview' );
		$request->set_param( 'id', 123 );

		$response = $this->free_controller()->undo_preview( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'catalogops_upgrade_required', $response->get_error_code() );
		$this->assertSame( 402, $response->get_error_data()['status'] );
	}

	public function test_can_undo_flag_is_false_on_free_plan(): void {
		$op_id   = $this->completed_operation( array( 801, 802 ) );
		$request = new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id );
		$request->set_param( 'id', $op_id );

		$data = $this->controller_for( License::free() )->show( $request )->get_data();

		// The operation is completed with changes, so only the plan holds undo back.
		$this->assertFalse( $data['can_undo'] );
	}

	public function test_can_undo_flag_is_true_on_paid_plan(): void {
		$op_id   = $this->completed_operation( array( 811, 812 ) );
		$request = new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id );
		$request->set_param( 'id', $op_id );

		$data = $this->controller_for( License::unlimited() )->show( $request )->get_data();

		$this->assertTrue( $data['can_undo'] );
	}

	/**
	 * Build a controller gated to a specific license, sharing the per-test repos.
	 * The paid-only paths (formulas, undo) raise License_Limited, which the
	 * controller maps to HTTP 402; the license also gates the `can_undo` response
	 * flag. Bypasses the container, whose license is unlimited under test.
	 *
	 * @param License $license The plan to gate on.
	 */
	private function controller_for( License $license ): Operations_Controller {
		global $wpdb;

		$service = new Operation_Service(
			new Query_Engine( $wpdb ),
			$this->operations,
			$this->changes,
			new Field_Providers( new Core_Fields(), new Meta_Fields() ),
			new Lock( $this->operations ),
			new Recording_Scheduler(),
			$license
		);

		return new Operations_Controller( $service, $this->operations, $this->changes, $wpdb, $license );
	}

	/**
	 * A controller gated to the free plan.
	 */
	private function free_controller(): Operations_Controller {
		return $this->controller_for( License::free() );
	}

	/**
	 * Create a completed operation with applied deltas on the given object ids —
	 * a stand-in for a finished run, so the audit and undo endpoints have real
	 * recorded changes to work from without driving the async chain.
	 *
	 * @param int[] $object_ids Object ids that were changed.
	 * @return int Operation id.
	 */
	private function completed_operation( array $object_ids ): int {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);

		$rows = array_map(
			static fn( int $id ): array => array(
				'object_id'  => $id,
				'field_type' => 'post_field',
				'field_key'  => 'regular_price',
			),
			$object_ids
		);
		$this->changes->seed( $op_id, $rows );

		foreach ( $this->changes->pending_chunk( $op_id, 100 ) as $row ) {
			$this->changes->mark_applied( $row->id, '19.90', '9.99' );
		}

		$this->operations->set_target_count( $op_id, count( $object_ids ) );
		$this->operations->record_progress( $op_id, count( $object_ids ), 0 );
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );

		return $op_id;
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
