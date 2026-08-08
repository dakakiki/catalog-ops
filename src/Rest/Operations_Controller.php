<?php
/**
 * REST endpoints for creating, running, and tracking operations.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Operation;
use CatalogOps\Operations\Operation_Blocked;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Filter;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the write pipeline to the admin app: preview a change, create-and-queue
 * an operation, poll its progress, list recent operations, and cancel a run. The
 * controller stays thin — it validates and shapes payloads and delegates every
 * decision to {@see Operation_Service}.
 */
final class Operations_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * Operation service (pipeline orchestration).
	 *
	 * @var Operation_Service
	 */
	private Operation_Service $service;

	/**
	 * Operations repository (reads for progress and listing).
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Build the controller.
	 *
	 * @param Operation_Service $service    Operation service.
	 * @param Operations        $operations Operations repository.
	 */
	public function __construct( Operation_Service $service, Operations $operations ) {
		$this->service    = $service;
		$this->operations = $operations;
	}

	/**
	 * Register the REST routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		$writable = array(
			'filter'  => array(
				'type'    => 'object',
				'default' => array(),
			),
			'actions' => array(
				'type'     => 'array',
				'required' => true,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $writable + array(
						'mode' => array(
							'type'    => 'string',
							'enum'    => array( 'safe' ),
							'default' => 'safe',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $writable + array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 0,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/(?P<id>\d+)/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Whether the current user may run catalog operations.
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Preview what a filter + actions would change (no writes).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		try {
			$filter  = Filter::from_array( (array) $request->get_param( 'filter' ) );
			$actions = Action_Factory::list_from_array( (array) $request->get_param( 'actions' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		}

		try {
			$preview = $this->service->preview( $filter, $actions, (int) $request->get_param( 'limit' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		}

		return new WP_REST_Response( $preview );
	}

	/**
	 * Create an operation and queue it for execution.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( WP_REST_Request $request ) {
		try {
			$filter  = Filter::from_array( (array) $request->get_param( 'filter' ) );
			$actions = Action_Factory::list_from_array( (array) $request->get_param( 'actions' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		}

		try {
			$op_id = $this->service->create(
				$filter,
				$actions,
				Operation_Mode::SAFE,
				Operation_Source::UI,
				get_current_user_id()
			);

			$this->service->queue( $op_id );
		} catch ( Operation_Blocked $e ) {
			return $this->error( 'catalogops_locked', $e->getMessage(), 409 );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		} catch ( Throwable $e ) {
			return $this->error( 'catalogops_failed', $e->getMessage(), 500 );
		}

		$operation = $this->operations->find( $op_id );

		return new WP_REST_Response( $this->to_array( $operation ), 201 );
	}

	/**
	 * Return one operation's current state (for progress polling).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ) {
		$operation = $this->operations->find( (int) $request->get_param( 'id' ) );

		if ( null === $operation ) {
			return $this->error( 'catalogops_not_found', 'Operation not found.', 404 );
		}

		return new WP_REST_Response( $this->to_array( $operation ) );
	}

	/**
	 * List recent operations.
	 */
	public function index(): WP_REST_Response {
		$operations = array_map(
			array( $this, 'to_array' ),
			$this->operations->recent( 20 )
		);

		return new WP_REST_Response( array( 'items' => $operations ) );
	}

	/**
	 * Cancel a running or queued operation.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel( WP_REST_Request $request ) {
		$id        = (int) $request->get_param( 'id' );
		$operation = $this->operations->find( $id );

		if ( null === $operation ) {
			return $this->error( 'catalogops_not_found', 'Operation not found.', 404 );
		}

		$this->service->cancel( $id );

		return new WP_REST_Response( $this->to_array( $this->operations->find( $id ) ) );
	}

	/**
	 * Shape an operation for a JSON response.
	 *
	 * @param Operation $operation The operation.
	 * @return array<string, mixed>
	 */
	private function to_array( Operation $operation ): array {
		return array(
			'id'           => $operation->id,
			'status'       => $operation->status->value,
			'source'       => $operation->source->value,
			'mode'         => $operation->mode->value,
			'target_count' => $operation->target_count,
			'processed'    => $operation->processed,
			'failed'       => $operation->failed,
			'percent'      => $operation->percent(),
			'created_at'   => $operation->created_at,
			'completed_at' => $operation->completed_at,
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
