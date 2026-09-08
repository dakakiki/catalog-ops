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

	/**
	 * The acknowledgement is a record, not a flag, and it belongs to a person.
	 *
	 * It used to be a site-wide `1`: one admin's click stood the reminder down for
	 * every colleague who came after, so someone's first bulk edit over the whole
	 * catalogue could arrive with no warning at all — and as a record it could not
	 * answer who agreed, or when. Stored per user with the time and the version, the
	 * confirmation can say both, and can point out that an acknowledgement from
	 * months ago is not evidence of a backup today.
	 */
	public function test_acknowledging_the_backup_records_who_and_when_for_that_user(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/settings/onboarding' );
		$request->set_body_params( array( 'backup_ack' => true ) );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['backup_ack'] );
		$this->assertNotSame( '', $data['backup_ack_by'] );
		$this->assertNotSame( '', $data['backup_ack_at'] );
		$this->assertSame( CATALOGOPS_VERSION, $data['backup_ack_version'] );

		$stored = get_user_meta( get_current_user_id(), Settings_Controller::BACKUP_META, true );
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'time', $stored );
		$this->assertSame( CATALOGOPS_VERSION, $stored['version'] );
	}

	public function test_one_users_acknowledgement_does_not_answer_for_another(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/settings/onboarding' );
		$request->set_body_params( array( 'backup_ack' => true ) );
		rest_do_request( $request );

		// A colleague who has never seen the reminder must still be shown it: the
		// gate exists to make a person stop before their first destructive run, and
		// this person has not stopped yet.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$fresh = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/onboarding' ) )->get_data();

		$this->assertFalse( $fresh['backup_ack'] );
		$this->assertSame( '', $fresh['backup_ack_at'] );
	}

	public function test_the_acknowledgement_time_is_not_restamped_on_a_later_run(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/settings/onboarding' );
		$request->set_body_params( array( 'backup_ack' => true ) );

		rest_do_request( $request );
		$first = get_user_meta( get_current_user_id(), Settings_Controller::BACKUP_META, true );

		// Backdate it, then acknowledge again: the record must keep saying when the
		// user actually agreed, or it would refresh itself into always looking
		// recent — which is the one thing it exists to stop.
		update_user_meta(
			get_current_user_id(),
			Settings_Controller::BACKUP_META,
			array(
				'time'    => '2020-01-02 03:04:05',
				'version' => '0.1.0',
			)
		);

		rest_do_request( $request );
		$second = get_user_meta( get_current_user_id(), Settings_Controller::BACKUP_META, true );

		$this->assertNotSame( $first['time'], $second['time'] );
		$this->assertSame( '2020-01-02 03:04:05', $second['time'] );
		$this->assertSame( '0.1.0', $second['version'] );
	}

	public function test_onboarding_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/settings/onboarding' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
