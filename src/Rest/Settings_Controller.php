<?php
/**
 * REST endpoints for plugin settings.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Operations\Retention;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the settings the admin app can read and change. For M3 that is the
 * retention window — the undo/audit horizon (CONTEXT §3), which must be explicit
 * in the UI so a user knows how long an operation stays reversible. The bounds
 * live on {@see Retention}; this controller only shapes them for the client and
 * clamps writes there.
 */
final class Settings_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * User meta flag: the current user has finished the first-run tour. Per user,
	 * so every admin sees the walkthrough once (CONTEXT §4 M6 DoD — a newcomer runs
	 * their first operation unaided).
	 */
	public const TOUR_META = 'catalogops_tour_done';

	/**
	 * Site option: the mandatory backup reminder has been acknowledged, so the
	 * first-operation gate steps aside (CONTEXT §9 — "obavezan backup podsetnik pri
	 * prvoj operaciji"). Site-wide: it is a property of this catalog, not the user.
	 */
	public const BACKUP_OPTION = 'catalogops_backup_ack';

	/**
	 * Retention service.
	 *
	 * @var Retention
	 */
	private Retention $retention;

	/**
	 * Build the controller.
	 *
	 * @param Retention $retention Retention service.
	 */
	public function __construct( Retention $retention ) {
		$this->retention = $retention;
	}

	/**
	 * Register the settings routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings/retention',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'days' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings/onboarding',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show_onboarding' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_onboarding' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'tour_done'  => array( 'type' => 'boolean' ),
						'backup_ack' => array( 'type' => 'boolean' ),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may manage settings.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Return the current retention window and its allowed bounds.
	 */
	public function show(): WP_REST_Response {
		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Set the retention window (clamped to the allowed range) and return it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		$this->retention->set_days( (int) $request->get_param( 'days' ) );

		return new WP_REST_Response( $this->payload() );
	}

	/**
	 * Return the onboarding state for the current user and site.
	 */
	public function show_onboarding(): WP_REST_Response {
		return new WP_REST_Response( $this->onboarding_payload() );
	}

	/**
	 * Record onboarding progress: the first-run tour is finished (per user) and/or
	 * the backup reminder is acknowledged (per site). Only the flags that are sent
	 * are changed, and only ever forward — a client cannot un-acknowledge.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function update_onboarding( WP_REST_Request $request ): WP_REST_Response {
		if ( true === $request->get_param( 'tour_done' ) ) {
			update_user_meta( get_current_user_id(), self::TOUR_META, 1 );
		}

		if ( true === $request->get_param( 'backup_ack' ) ) {
			update_option( self::BACKUP_OPTION, 1 );
		}

		return new WP_REST_Response( $this->onboarding_payload() );
	}

	/**
	 * The onboarding payload: what the app needs to decide whether to show the
	 * first-run tour and the first-operation backup gate, plus the retention window
	 * so the copy can name the concrete undo horizon.
	 *
	 * @return array{tour_done: bool, backup_ack: bool, retention_days: int}
	 */
	private function onboarding_payload(): array {
		return array(
			'tour_done'      => (bool) get_user_meta( get_current_user_id(), self::TOUR_META, true ),
			'backup_ack'     => (bool) get_option( self::BACKUP_OPTION, false ),
			'retention_days' => $this->retention->days(),
		);
	}

	/**
	 * The retention settings payload.
	 *
	 * @return array{days: int, min: int, max: int, default: int}
	 */
	private function payload(): array {
		return array(
			'days'    => $this->retention->days(),
			'min'     => Retention::MIN_DAYS,
			'max'     => Retention::MAX_DAYS,
			'default' => Retention::DEFAULT_DAYS,
		);
	}
}
