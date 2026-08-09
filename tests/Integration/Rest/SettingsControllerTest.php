<?php
/**
 * Integration tests for the settings REST endpoints.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use CatalogOps\Operations\Retention;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Settings_Controller
 */
final class SettingsControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		delete_option( Retention::OPTION );
		parent::tear_down();
	}

	public function test_get_returns_the_window_and_its_bounds(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/retention' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( Retention::DEFAULT_DAYS, $data['days'] );
		$this->assertSame( Retention::MIN_DAYS, $data['min'] );
		$this->assertSame( Retention::MAX_DAYS, $data['max'] );
	}

	public function test_put_clamps_and_persists_the_window(): void {
		$request = new WP_REST_Request( 'PUT', '/catalogops/v1/settings/retention' );
		$request->set_body_params( array( 'days' => 5 ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		// Below the minimum, clamped up.
		$this->assertSame( Retention::MIN_DAYS, $response->get_data()['days'] );

		$this->assertSame( Retention::MIN_DAYS, (int) get_option( Retention::OPTION ) );
	}

	public function test_settings_require_a_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/retention' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
