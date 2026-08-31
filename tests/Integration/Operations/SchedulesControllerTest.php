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
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedules;
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

	/**
	 * A well-formed create request the license gate is the only thing standing in
	 * front of.
	 */
	private function create_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/schedules' );
		$request->set_body_params(
			array(
				'name'       => 'Nightly markdown',
				'filter'     => array(),
				'actions'    => array( array( 'type' => 'set', 'field' => 'regular_price', 'value' => '9.99' ) ),
				'recurrence' => 'daily',
			)
		);

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
