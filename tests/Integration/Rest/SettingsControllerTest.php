<?php
/**
 * Integration tests for the settings REST endpoints.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use CatalogOps\Operations\Retention;
use CatalogOps\Rest\Settings_Controller;
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
		delete_option( Settings_Controller::BACKUP_OPTION );
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

	public function test_onboarding_starts_unseen_and_unacknowledged(): void {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/onboarding' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['tour_done'] );
		$this->assertFalse( $data['backup_ack'] );
		$this->assertSame( Retention::DEFAULT_DAYS, $data['retention_days'] );
	}

	public function test_marking_the_tour_done_persists_for_the_user(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/settings/onboarding' );
		$request->set_body_params( array( 'tour_done' => true ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['tour_done'] );
		// A later read reflects it, and the backup flag is untouched.
		$fresh = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/onboarding' ) )->get_data();
		$this->assertTrue( $fresh['tour_done'] );
		$this->assertFalse( $fresh['backup_ack'] );
	}

	public function test_acknowledging_the_backup_persists_site_wide(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/settings/onboarding' );
		$request->set_body_params( array( 'backup_ack' => true ) );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['backup_ack'] );
		$this->assertTrue( (bool) get_option( Settings_Controller::BACKUP_OPTION ) );
	}

	public function test_onboarding_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/onboarding' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
