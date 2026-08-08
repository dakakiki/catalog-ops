<?php
/**
 * REST endpoints for the read-only query/preview.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use wpdb;

/**
 * Exposes the query engine to the admin app. The engine resolves a filter to a
 * frozen list of IDs; this controller paginates that list and returns just the
 * columns the table renders, preserving the frozen order.
 */
final class Query_Controller {

	private const REST_NAMESPACE = 'catalogops/v1';

	/**
	 * Query engine.
	 *
	 * @var Query_Engine
	 */
	private Query_Engine $engine;

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Build the controller.
	 *
	 * @param Query_Engine $engine Query engine.
	 * @param wpdb         $wpdb   WordPress database handle.
	 */
	public function __construct( Query_Engine $engine, wpdb $wpdb ) {
		$this->engine = $engine;
		$this->wpdb   = $wpdb;
	}

	/**
	 * Register the REST routes. Hook to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/products/query',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'query' ),
				'permission_callback' => array( $this, 'can_query' ),
				'args'                => array(
					'filter'   => array(
						'type'    => 'object',
						'default' => array(),
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);
	}

	/**
	 * Whether the current user may run catalog queries.
	 */
	public function can_query(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Resolve a filter and return one page of matching products.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function query( WP_REST_Request $request ): WP_REST_Response {
		$filter   = Filter::from_array( (array) $request->get_param( 'filter' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$ids      = $this->engine->resolve( $filter );
		$total    = count( $ids );
		$page_ids = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );

		return new WP_REST_Response(
			array(
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'items'    => $this->rows_for( $page_ids ),
			)
		);
	}

	/**
	 * Fetch display columns for a page of product IDs, in the given order.
	 *
	 * @param int[] $ids Product IDs for this page.
	 * @return list<array<string, mixed>>
	 */
	private function rows_for( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$lookup       = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts        = $this->wpdb->posts;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.ID, p.post_title, l.sku, l.min_price, l.max_price, l.stock_status, l.stock_quantity
				FROM {$lookup} l
				INNER JOIN {$posts} p ON p.ID = l.product_id
				WHERE l.product_id IN ( {$placeholders} )",
				...$ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['ID'] ] = $row;
		}

		$items = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}

			$row     = $by_id[ $id ];
			$items[] = array(
				'id'             => (int) $row['ID'],
				'name'           => (string) $row['post_title'],
				'sku'            => (string) $row['sku'],
				'price'          => $row['min_price'],
				'max_price'      => $row['max_price'],
				'stock_status'   => (string) $row['stock_status'],
				'stock_quantity' => null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'],
			);
		}

		return $items;
	}
}
