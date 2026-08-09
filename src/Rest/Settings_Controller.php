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
