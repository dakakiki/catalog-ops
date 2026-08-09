<?php
/**
 * REST endpoints for the read-only query/preview.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
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
					'scope'    => array(
						'type' => 'string',
						'enum' => array( Query_Scope::PRODUCT->value, Query_Scope::VARIATION->value ),
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
		$filter_data = (array) $request->get_param( 'filter' );

		// A top-level scope param (what the UI's Products/Variations toggle sends)
		// overrides any scope inside the filter object.
		$scope = $request->get_param( 'scope' );
		if ( null !== $scope ) {
			$filter_data['scope'] = $scope;
		}

		$filter   = Filter::from_array( $filter_data );
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
				'scope'    => $filter->scope()->value,
				'items'    => $this->rows_for( $page_ids, $filter->scope() ),
			)
		);
	}

	/**
	 * Fetch display columns for a page of IDs, in the given order. For variations
	 * the post has no title of its own, so the parent's title is joined in and the
	 * variation's attribute summary is read, giving the table a meaningful label
	 * (CONTEXT §4). Only the page's rows are touched, never the whole result.
	 *
	 * @param int[]       $ids   IDs for this page.
	 * @param Query_Scope $scope The object type these IDs are.
	 * @return list<array<string, mixed>>
	 */
	private function rows_for( array $ids, Query_Scope $scope ): array {
		if ( array() === $ids ) {
			return array();
		}

		$lookup       = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts        = $this->wpdb->posts;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		$is_variation = $scope->is_variation();
		$name_select  = $is_variation ? 'parent.post_title AS name, p.post_parent AS parent_id' : 'p.post_title AS name, 0 AS parent_id';
		$parent_join  = $is_variation ? "LEFT JOIN {$posts} parent ON parent.ID = p.post_parent" : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.ID, {$name_select}, l.sku, l.min_price, l.max_price, l.stock_status, l.stock_quantity
				FROM {$lookup} l
				INNER JOIN {$posts} p ON p.ID = l.product_id
				{$parent_join}
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

		$attributes = $is_variation ? $this->variation_attributes( $ids ) : array();

		$items = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}

			$row  = $by_id[ $id ];
			$name = (string) $row['name'];
			if ( $is_variation && isset( $attributes[ $id ] ) ) {
				$name = '' === $name ? $attributes[ $id ] : $name . ' — ' . $attributes[ $id ];
			}

			$items[] = array(
				'id'             => (int) $row['ID'],
				'name'           => $name,
				'parent_id'      => (int) $row['parent_id'],
				'sku'            => (string) $row['sku'],
				'price'          => $row['min_price'],
				'max_price'      => $row['max_price'],
				'stock_status'   => (string) $row['stock_status'],
				'stock_quantity' => null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'],
			);
		}

		return $items;
	}

	/**
	 * A short attribute summary per variation (e.g. "large, red"), read from the
	 * variations' `attribute_*` post meta in one pass over the page's ids.
	 *
	 * @param int[] $ids Variation ids for this page.
	 * @return array<int, string> Variation id => comma-separated attribute values.
	 */
	private function variation_attributes( array $ids ): array {
		$postmeta     = $this->wpdb->postmeta;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$like         = $this->wpdb->esc_like( 'attribute_' ) . '%';
		$args         = array( ...$ids, $like );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT post_id, meta_value
				FROM {$postmeta}
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
}
