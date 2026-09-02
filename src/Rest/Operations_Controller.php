<?php
/**
 * REST endpoints for creating, running, and tracking operations.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Change;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Conflict_Policy;
use CatalogOps\Operations\Operation;
use CatalogOps\Operations\Operation_Blocked;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operations;
use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Query\Filter;
use InvalidArgumentException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;

/**
 * Exposes the write pipeline to the admin app: preview a change, create-and-queue
 * an operation, poll its progress, list recent operations, and cancel a run. The
 * controller stays thin — it validates and shapes payloads and delegates every
 * decision to {@see Operation_Service}.
 */
final class Operations_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * Operations per page in the history list. Ten fills the panel without
	 * pushing everything below it off the screen.
	 */
	private const HISTORY_PER_PAGE = 10;

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
	 * Changes repository (audit-log detail and change counts).
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * WordPress database handle (audit rows are enriched with SKU and name).
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Plan gating (undo is a paid-plan feature).
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * Build the controller.
	 *
	 * @param Operation_Service $service    Operation service.
	 * @param Operations        $operations Operations repository.
	 * @param Changes           $changes    Changes repository.
	 * @param wpdb              $wpdb       WordPress database handle.
	 * @param License|null      $license    Plan gating; defaults to unlimited
	 *                                      (unlicensed development and tests).
	 */
	public function __construct( Operation_Service $service, Operations $operations, Changes $changes, wpdb $wpdb, ?License $license = null ) {
		$this->service    = $service;
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->wpdb       = $wpdb;
		$this->license    = $license ?? License::unlimited();
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
					'args'                => Paging::args( self::HISTORY_PER_PAGE ),
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
					// Narrows the worked-out sample to one product. The counts
					// still describe the whole edit.
					'sku' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
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

		$policy_arg = array(
			'conflict_policy' => array(
				'type'    => 'string',
				'enum'    => array( Conflict_Policy::SKIP->value, Conflict_Policy::FORCE->value ),
				'default' => Conflict_Policy::SKIP->value,
			),
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/(?P<id>\d+)/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $policy_arg,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/operations/(?P<id>\d+)/undo/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo_preview' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $policy_arg + array(
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
			'/operations/(?P<id>\d+)/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'changes' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'object_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'sku'       => array(
						'type'    => 'string',
						'default' => '',
					),
				),
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
			$preview = $this->service->preview( $filter, $actions, (string) $request->get_param( 'sku' ) );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		}

		// The service answers in ids; the panel shows people a product. Identity is
		// resolved here, with the same lookup the audit view uses, so a preview row
		// and the history row it becomes read identically.
		$identities = $this->identify(
			array_map( static fn( array $row ): int => $row['id'], $preview['sample'] )
		);

		$preview['sample'] = array_map(
			static function ( array $row ) use ( $identities ): array {
				$identity = $identities[ $row['id'] ] ?? array(
					'sku'         => '',
					'name'        => '',
					'object_type' => 'product',
				);

				return $row + $identity;
			},
			$preview['sample']
		);

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
		} catch ( License_Limited $e ) {
			return $this->error( 'catalogops_upgrade_required', $e->getMessage(), 402 );
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

		$body = $this->to_array( $operation );

		// The reason breakdown is what turns a finished run's "N skipped" into
		// something a user can act on, so the result bar gets it as soon as the
		// operation settles. Only then: while it is still running the counts are
		// half-formed, and a grouped scan of the operation's rows on every 1.5s poll
		// would be paid for nothing.
		if ( ! $operation->status->is_active() ) {
			$body['skip_reasons'] = $this->changes->skip_reasons( $operation->id );
		}

		return new WP_REST_Response( $body );
	}

	/**
	 * List recent operations, newest first, one page at a time.
	 *
	 * The list used to answer with the newest twenty and no hint that there were
	 * more, which is the same as losing them. It now carries the whole count, so
	 * the history can be walked back as far as retention keeps it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$slice = Paging::slice( $request, self::HISTORY_PER_PAGE );

		$operations = array_map(
			array( $this, 'to_array' ),
			$this->operations->recent( $slice['per_page'], $slice['offset'] )
		);

		return new WP_REST_Response(
			array(
				'items'    => $operations,
				'total'    => $this->operations->count_all(),
				'page'     => $slice['page'],
				'per_page' => $slice['per_page'],
			)
		);
	}

	/**
	 * Remove an operation and its recorded deltas from the history.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		try {
			$this->service->delete( $id );
		} catch ( Operation_Blocked $e ) {
			return $this->error( 'catalogops_locked', $e->getMessage(), 409 );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_not_found', $e->getMessage(), 404 );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
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
	 * Preview undoing an operation: how many changes would be reverted and a
	 * sample flagging which objects have drifted (CONTEXT §3).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo_preview( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		try {
			$preview = $this->service->preview_undo(
				$id,
				$this->policy_param( $request ),
				(int) $request->get_param( 'limit' )
			);
		} catch ( License_Limited $e ) {
			return $this->error( 'catalogops_upgrade_required', $e->getMessage(), 402 );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_not_found', $e->getMessage(), 404 );
		}

		return new WP_REST_Response( $preview );
	}

	/**
	 * Undo an operation: create and queue the inverse operation (CONTEXT §2).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		try {
			$undo_id = $this->service->undo( $id, $this->policy_param( $request ), get_current_user_id() );
			$this->service->queue( $undo_id );
		} catch ( Operation_Blocked $e ) {
			return $this->error( 'catalogops_locked', $e->getMessage(), 409 );
		} catch ( License_Limited $e ) {
			return $this->error( 'catalogops_upgrade_required', $e->getMessage(), 402 );
		} catch ( InvalidArgumentException $e ) {
			return $this->error( 'catalogops_invalid_request', $e->getMessage(), 400 );
		} catch ( Throwable $e ) {
			return $this->error( 'catalogops_failed', $e->getMessage(), 500 );
		}

		return new WP_REST_Response( $this->to_array( $this->operations->find( $undo_id ) ), 201 );
	}

	/**
	 * A page of an operation's recorded deltas — the audit-log detail view.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function changes( WP_REST_Request $request ) {
		$id        = (int) $request->get_param( 'id' );
		$operation = $this->operations->find( $id );

		if ( null === $operation ) {
			return $this->error( 'catalogops_not_found', 'Operation not found.', 404 );
		}

		$page      = max( 1, (int) $request->get_param( 'page' ) );
		$per_page  = (int) $request->get_param( 'per_page' );
		$object_id = max( 0, (int) $request->get_param( 'object_id' ) );
		$sku       = trim( (string) $request->get_param( 'sku' ) );
		$offset    = ( $page - 1 ) * $per_page;

		$changes    = $this->changes->page( $id, $per_page, $offset, $object_id, $sku );
		$identities = $this->identify( array_map( static fn( Change $c ): int => $c->object_id, $changes ) );

		$rows = array_map(
			fn( Change $c ): array => $this->change_to_array( $c, $identities ),
			$changes
		);

		return new WP_REST_Response(
			array(
				'items'        => $rows,
				'counts'       => $this->changes->counts( $id ),
				'skip_reasons' => $this->changes->skip_reasons( $id ),
				'total'        => $this->changes->count_page( $id, $object_id, $sku ),
				'page'         => $page,
			)
		);
	}

	/**
	 * Resolve a set of changed object ids to a human identity — SKU and a label —
	 * so the audit view shows "COPS-1234 · Shirt (Large)" instead of a raw post id
	 * that means nothing to the user. A variation borrows its parent's SKU (its
	 * own is usually blank) and shows the parent's name plus its attribute values.
	 *
	 * @param int[] $ids Changed object ids on the current page.
	 * @return array<int, array{sku: string, name: string, object_type: string}>
	 */
	private function identify( array $ids ): array {
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$lookup       = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts        = $this->wpdb->posts;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_title, p.post_parent,
					l.sku AS sku, parent.post_title AS parent_title, pl.sku AS parent_sku
				FROM {$posts} p
				LEFT JOIN {$lookup} l ON l.product_id = p.ID
				LEFT JOIN {$posts} parent ON parent.ID = p.post_parent
				LEFT JOIN {$lookup} pl ON pl.product_id = p.post_parent
				WHERE p.ID IN ( {$placeholders} )",
				...$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$variation_ids = array();
		foreach ( $rows as $row ) {
			if ( 'product_variation' === $row['post_type'] ) {
				$variation_ids[] = (int) $row['ID'];
			}
		}
		$attributes = $this->attribute_summaries( $variation_ids );

		$identities = array();
		foreach ( $rows as $row ) {
			$id           = (int) $row['ID'];
			$is_variation = 'product_variation' === $row['post_type'];
			$sku          = (string) $row['sku'];
			$name         = (string) $row['post_title'];

			if ( $is_variation ) {
				$sku  = '' !== $sku ? $sku : (string) $row['parent_sku'];
				$name = (string) $row['parent_title'];
				if ( isset( $attributes[ $id ] ) ) {
					$name = '' === $name ? $attributes[ $id ] : $name . ' (' . $attributes[ $id ] . ')';
				}
			}

			$identities[ $id ] = array(
				'sku'         => $sku,
				'name'        => $name,
				'object_type' => (string) $row['post_type'],
			);
		}

		return $identities;
	}

	/**
	 * A short attribute summary per variation, from its `attribute_*` post meta.
	 *
	 * @param int[] $ids Variation ids.
	 * @return array<int, string> Variation id => comma-separated attribute values.
	 */
	private function attribute_summaries( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$postmeta     = $this->wpdb->postmeta;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$like         = $this->wpdb->esc_like( 'attribute_' ) . '%';
		$args         = array( ...$ids, $like );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT post_id, meta_value FROM {$postmeta}
				WHERE post_id IN ( {$placeholders} ) AND meta_key LIKE %s AND meta_value <> ''
				ORDER BY meta_key ASC",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$summary = array();
		foreach ( $rows as $row ) {
			$id             = (int) $row['post_id'];
			$summary[ $id ] = isset( $summary[ $id ] ) ? $summary[ $id ] . ', ' . $row['meta_value'] : (string) $row['meta_value'];
		}

		return $summary;
	}

	/**
	 * The conflict policy carried by a request, defaulting to skip (CONTEXT §3).
	 *
	 * @param WP_REST_Request $request The request.
	 */
	private function policy_param( WP_REST_Request $request ): Conflict_Policy {
		return Conflict_Policy::tryFrom( (string) $request->get_param( 'conflict_policy' ) )
			?? Conflict_Policy::safe_default();
	}

	/**
	 * Shape an operation for a JSON response.
	 *
	 * @param Operation $operation The operation.
	 * @return array<string, mixed>
	 */
	private function to_array( Operation $operation ): array {
		return array(
			'id'              => $operation->id,
			'status'          => $operation->status->value,
			'source'          => $operation->source->value,
			'mode'            => $operation->mode->value,
			'parent_op_id'    => $operation->parent_op_id,
			'conflict_policy' => null === $operation->conflict_policy ? null : $operation->conflict_policy->value,
			'user_id'         => $operation->user_id,
			'user_name'       => $this->user_name( $operation->user_id ),
			'target_count'    => $operation->target_count,
			'processed'       => $operation->processed,
			'failed'          => $operation->failed,
			'percent'         => $operation->percent(),
			'can_undo'        => $this->can_undo( $operation ),
			'created_at'      => $operation->created_at,
			'completed_at'    => $operation->completed_at,
		);
	}

	/**
	 * Shape one change row for the audit view, enriched with the object's SKU and
	 * name so the row is legible without knowing internal ids.
	 *
	 * @param Change                                                            $change     The change.
	 * @param array<int, array{sku: string, name: string, object_type: string}> $identities Identity map by object id.
	 * @return array<string, mixed>
	 */
	private function change_to_array( Change $change, array $identities ): array {
		$labels = array(
			0 => 'pending',
			1 => 'applied',
			2 => 'failed',
			3 => 'skipped',
		);

		$identity = $identities[ $change->object_id ] ?? array(
			'sku'  => '',
			'name' => '',
		);

		return array(
			'object_id'   => $change->object_id,
			'object_type' => $change->object_type,
			'sku'         => $identity['sku'],
			'name'        => $identity['name'],
			'field_type'  => $change->field_type->value,
			'field_key'   => $change->field_key,
			'old_value'   => $change->old_value,
			'new_value'   => $change->new_value,
			'status'      => $labels[ $change->status->value ] ?? 'pending',
			'skip_reason' => null === $change->skip_reason ? '' : $change->skip_reason->value,
		);
	}

	/**
	 * Whether the admin app should offer an undo control for this operation: the
	 * plan permits undo, and the operation made changes and is not currently
	 * running. Gating the flag here means the free tier's Undo button simply never
	 * appears, matching the REST layer's 402 on the undo endpoint.
	 *
	 * @param Operation $operation The operation.
	 */
	private function can_undo( Operation $operation ): bool {
		return $this->license->can_undo() && ! $operation->status->is_active() && $operation->processed > 0;
	}

	/**
	 * A display name for the user who ran an operation, for the audit log.
	 *
	 * @param int $user_id User id.
	 */
	private function user_name( int $user_id ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return false === $user ? '' : $user->display_name;
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
