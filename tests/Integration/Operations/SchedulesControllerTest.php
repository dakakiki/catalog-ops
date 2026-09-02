<?php
/**
 * Integration tests for plan gating on the schedules REST endpoint.
 *
 * Scheduling is a paid-plan feature (CONTEXT §5): a free-tier license makes
 * `create()` return HTTP 402 before any work, while a paid (unlimited) license
 * creates the schedule. The controller is built directly with an explicit
 * license rather than the container's, whose license is unlimited under test.
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
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedules;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Rest\Schedules_Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \CatalogOps\Rest\Schedules_Controller
 */
final class SchedulesControllerTest extends Operations_Database_Case {

	private Schedules $schedules;
	private Operations $operations;

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wpdb;
		$this->schedules  = new Schedules( $wpdb, $this->schema );
		$this->operations = new Operations( $wpdb, $this->schema );
	}

	public function test_create_on_free_plan_returns_402(): void {
		$response = $this->controller_for( License::free() )->create( $this->create_request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'catalogops_upgrade_required', $response->get_error_code() );
		$this->assertSame( 402, $response->get_error_data()['status'] );

		// The gate fires before any write: nothing was persisted.
		$this->assertSame( array(), $this->schedules->all() );
	}

	public function test_create_on_paid_plan_is_allowed(): void {
		$response = $this->controller_for( License::unlimited() )->create( $this->create_request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $this->schedules->all() );
	}

	public function test_index_pages_the_list_and_reports_the_whole_count(): void {
		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$ids[] = $this->schedules->create(
				'QC Schedule ' . $i,
				new Filter(),
				array( new Set_Value( 'regular_price', '9.99' ) ),
				Operation_Mode::SAFE,
				Recurrence::DAILY,
				'2026-09-02 03:00:00',
				'',
				get_current_user_id()
			);
		}

		$controller = $this->controller_for( License::unlimited() );

		$first = $controller->index( new WP_REST_Request( 'GET', '/catalogops/v1/schedules' ) )->get_data();

		$this->assertCount( 10, $first['items'] );
		$this->assertSame( 12, $first['total'] );
		$this->assertSame( 1, $first['page'] );

		$request = new WP_REST_Request( 'GET', '/catalogops/v1/schedules' );
		$request->set_param( 'page', 2 );
		$second = $controller->index( $request )->get_data();

		$this->assertCount( 2, $second['items'] );

		// Newest first, and the two pages together are the whole list exactly once.
		$paged = array_merge(
			array_column( $first['items'], 'id' ),
			array_column( $second['items'], 'id' )
		);
		$this->assertSame( array_reverse( $ids ), $paged );
	}

	public function test_start_time_is_read_as_the_sites_own_clock(): void {
		// The Start control submits wall-clock time with no timezone: "22:00" means
		// 22:00 by the shop's clock. WordPress runs PHP in UTC, so reading it there
		// would store 22:00 GMT and fire the whole offset away from the hour the
		// user picked — on a +3 site, at 01:00 the next morning.
		$this->with_gmt_offset(
			3,
			function (): void {
				$response = $this->controller_for( License::unlimited() )
					->create( $this->create_request( '2026-09-01T22:00' ) );

				$this->assertInstanceOf( WP_REST_Response::class, $response );

				$data = $response->get_data();

				$this->assertSame( '2026-09-01 19:00:00', $data['next_run'] );
				// And read back the way it was typed.
				$this->assertSame( '2026-09-01 22:00:00', $data['next_run_local'] );
			}
		);
	}

	public function test_an_empty_start_time_means_the_next_tick(): void {
		$response = $this->controller_for( License::unlimited() )->create( $this->create_request( '' ) );

		$data = $response->get_data();

		// Due now, in GMT, so the supervisor picks it up on its next pass.
		$this->assertNotEmpty( $data['next_run'] );
		$this->assertLessThanOrEqual(
			strtotime( current_time( 'mysql', true ) ) + 5,
			strtotime( $data['next_run'] )
		);
	}

	public function test_an_unparseable_start_time_does_not_fail_the_request(): void {
		$response = $this->controller_for( License::unlimited() )->create( $this->create_request( 'not a date' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['next_run'] );
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

	/**
	 * A well-formed create request the license gate is the only thing standing in
	 * front of.
	 *
	 * @param string|null $starts_at Start time as the datetime-local control submits
	 *                               it, or null to omit the field entirely.
	 */
	private function create_request( ?string $starts_at = null ): WP_REST_Request {
		$params = array(
			'name'       => 'Nightly markdown',
			'filter'     => array(),
			'actions'    => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '9.99' ) ),
			'recurrence' => 'daily',
		);

		if ( null !== $starts_at ) {
			$params['starts_at'] = $starts_at;
		}

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/schedules' );
		$request->set_body_params( $params );

		return $request;
	}

	/**
	 * Build a schedules controller gated to a specific license. The runner is wired
	 * for completeness, but the free-plan gate returns before it is ever touched.
	 *
	 * @param License $license The plan to gate on.
	 */
	private function controller_for( License $license ): Schedules_Controller {
		global $wpdb;

		$changes = new Changes( $wpdb, $this->schema );
		$service = new Operation_Service(
			new Query_Engine( $wpdb ),
			$this->operations,
			$changes,
			new Field_Providers( new Core_Fields(), new Meta_Fields() ),
			new Lock( $this->operations ),
			new Recording_Scheduler(),
			$license
		);
		$runner = new Schedule_Runner( $this->schedules, $service, $this->operations );

		return new Schedules_Controller( $this->schedules, $runner, $license );
	}
}
