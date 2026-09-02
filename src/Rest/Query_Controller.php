<?php
/**
 * REST endpoints for the read-only query/preview.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Operations\Formula\Variables;
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
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'scope'       => $filter->scope()->value,
				'items'       => $this->rows_for( $page_ids, $filter->scope() ),
				'other_scope' => $this->other_scope( $filter, $total ),
			)
		);
	}

	/**
	 * How many objects the same filter would match in the other scope, when this
	 * one matched nothing.
	 *
	 * An empty result is the moment the Products/Variations distinction actually
	 * bites: a variable product keeps its price, stock and SKU on its variations,
	 * so a price or stock filter over parents sails past every variable product in
	 * the catalogue and reports nothing found (CONTEXT §4). The count is what turns
	 * "no products match this filter" from a dead end into a signpost, and the UI
	 * offers the switch rather than describing it.
	 *
	 * Only asked when the answer can be acted on — the result was empty — so the
	 * extra count never rides along with a query that already found something.
	 *
	 * @param Filter $filter The filter as asked.
	 * @param int    $total  What it matched in its own scope.
	 * @return array{scope: string, total: int}|null Null when there is nothing to suggest.
	 */
	private function other_scope( Filter $filter, int $total ): ?array {
		if ( $total > 0 ) {
			return null;
		}

		$other = $filter->scope()->other();
		$count = $this->engine->count( $filter->for_scope( $other ) );

		if ( 0 === $count ) {
			// Nothing there either: the filter is simply too narrow, and pointing
			// at an equally empty scope would only add noise.
			return null;
		}

		return array(
			'scope' => $other->value,
			'total' => $count,
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

		// A variation's own SKU is usually blank — shops give one to the product,
		// not to each size — and SKU leads the table, so it borrows the parent's
		// rather than leaving the identifying column empty on every row. This is
		// what the audit view already does ({@see Operations_Controller::identify()}).
		$sku_select  = $is_variation ? "COALESCE( NULLIF( l.sku, '' ), pl.sku, '' ) AS sku" : 'l.sku';
		$parent_join = $is_variation
			? "LEFT JOIN {$posts} parent ON parent.ID = p.post_parent
				LEFT JOIN {$lookup} pl ON pl.product_id = p.post_parent"
			: '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.ID, {$name_select}, {$sku_select}, l.min_price, l.max_price, l.stock_status, l.stock_quantity
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
		$extra      = $this->meta_columns( $ids );

		// Terms hang off the parent product, which is also how the filter matches
		// them, so under the variation scope the tags to show are the parent's.
		$tag_owners = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$tag_owners[ $id ] = $is_variation ? (int) $by_id[ $id ]['parent_id'] : $id;
			}
		}

		$tags = $this->tags( $tag_owners );

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
				'brand'          => $extra[ $id ]['brand'] ?? null,
				'tags'           => $tags[ $id ] ?? array(),
				'price'          => $row['min_price'],
				'max_price'      => $row['max_price'],
				'sale_price'     => $extra[ $id ]['sale_price'] ?? null,
				'cost'           => $extra[ $id ]['cost'] ?? null,
				'stock_status'   => (string) $row['stock_status'],
				'stock_quantity' => null === $row['stock_quantity'] ? null : (int) $row['stock_quantity'],
			);
		}

		return $items;
	}

	/**
	 * The product tags for a page of objects, in one pass.
	 *
	 * Joining `term_taxonomy` here is safe, unlike inside the filter's membership
	 * subquery where it is the thing that mis-plans (see
	 * {@see \CatalogOps\Query\Query_Engine::taxonomy_clause()}): this reads at most
	 * a page of ids straight off the primary index, with no filter to re-plan
	 * around.
	 *
	 * @param array<int, int> $owners Row id => the object whose terms to read.
	 * @return array<int, list<string>> Row id => tag names.
	 */
	private function tags( array $owners ): array {
		$unique = array_values( array_unique( array_filter( $owners ) ) );

		if ( array() === $unique ) {
			return array();
		}

		$relationships = $this->wpdb->term_relationships;
		$taxonomy      = $this->wpdb->term_taxonomy;
		$terms         = $this->wpdb->terms;
		$placeholders  = implode( ', ', array_fill( 0, count( $unique ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT tr.object_id, t.name
				FROM {$relationships} tr
				INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_tag'
				INNER JOIN {$terms} t ON t.term_id = tt.term_id
				WHERE tr.object_id IN ( {$placeholders} )
				ORDER BY t.name ASC",
				...$unique
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$by_owner = array();
		foreach ( $rows as $row ) {
			$by_owner[ (int) $row['object_id'] ][] = (string) $row['name'];
		}

		$by_row = array();
		foreach ( $owners as $id => $owner ) {
			$by_row[ $id ] = $by_owner[ $owner ] ?? array();
		}

		return $by_row;
	}

	/**
	 * Read the sale price and cost for a page of ids in one pass, so the table can
	 * show whether those fields carry data (and thus whether a formula reading
	 * `sale_price` or `cost` would have something to work with). Sale price lives in
	 * `_sale_price`; cost in the filterable cost meta key the `cost` formula
	 * variable reads (default `_catalogops_cost`). Empty strings are normalized to
	 * null so the UI can render a clear blank. No product objects are loaded (§9).
	 *
	 * Brand rides along in the same pass: it is filterable, so leaving it out of
	 * the table meant filtering by something the results would not show.
	 *
	 * @param int[] $ids Ids for this page.
	 * @return array<int, array{sale_price: ?string, cost: ?string, brand: ?string}>
	 */
	private function meta_columns( array $ids ): array {
		$cost_key = (string) apply_filters( 'catalogops_cost_meta_key', Variables::DEFAULT_COST_META_KEY );
		if ( '' === $cost_key ) {
			$cost_key = Variables::DEFAULT_COST_META_KEY;
		}

		/** This filter is documented in src/Rest/Fields_Controller.php */
		$brand_key = (string) apply_filters( 'catalogops_brand_meta_key', '_catalogops_brand' );

		$postmeta     = $this->wpdb->postmeta;
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array( ...$ids, '_sale_price', $cost_key, $brand_key );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT post_id, meta_key, meta_value
				FROM {$postmeta}
				WHERE post_id IN ( {$placeholders} ) AND meta_key IN ( %s, %s, %s )",
				...$args
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( $rows as $row ) {
			$id    = (int) $row['post_id'];
			$value = '' === $row['meta_value'] ? null : (string) $row['meta_value'];

			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'sale_price' => null,
					'cost'       => null,
					'brand'      => null,
				);
			}

			if ( '_sale_price' === $row['meta_key'] ) {
				$out[ $id ]['sale_price'] = $value;
			} elseif ( $cost_key === $row['meta_key'] ) {
				$out[ $id ]['cost'] = $value;
			} elseif ( $brand_key === $row['meta_key'] ) {
				$out[ $id ]['brand'] = $value;
			}
		}

		return $out;
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
