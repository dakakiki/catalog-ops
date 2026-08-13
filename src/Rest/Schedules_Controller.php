<?php
/**
 * REST endpoints for scheduled and recurring operations.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Licensing\License;
use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedule_Status;
use CatalogOps\Operations\Schedules;
use CatalogOps\Query\Filter;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes schedule management to the admin app: list, create, pause/resume,
 * delete, and fire-now. Creating a schedule stores a filter + actions template;
 * the supervisor ({@see Schedule_Runner}) fires it. The controller validates and
 * shapes payloads and delegates firing to the runner.
 */
final class Schedules_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * Schedules repository.
	 *
	 * @var Schedules
	 */
	private Schedules $schedules;

	/**
	 * Schedule supervisor (fire-now).
	 *
	 * @var Schedule_Runner
	 */
	private Schedule_Runner $runner;

	/**
	 * Plan gating (scheduling is a paid-plan feature).
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * Build the controller.
	 *
	 * @param Schedules       $schedules Schedules repository.
	 * @param Schedule_Runner $runner    Schedule supervisor.
	 * @param License|null    $license   Plan gating; defaults to unlimited
	 *                                    (unlicensed development and tests).
	 */
	public function __construct( Schedules $schedules, Schedule_Runner $runner, ?License $license = null ) {
		$this->schedules = $schedules;
		$this->runner    = $runner;
		$this->license   = $license ?? License::unlimited();
	}

	/**
	 * Register the REST routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/schedules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'name'         => array(
							'type'    => 'string',
							'default' => '',
						),
						'filter'       => array(
							'type'    => 'object',
							'default' => array(),
						),
						'actions'      => array(
							'type'     => 'array',
							'required' => true,
						),
						'recurrence'   => array(
							'type'    => 'string',
							'enum'    => array( 'once', 'hourly', 'daily', 'weekly', 'monthly' ),
							'default' => 'once',
						),
						'starts_at'    => array(
							'type'    => 'string',
							'default' => '',
						),
						'notify_email' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/schedules/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		foreach ( array( 'pause', 'resume', 'run' ) as $verb ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/schedules/(?P<id>\d+)/' . $verb,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $verb ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Whether the current user may manage schedules.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * List all schedules.
	 */
	public function index(): WP_REST_Response {
		$items = array_map( array( $this, 'to_array' ), $this->schedules->all() );

		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Create a schedule from a filter + actions template.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		// Scheduling is a paid-plan feature (CONTEXT §5). Gate before any parsing so
		// a free site gets a clean 402 upgrade prompt, mirroring the operation
		// pipeline's License_Limited → 402 mapping.
		if ( ! $this->license->can_schedule() ) {
			return $this->error( 'catalogops_upgrade_required', 'Scheduling is a paid-plan feature.', 402 );
		}

		try {
			$filter  = Filter::from_array( (array) $request->get_param( 'filter' ) );
			$actions = Action_Factory::list_from_array( (array) $request->get_param( 'actions' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		}

		$recurrence = Recurrence::tryFrom( (string) $request->get_param( 'recurrence' ) ) ?? Recurrence::ONCE;
		$next_run   = $this->normalize_gmt( (string) $request->get_param( 'starts_at' ) );
		$email      = sanitize_email( (string) $request->get_param( 'notify_email' ) );

		$id = $this->schedules->create(
			sanitize_text_field( (string) $request->get_param( 'name' ) ),
			$filter,
			$actions,
			Operation_Mode::SAFE,
			$recurrence,
			$next_run,
			$email,
			get_current_user_id()
		);

		return new WP_REST_Response( $this->to_array( $this->schedules->find( $id ) ), 201 );
	}

	/**
	 * Delete a schedule.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->schedules->find( $id ) ) {
			return $this->error( 'catalogops_not_found', 'Schedule not found.', 404 );
		}

		$this->schedules->delete( $id );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Pause a schedule.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause( WP_REST_Request $request ) {
		return $this->transition( $request, Schedule_Status::PAUSED );
	}

	/**
	 * Resume a paused schedule.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume( WP_REST_Request $request ) {
		return $this->transition( $request, Schedule_Status::ACTIVE );
	}

	/**
	 * Fire a schedule now.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->schedules->find( $id ) ) {
			return $this->error( 'catalogops_not_found', 'Schedule not found.', 404 );
		}

		$fired = $this->runner->run_one( $id );

		if ( ! $fired ) {
			return $this->error( 'catalogops_locked', 'Another operation is running; try again shortly.', 409 );
		}

		return new WP_REST_Response( $this->to_array( $this->schedules->find( $id ) ) );
	}

	/**
	 * Move a schedule to a new status if it exists.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param Schedule_Status $status  Target status.
	 * @return WP_REST_Response|WP_Error
	 */
	private function transition( WP_REST_Request $request, Schedule_Status $status ) {
		$id = (int) $request->get_param( 'id' );

		if ( null === $this->schedules->find( $id ) ) {
			return $this->error( 'catalogops_not_found', 'Schedule not found.', 404 );
		}

		$this->schedules->set_status( $id, $status );

		return new WP_REST_Response( $this->to_array( $this->schedules->find( $id ) ) );
	}

	/**
	 * Normalize a client-supplied start time to a GMT MySQL datetime, defaulting
	 * to now when absent or unparseable (fire on the next tick).
	 *
	 * @param string $value The supplied start time.
	 */
	private function normalize_gmt( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return current_time( 'mysql', true );
		}

		$timestamp = strtotime( $value );

		return false === $timestamp ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Shape a schedule for a JSON response.
	 *
	 * @param Schedule $schedule The schedule.
	 * @return array<string, mixed>
	 */
	private function to_array( Schedule $schedule ): array {
		return array(
			'id'           => $schedule->id,
			'name'         => $schedule->name,
			'recurrence'   => $schedule->recurrence->value,
			'status'       => $schedule->status->value,
			'next_run'     => $schedule->next_run,
			'last_run'     => $schedule->last_run,
			'last_op_id'   => $schedule->last_op_id,
			'notify_email' => $schedule->notify_email,
			'mode'         => $schedule->mode->value,
			'filter'       => $schedule->filter_data,
			'actions'      => $schedule->actions_data,
			'created_at'   => $schedule->created_at,
		);
	}

	/**
	 * Build a WP_Error carrying an HTTP status.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
