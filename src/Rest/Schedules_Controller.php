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
use DateTimeZone;
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
	 * Schedules per page, matching the history list beside it.
	 */
	private const PER_PAGE = 10;

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
					'args'                => Paging::args( self::PER_PAGE ),
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
	 * List schedules, newest first, one page at a time.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$slice = Paging::slice( $request, self::PER_PAGE );

		$items = array_map(
			array( $this, 'to_array' ),
			$this->schedules->all( $slice['per_page'], $slice['offset'] )
		);

		return new WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => $this->schedules->count_all(),
				'page'     => $slice['page'],
				'per_page' => $slice['per_page'],
			)
		);
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
	 * The control is `<input type="datetime-local">`, which submits wall-clock time
	 * with no timezone attached — "22:00" means 22:00 as the shop reads a clock.
	 * WordPress runs PHP in UTC, so parsing that string with `strtotime()` would
	 * silently take it as 22:00 UTC and fire the schedule the site's whole offset
	 * away from the hour the user picked. Reading it in the site's timezone is what
	 * makes "overnight" actually mean overnight.
	 *
	 * @param string $value The supplied start time.
	 */
	private function normalize_gmt( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return current_time( 'mysql', true );
		}

		$local = date_create( $value, wp_timezone() );

		if ( false === $local ) {
			return current_time( 'mysql', true );
		}

		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * A GMT datetime rendered in the site's timezone, for display. Times are stored
	 * and compared in GMT — the only sane basis for a scheduler — but a shop owner
	 * reads the clock on the wall, so showing them raw GMT invites exactly the
	 * confusion this pair of methods exists to avoid.
	 *
	 * @param string|null $gmt Stored GMT datetime, or null.
	 */
	private function to_local( ?string $gmt ): ?string {
		return ( null === $gmt || '' === $gmt ) ? $gmt : get_date_from_gmt( $gmt );
	}

	/**
	 * Shape a schedule for a JSON response.
	 *
	 * @param Schedule $schedule The schedule.
	 * @return array<string, mixed>
	 */
	private function to_array( Schedule $schedule ): array {
		return array(
			'id'             => $schedule->id,
			'name'           => $schedule->name,
			'recurrence'     => $schedule->recurrence->value,
			'status'         => $schedule->status->value,
			// Times are stored and compared in GMT; the *_local pair is what the
			// admin table shows, so a shop owner reads their own clock.
			'next_run'       => $schedule->next_run,
			'next_run_local' => $this->to_local( $schedule->next_run ),
			'last_run'       => $schedule->last_run,
			'last_run_local' => $this->to_local( $schedule->last_run ),
			'last_op_id'     => $schedule->last_op_id,
			'notify_email'   => $schedule->notify_email,
			'mode'           => $schedule->mode->value,
			'filter'         => $schedule->filter_data,
			'actions'        => $schedule->actions_data,
			'created_at'     => $schedule->created_at,
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
