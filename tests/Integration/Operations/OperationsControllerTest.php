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
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedules;
use CatalogOps\Operations\Watchdog;
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
	private Schedules $schedules;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
		$this->schedules  = new Schedules( $wpdb, $this->schema );
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

	public function test_preview_names_the_products_in_its_sample(): void {
		// The service answers in ids; a person needs the product. The endpoint
		// resolves identity with the same lookup the audit view uses, so a preview
		// row and the history row it becomes read the same.
		$id = $this->make_product( 30 );
		$product = wc_get_product( $id );
		$product->set_sku( 'QC-PREVIEW-1' );
		$product->set_name( 'Preview Sample Product' );
		$product->save();

		$response = $this->post(
			'/catalogops/v1/operations/preview',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 20 ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '9.99' ) ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$sample = $response->get_data()['sample'];

		$this->assertCount( 1, $sample );
		$this->assertSame( $id, $sample[0]['id'] );
		$this->assertSame( 'QC-PREVIEW-1', $sample[0]['sku'] );
		$this->assertSame( 'Preview Sample Product', $sample[0]['name'] );
		$this->assertSame( '30', $sample[0]['changes'][0]['old'] );
		$this->assertSame( '9.99', $sample[0]['changes'][0]['new'] );
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

	/**
	 * Pins the boundary assert in Operation_Service::create(): a filter naming a
	 * field the engine cannot answer is refused with a 400, and — this is the point
	 * of the second assertion — refused before any row is written, which is only
	 * true while the assert sits ahead of Operations::create() rather than after it.
	 *
	 * 0.7.1 dropped the unrecognised condition instead. build_where() skipped the
	 * empty fragment, so the filter ran one condition lighter and matched strictly
	 * more objects than were asked for: the operation was created and queued
	 * against the whole catalogue and answered 201, leaving a row in the history
	 * for an edit nobody had described.
	 *
	 * The same invariant FormulaWriteTest holds one step later — a refused
	 * operation leaves no draft behind — carried back to the earlier refusal.
	 */
	public function test_create_refuses_an_unanswerable_filter_before_recording_a_draft(): void {
		// A product the widened filter would have swept up and repriced.
		$this->make_product( 30 );

		$response = $this->post(
			'/catalogops/v1/operations',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'brand', 'operator' => '=', 'value' => 'Acme' ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '5.00' ) ),
			)
		);

		$this->assertSame( 400, $response->get_status() );

		// Nothing recorded at all — not a draft that is discarded afterwards, but a
		// refusal that happens before the history is ever touched.
		$this->assertCount( 0, $this->operations->recent( 100 ) );
	}

	/**
	 * Pins the preview endpoint refusing the same unanswerable filter with a 400
	 * rather than answering a count computed from a widened filter.
	 *
	 * 0.7.1 answered 200 with `matched` describing every product in the shop, and
	 * because the run resolved the identically widened filter, preview and run
	 * agreed perfectly — so nothing downstream could notice the difference.
	 */
	public function test_preview_refuses_an_unanswerable_filter(): void {
		$this->make_product( 30 );

		$response = $this->post(
			'/catalogops/v1/operations/preview',
			array(
				'filter'  => array( 'conditions' => array( array( 'field' => 'brand', 'operator' => '=', 'value' => 'Acme' ) ) ),
				'actions' => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '9.99' ) ),
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

	public function test_index_pages_the_history_and_reports_how_far_it_goes(): void {
		// Twelve, so the default ten leaves a second page with a remainder on it.
		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->draft_operation();
		}

		$first = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/operations' ) )->get_data();

		$this->assertCount( 10, $first['items'] );
		// The count is the whole history, not the page — without it the list used
		// to stop at its own page size with no sign there was more behind it.
		$this->assertSame( 12, $first['total'] );
		$this->assertSame( 1, $first['page'] );
		$this->assertSame( 10, $first['per_page'] );

		// Newest first, so the first page opens on the last operation created.
		$this->assertSame( end( $ids ), $first['items'][0]['id'] );

		$request = new WP_REST_Request( 'GET', '/catalogops/v1/operations' );
		$request->set_param( 'page', 2 );
		$second = rest_do_request( $request )->get_data();

		$this->assertCount( 2, $second['items'] );
		$this->assertSame( 2, $second['page'] );

		// The two pages partition the history — no row is shown twice or missed.
		$paged = array_merge(
			array_column( $first['items'], 'id' ),
			array_column( $second['items'], 'id' )
		);
		$this->assertSame( array_reverse( $ids ), $paged );
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

	/**
	 * The undo confirmation warns that reverting a scheduled run will pause the
	 * schedule behind it, and this is the boundary that sentence is built from —
	 * the panel has no other source for the schedule's name. Pinned here as well as
	 * in the service because it crosses the REST layer untouched, which is easy to
	 * break by shaping the response rather than passing it through.
	 */
	public function test_the_undo_preview_carries_a_live_schedule_to_the_client(): void {
		$schedule_id = $this->schedules->create(
			'Nightly cut',
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::DAILY,
			'2099-01-01 00:00:00',
			'',
			get_current_user_id()
		);
		$op_id       = $this->completed_operation( array( 611, 612 ), $schedule_id );

		$response = $this->post( '/catalogops/v1/operations/' . $op_id . '/undo/preview', array() );

		$this->assertSame( 200, $response->get_status() );
		$schedule = $response->get_data()['schedule'];
		$this->assertNotNull( $schedule );
		$this->assertSame( $schedule_id, $schedule['id'] );
		$this->assertSame( 'Nightly cut', $schedule['name'] );
	}

	public function test_the_undo_preview_carries_no_schedule_for_a_run_started_by_hand(): void {
		$op_id = $this->completed_operation( array( 621 ) );

		$response = $this->post( '/catalogops/v1/operations/' . $op_id . '/undo/preview', array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'schedule', $response->get_data() );
		$this->assertNull( $response->get_data()['schedule'] );
	}

	/**
	 * The history prints this value straight, and the schedules card beside it has
	 * always printed local time. Shipping only GMT here did not read to a user as a
	 * timezone question: the same scheduled run showed 08:01 in the history and
	 * 10:01 as the schedule's last fire, which reads as a run that went off hours
	 * before it was due.
	 */
	public function test_operation_times_are_carried_in_the_sites_own_clock(): void {
		$this->with_gmt_offset(
			2,
			function (): void {
				$op_id = $this->completed_operation( array( 631 ) );

				$data = rest_do_request(
					new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id )
				)->get_data();

				$this->assertSame(
					get_date_from_gmt( $data['created_at'] ),
					$data['created_at_local']
				);
				// Two hours apart, and the GMT value is the one still stored.
				$this->assertSame(
					strtotime( $data['created_at'] ) + 2 * HOUR_IN_SECONDS,
					strtotime( $data['created_at_local'] )
				);
				$this->assertSame(
					get_date_from_gmt( $data['completed_at'] ),
					$data['completed_at_local']
				);
			}
		);
	}

	/**
	 * The Stop confirmation warns that stopping a scheduled run pauses its schedule,
	 * and this is all it has to go on: the id is read straight off the operation row,
	 * so the history pays nothing for it on a poll.
	 */
	public function test_the_history_says_whether_a_run_came_from_a_schedule(): void {
		$schedule_id = $this->schedules->create(
			'Nightly cut',
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::DAILY,
			'2099-01-01 00:00:00',
			'',
			get_current_user_id()
		);

		$scheduled = $this->completed_operation( array( 641 ), $schedule_id );
		$by_hand   = $this->completed_operation( array( 642 ) );

		$this->assertSame( $schedule_id, $this->show( $scheduled )['schedule_id'] );
		$this->assertNull( $this->show( $by_hand )['schedule_id'] );
	}

	/**
	 * The history says "not responding" from this flag, and offers no Resume while
	 * it is set. It has to agree exactly with what the watchdog is about to fail, or
	 * the screen either accuses a healthy run or keeps promising a dead one.
	 */
	public function test_a_run_that_has_stopped_reporting_is_flagged_stalled(): void {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);
		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		// A fresh heartbeat is a healthy run, however slow.
		$this->operations->touch( $op_id );
		$this->assertFalse( $this->show( $op_id )['is_stalled'] );

		$this->backdate_progress( $op_id, Watchdog::STALL_THRESHOLD + 60 );
		$this->assertTrue( $this->show( $op_id )['is_stalled'] );

		// And the same row, once it is no longer running, is not stalled but simply
		// over — the watchdog has had it, and Resume is the control that applies.
		$this->operations->set_status( $op_id, Operation_Status::FAILED );
		$this->assertFalse( $this->show( $op_id )['is_stalled'] );
	}

	/**
	 * The history counts rather than sitting silent until a threshold trips, so it
	 * needs the duration and not just the verdict. Null once a run is over: a
	 * finished operation is not quiet, it is done, and a screen counting seconds
	 * beside it would be describing nothing.
	 */
	public function test_the_history_is_told_how_long_a_run_has_been_quiet(): void {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);
		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		$this->operations->touch( $op_id );
		$this->assertLessThan( 5, $this->show( $op_id )['quiet_seconds'] );

		$this->backdate_progress( $op_id, 90 );
		$quiet = $this->show( $op_id )['quiet_seconds'];
		$this->assertGreaterThanOrEqual( 90, $quiet );
		$this->assertLessThan( 100, $quiet );

		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
		$this->assertNull( $this->show( $op_id )['quiet_seconds'] );
	}

	/**
	 * A queued run has no heartbeat yet, and must not be read as one that has gone
	 * quiet — the watchdog's query ignores it for the same reason.
	 */
	public function test_a_run_that_has_not_started_is_not_flagged_stalled(): void {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);
		$this->operations->set_status( $op_id, Operation_Status::QUEUED );

		$this->assertFalse( $this->show( $op_id )['is_stalled'] );
	}

	/**
	 * Push an operation's heartbeat into the past.
	 *
	 * @param int $op_id   Operation id.
	 * @param int $seconds How far back.
	 */
	private function backdate_progress( int $op_id, int $seconds ): void {
		global $wpdb;

		$wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ),
			array( 'id' => $op_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * One operation, as the history sees it.
	 *
	 * @param int $op_id Operation id.
	 * @return array<string, mixed>
	 */
	private function show( int $op_id ): array {
		return rest_do_request(
			new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id )
		)->get_data();
	}

	public function test_an_unfinished_operation_has_no_local_completion_time(): void {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);

		$data = rest_do_request(
			new WP_REST_Request( 'GET', '/catalogops/v1/operations/' . $op_id )
		)->get_data();

		$this->assertNull( $data['completed_at'] );
		$this->assertNull( $data['completed_at_local'] );
	}

	/**
	 * Run a test body with the site on a fixed UTC offset, restoring the original
	 * settings afterwards. A fixed offset rather than a named zone so the
	 * expectation cannot shift with daylight saving.
	 *
	 * @param int      $hours Offset from UTC.
	 * @param callable $body  The assertions to run.
	 */
	private function with_gmt_offset( int $hours, callable $body ): void {
		$original_string = get_option( 'timezone_string' );
		$original_offset = get_option( 'gmt_offset' );

		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', $hours );

		try {
			$body();
		} finally {
			update_option( 'timezone_string', $original_string );
			update_option( 'gmt_offset', $original_offset );
		}
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
	/**
	 * A bare draft operation, for tests that only care that a row exists.
	 */
	private function draft_operation(): int {
		return $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			get_current_user_id()
		);
	}

	private function completed_operation( array $object_ids, ?int $schedule_id = null ): int {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			null === $schedule_id ? Operation_Source::UI : Operation_Source::SCHEDULE,
			get_current_user_id(),
			$schedule_id
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

	public function test_delete_removes_the_operation_and_its_recorded_changes(): void {
		$op_id = $this->completed_operation( array( $this->make_product( 10 ) ) );

		$this->assertSame( 1, $this->changes->counts( $op_id )['applied'] );

		$response = $this->delete( "/catalogops/v1/operations/{$op_id}" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );

		$this->assertNull( $this->operations->find( $op_id ) );
		// The deltas go with it — leaving them would be unreachable rows nothing
		// ever cleans up, and they are the whole reason deleting is irreversible.
		$this->assertSame( 0, array_sum( $this->changes->counts( $op_id ) ) );
	}

	public function test_delete_refuses_while_the_operation_is_running(): void {
		// Deleting mid-write would leave the remaining chunks pointing at nothing.
		$op_id = $this->completed_operation( array( $this->make_product( 10 ) ) );
		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		$response = $this->delete( "/catalogops/v1/operations/{$op_id}" );

		$this->assertSame( 409, $response->get_status() );
		$this->assertNotNull( $this->operations->find( $op_id ) );
		$this->assertSame( 1, $this->changes->counts( $op_id )['applied'] );
	}

	public function test_delete_of_a_missing_operation_is_a_404(): void {
		$response = $this->delete( '/catalogops/v1/operations/999999' );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_delete_detaches_an_undo_from_its_deleted_parent(): void {
		// The undo already ran and keeps its own record; it just stops claiming a
		// lineage that no longer exists, rather than pointing at a missing row.
		$parent = $this->completed_operation( array( $this->make_product( 10 ) ) );
		$undo   = $this->operations->create(
			new Filter(),
			array(),
			Operation_Mode::SAFE,
			Operation_Source::UNDO,
			get_current_user_id(),
			null,
			$parent
		);
		$this->operations->set_status( $undo, Operation_Status::COMPLETED, true );

		$this->assertSame( $parent, $this->operations->find( $undo )->parent_op_id );

		$this->assertSame( 200, $this->delete( "/catalogops/v1/operations/{$parent}" )->get_status() );

		$survivor = $this->operations->find( $undo );
		$this->assertNotNull( $survivor );
		$this->assertNull( $survivor->parent_op_id );
	}

	public function test_delete_frees_a_write_lock_the_operation_still_held(): void {
		// A paused operation released its lock on cancel, but a crashed one may not
		// have. Deleting the row must not leave a holder id behind pointing at it.
		$op_id = $this->completed_operation( array( $this->make_product( 10 ) ) );
		update_option( 'catalogops_active_operation', $op_id, false );

		$this->assertSame( 200, $this->delete( "/catalogops/v1/operations/{$op_id}" )->get_status() );

		$this->assertSame( 0, ( new Lock( $this->operations ) )->holder() );
	}

	/**
	 * DELETE a route and return the response.
	 *
	 * @param string $route The REST route.
	 */
	private function delete( string $route ): \WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'DELETE', $route ) );
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
