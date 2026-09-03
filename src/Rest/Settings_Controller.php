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
	 * Site option: the mandatory backup reminder has been acknowledged (CONTEXT §9).
	 *
	 * Superseded by {@see BACKUP_META} and read no longer. It was site-wide and a
	 * bare `1`, which made it two things at once and neither of them well: as a
	 * gate, one admin's click silenced the reminder for every colleague who came
	 * after, so someone's first bulk edit over eighteen thousand products could
	 * arrive with no warning at all; and as a record it said only that somebody
	 * with access had clicked something at some point, which cannot answer "who,
	 * and when". Left in the database rather than deleted — it is a statement about
	 * a past install, and removing user data to tidy up is not this migration's
	 * business.
	 */
	public const BACKUP_OPTION = 'catalogops_backup_ack';

	/**
	 * User meta: this user has acknowledged the backup reminder, recorded as
	 * `{ time, version }` in GMT.
	 *
	 * Per user, like {@see TOUR_META} and for the same reason — the reminder exists
	 * to make a person stop before their first destructive run, and a colleague who
	 * joins next year has not stopped yet. The time and version are stored because
	 * an acknowledgement with neither is not a record of anything: with them the
	 * confirmation can say who agreed and when, and can point out that a backup
	 * confirmed eight months ago may no longer be recent.
	 */
	public const BACKUP_META = 'catalogops_backup_ack';

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
			// Written once and never overwritten: the record is of the moment the
			// user first agreed, and re-stamping it on a later run would quietly
			// keep it looking fresh — which is the one thing it must not do.
			$existing = get_user_meta( get_current_user_id(), self::BACKUP_META, true );

			if ( ! is_array( $existing ) || ! isset( $existing['time'] ) ) {
				update_user_meta(
					get_current_user_id(),
					self::BACKUP_META,
					array(
						'time'    => current_time( 'mysql', true ),
						'version' => CATALOGOPS_VERSION,
					)
				);
			}
		}

		return new WP_REST_Response( $this->onboarding_payload() );
	}

	/**
	 * The onboarding payload: what the app needs to decide whether to show the
	 * first-run tour and the first-operation backup gate, plus the retention window
	 * so the copy can name the concrete undo horizon.
	 *
	 * The acknowledgement travels as a boolean plus the record behind it, so the
	 * confirmation can name who agreed and when instead of silently standing aside.
	 * Formatting the date here rather than in the client is the same call as
	 * elsewhere: the value is stored in GMT, and the shop's clock and date format
	 * are known on this side.
	 *
	 * @return array{tour_done: bool, backup_ack: bool, backup_ack_by: string, backup_ack_at: string, backup_ack_version: string, retention_days: int}
	 */
	private function onboarding_payload(): array {
		$user = wp_get_current_user();
		$ack  = get_user_meta( $user->ID, self::BACKUP_META, true );
		$has  = is_array( $ack ) && isset( $ack['time'] );

		return array(
			'tour_done'          => (bool) get_user_meta( $user->ID, self::TOUR_META, true ),
			'backup_ack'         => $has,
			'backup_ack_by'      => $has ? $user->display_name : '',
			'backup_ack_at'      => $has ? $this->to_local( (string) $ack['time'] ) : '',
			'backup_ack_version' => $has ? (string) ( $ack['version'] ?? '' ) : '',
			'retention_days'     => $this->retention->days(),
		);
	}

	/**
	 * Render a GMT MySQL datetime in the shop's own clock and date format.
	 *
	 * @param string $gmt GMT MySQL datetime.
	 */
	private function to_local( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return wp_date(
			get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ),
			$timestamp
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
