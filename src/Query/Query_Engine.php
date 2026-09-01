<?php
/**
 * Resolves a filter to a frozen list of product IDs.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

use CatalogOps\Query\Requirements\Requirement;
use wpdb;

/**
 * The read-only heart of M1. Turns a {@see Filter} into product IDs with a
 * single SELECT: numeric and stock conditions read straight off
 * wc_product_meta_lookup (which WooCommerce keeps indexed), and taxonomy/meta
 * conditions become correlated EXISTS subqueries rather than fan-out JOINs.
 *
 * Only `SELECT product_id` ever leaves the database — no product objects are
 * loaded (CONTEXT §9). The list it returns is meant to be frozen once and then
 * operated on, never re-queried mid-operation (CONTEXT §2).
 */
final class Query_Engine {

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Build the engine over a database handle.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Resolve a filter to the matching product IDs, ascending.
	 *
	 * @param Filter        $filter       The filter to resolve.
	 * @param Requirement[] $requirements Applicability constraints every match must
	 *                                    satisfy — the objects an edit can actually
	 *                                    change, so ones it would only skip are
	 *                                    excluded up front.
	 * @return list<int>
	 */
	public function resolve( Filter $filter, array $requirements = array() ): array {
		$sql = $this->select( 'l.product_id', $filter, $requirements ) . ' ORDER BY l.product_id ASC';

		// $sql is assembled in select() from trusted identifiers with all values
		// bound via $wpdb->prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $this->wpdb->get_col( $sql ) );
	}

	/**
	 * Count the products matching a filter without materializing the IDs.
	 *
	 * @param Filter        $filter       The filter to count.
	 * @param Requirement[] $requirements Applicability constraints (see {@see resolve()}).
	 */
	public function count( Filter $filter, array $requirements = array() ): int {
		// select() returns a prepared statement built from trusted identifiers.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $this->select( 'COUNT(*)', $filter, $requirements ) );
	}

	/**
	 * Build the full, prepared SELECT for a projection and filter. The scope
	 * decides the post type (parent products or their variations); a variation
	 * carries its own price, stock, sku, and meta, but inherits its category from
	 * the parent, and its chosen attribute value lives on the variation itself
	 * (CONTEXT §4).
	 *
	 * @param string        $projection   The select list, e.g. `l.product_id`.
	 * @param Filter        $filter       The filter to translate.
	 * @param Requirement[] $requirements Applicability constraints on a match.
	 */
	private function select( string $projection, Filter $filter, array $requirements = array() ): string {
		$lookup   = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts    = $this->wpdb->posts;
		$postmeta = $this->wpdb->postmeta;
		$scope    = $filter->scope();

		// Set membership is joined, not tested in the WHERE clause.
		//
		// Every `l.product_id IN (SELECT …)` is an invitation for MySQL to re-plan
		// the whole query around it, and with two of them present it eventually
		// takes the invitation: measured on an 18.5k-product catalogue, filtering by
		// category held its plan up to a point and then flipped — the meta lookup
		// stopped driving, `posts` lost its primary-key path for a range scan with a
		// block-nested-loop join, and a one-second query became four minutes and
		// then a fatal. The tipping point moved with how many rows the optimiser
		// estimated, which is why it looked like "it breaks past ten thousand".
		//
		// Joining says the same thing in a shape with no such freedom, and it is the
		// only form that stayed stable across every selection measured, all the way
		// to every category at once.
		$joins     = array();
		$join_args = array();

		// Product scope targets simple, priced products. A variable product keeps
		// its price on its variations — its own _regular_price is empty — so it
		// belongs to the variation scope, not here. Requiring a regular price
		// excludes those parents (and any unpriced product), so a Product-scope
		// price edit never silently skips what it cannot compute (CONTEXT §4).
		if ( ! $scope->is_variation() ) {
			$joins[] = "INNER JOIN {$postmeta} co_price
				ON co_price.post_id = l.product_id
				AND co_price.meta_key = '_regular_price'
				AND co_price.meta_value <> ''";
		}

		list( $where, $where_args, $condition_joins, $condition_join_args ) = $this->build_where( $filter );

		$joins     = array( ...$joins, ...$condition_joins );
		$join_args = array( ...$join_args, ...$condition_join_args );

		// After the posts join, not before: under the variation scope a membership
		// join matches on the parent (p.post_parent), which does not exist yet.
		$sql = "SELECT {$projection}
			FROM {$lookup} l
			INNER JOIN {$posts} p ON p.ID = l.product_id
			" . implode( "\n\t\t\t", $joins ) . "
			WHERE p.post_type = %s AND p.post_status = 'publish'";

		// Placeholders bind in the order they appear in the statement, so the join
		// arguments come first — they sit in the FROM clause, ahead of the WHERE.
		$args = array( ...$join_args, $scope->post_type() );

		// Applicability: keep only the objects the edit can actually change — ones
		// carrying every field it reads, and ones whose new value WooCommerce will
		// keep rather than silently override on save. Excluding them here is what
		// makes the previewed count equal the applied count (CONTEXT §2); each
		// requirement carries the reason its exclusions are reported under.
		foreach ( $requirements as $requirement ) {
			list( $fragment, $fragment_args ) = $requirement->sql( $this->wpdb, $scope );

			if ( '' === $fragment ) {
				continue;
			}

			$sql .= ' AND ' . $fragment;
			$args = array( ...$args, ...$fragment_args );
		}

		if ( '' !== $where ) {
			$sql .= ' AND ' . $where;
			$args = array( ...$args, ...$where_args );
		}

		// Identifiers ($projection, table names) are trusted; every value —
		// including the post type — is a placeholder resolved here by prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Translate a filter's conditions into a WHERE fragment, plus any joins the
	 * clauses would rather be expressed as.
	 *
	 * A join is an AND by construction, so a clause may only contribute one when
	 * the filter's conditions are ANDed together. Under OR every clause stays in
	 * the WHERE, where it can be combined with the others truthfully — the shape is
	 * slower, but an OR filter that returns the wrong products would be worse.
	 *
	 * @param Filter $filter The filter.
	 * @return array{0: string, 1: list<mixed>, 2: list<string>, 3: list<mixed>}
	 */
	private function build_where( Filter $filter ): array {
		$fragments = array();
		$args      = array();
		$joins     = array();
		$join_args = array();
		$joinable  = Filter::RELATION_OR !== $filter->relation();
		$index     = 0;

		foreach ( $filter->conditions() as $condition ) {
			$clause = $this->clause_for( $condition, $filter->scope(), $joinable ? $index : null );
			++$index;

			list( $fragment, $fragment_args ) = $clause;
			$join                             = $clause[2] ?? '';

			if ( '' !== $join ) {
				$joins[]   = $join;
				$join_args = array( ...$join_args, ...( $clause[3] ?? array() ) );
			}

			if ( '' === $fragment ) {
				continue;
			}

			$fragments[] = $fragment;
			$args        = array( ...$args, ...$fragment_args );
		}

		if ( array() === $fragments ) {
			return array( '', array(), $joins, $join_args );
		}

		$glue = Filter::RELATION_OR === $filter->relation() ? ' OR ' : ' AND ';

		return array( '( ' . implode( $glue, $fragments ) . ' )', $args, $joins, $join_args );
	}

	/**
	 * Dispatch a condition to the right clause builder based on its field and the
	 * query scope.
	 *
	 * @param Condition   $condition The condition.
	 * @param Query_Scope $scope     The object type being queried.
	 * @param int|null    $join_slot A number unique to this condition, used to name
	 *                               a join alias — or null when the filter's shape
	 *                               forbids joins (an OR relation), in which case
	 *                               the clause must stay in the WHERE.
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 */
	private function clause_for( Condition $condition, Query_Scope $scope, ?int $join_slot = null ): array {
		$field = $condition->field;

		if ( 'price' === $field ) {
			return $this->numeric_clause( 'l.min_price', '%f', $condition );
		}

		if ( 'stock_quantity' === $field ) {
			return $this->numeric_clause( 'l.stock_quantity', '%d', $condition );
		}

		if ( 'stock_status' === $field ) {
			return $this->string_clause( 'l.stock_status', $condition );
		}

		if ( 'sku' === $field ) {
			return $this->string_clause( 'l.sku', $condition );
		}

		if ( 'category' === $field ) {
			// A variation inherits its category from the parent product.
			return $this->taxonomy_clause( 'product_cat', $condition, $scope, $join_slot );
		}

		if ( 'tag' === $field ) {
			// Like category, a variation inherits its tags from the parent product.
			return $this->taxonomy_clause( 'product_tag', $condition, $scope, $join_slot );
		}

		if ( str_starts_with( $field, 'attribute:' ) ) {
			$taxonomy = substr( $field, strlen( 'attribute:' ) );

			// On a variation the chosen attribute value is stored on the variation
			// itself, not as a parent taxonomy term.
			return $scope->is_variation()
				? $this->variation_attribute_clause( $taxonomy, $condition )
				: $this->taxonomy_clause( $taxonomy, $condition, $scope, $join_slot );
		}

		if ( str_starts_with( $field, 'meta:' ) ) {
			return $this->meta_clause( substr( $field, strlen( 'meta:' ) ), $condition );
		}

		// Unknown field: ignore rather than produce broken SQL.
		return array( '', array() );
	}

	/**
	 * Numeric comparison against a lookup column.
	 *
	 * @param string    $column    Trusted column expression, e.g. `l.min_price`.
	 * @param string    $format    Placeholder for values: `%f` or `%d`.
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function numeric_clause( string $column, string $format, Condition $condition ): array {
		$operator = $condition->operator;

		$simple = array(
			Operator::EQUALS->name           => '=',
			Operator::NOT_EQUALS->name       => '!=',
			Operator::GREATER_THAN->name     => '>',
			Operator::GREATER_OR_EQUAL->name => '>=',
			Operator::LESS_THAN->name        => '<',
			Operator::LESS_OR_EQUAL->name    => '<=',
		);

		if ( isset( $simple[ $operator->name ] ) ) {
			return array( "{$column} {$simple[ $operator->name ]} {$format}", array( $condition->value ) );
		}

		if ( Operator::BETWEEN === $operator ) {
			$range = array_values( (array) $condition->value );
			if ( count( $range ) < 2 ) {
				return array( '', array() );
			}

			return array( "{$column} BETWEEN {$format} AND {$format}", array( $range[0], $range[1] ) );
		}

		if ( Operator::IN === $operator || Operator::NOT_IN === $operator ) {
			$values = array_values( (array) $condition->value );
			if ( array() === $values ) {
				return array( '', array() );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), $format ) );
			$keyword      = Operator::NOT_IN === $operator ? 'NOT IN' : 'IN';

			return array( "{$column} {$keyword} ( {$placeholders} )", $values );
		}

		return array( '', array() );
	}

	/**
	 * String comparison against a lookup column.
	 *
	 * @param string    $column    Trusted column expression, e.g. `l.stock_status`.
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function string_clause( string $column, Condition $condition ): array {
		$operator = $condition->operator;

		if ( Operator::EQUALS === $operator || Operator::NOT_EQUALS === $operator ) {
			$comparison = Operator::NOT_EQUALS === $operator ? '!=' : '=';

			return array( "{$column} {$comparison} %s", array( (string) $condition->value ) );
		}

		if ( Operator::CONTAINS === $operator ) {
			// Substring search, e.g. the SKU box in the admin table.
			return array( "{$column} LIKE %s", array( '%' . $this->wpdb->esc_like( (string) $condition->value ) . '%' ) );
		}

		if ( Operator::IN === $operator || Operator::NOT_IN === $operator ) {
			$values = array_map( 'strval', array_values( (array) $condition->value ) );
			if ( array() === $values ) {
				return array( '', array() );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$keyword      = Operator::NOT_IN === $operator ? 'NOT IN' : 'IN';

			return array( "{$column} {$keyword} ( {$placeholders} )", $values );
		}

		return array( '', array() );
	}

	/**
	 * Membership in a taxonomy's terms. Terms are attached to the parent product,
	 * so under the variation scope the match is on the variation's parent
	 * (p.post_parent) rather than the variation itself.
	 *
	 * Two forms:
	 *   - IN / NOT_IN with a list of term ids — has (or lacks) one of those terms.
	 *   - EXISTS / NOT_EXISTS with no value — has (or lacks) any term of the
	 *     taxonomy at all, i.e. "filter by this attribute, any value" (M5 filter UI).
	 *
	 * Positive membership is expressed as `{object} IN (SELECT object_id …)` rather
	 * than a correlated `EXISTS`: the subquery selects the (usually small) set of
	 * objects carrying the term, which MySQL can materialise and drive the whole
	 * query from. A correlated EXISTS instead makes the optimiser scan every
	 * published product and test each one — on a large catalogue with several
	 * conditions that mis-planned into a full scan and timed out (CONTEXT §3/§9).
	 * The negative forms stay as NOT EXISTS: an anti-join is both the right shape
	 * and free of NOT IN's NULL pitfalls.
	 *
	 * **The subquery reads one table.** term_relationships is keyed by
	 * term_taxonomy_id, not term_id, so asking it for terms means joining
	 * term_taxonomy inside the subquery — and that two-table subquery is what the
	 * optimiser gets wrong. Measured on a 18.5k-product catalogue: three categories
	 * planned well and answered in 1s; a fourth flipped the plan, `p` lost its
	 * primary-key path for a range scan with a block-nested-loop join, and the same
	 * query took **four minutes**. DISTINCT, GROUP BY and a wrapped derived table
	 * all still timed out.
	 *
	 * Resolving the term ids to term_taxonomy_ids first — one small indexed lookup,
	 * the same trick {@see term_slugs()} already uses — leaves a subquery over a
	 * single indexed table, which plans correctly at any number of terms: 992ms for
	 * the four-category case that used to take four minutes, and flat from there.
	 *
	 * Positive membership is a join when the filter's shape allows one. See
	 * {@see select()} for the measurements: as a WHERE subquery this is the clause
	 * that eventually tips the optimiser into a four-minute plan, and joining is
	 * the only form that held across every selection tried.
	 *
	 * @param string      $taxonomy  Taxonomy name, e.g. `product_cat` or `pa_color`.
	 * @param Condition   $condition Term id(s) for IN/NOT_IN, or none for (NOT_)EXISTS.
	 * @param Query_Scope $scope     The object type being queried.
	 * @param int|null    $join_slot Alias number for a join, or null if the clause
	 *                               must stay in the WHERE (an OR filter).
	 * @return array{0: string, 1: list<mixed>, 2?: string, 3?: list<mixed>}
	 */
	private function taxonomy_clause( string $taxonomy, Condition $condition, Query_Scope $scope, ?int $join_slot = null ): array {
		if ( '' === $taxonomy ) {
			return array( '', array() );
		}

		$operator = $condition->operator;
		$any_term = Operator::EXISTS === $operator || Operator::NOT_EXISTS === $operator;

		// "Has any term of this taxonomy" (an attribute chosen with no value) means
		// every term of it; otherwise just the ones asked for.
		$term_ids = $any_term
			? array()
			: array_values( array_filter( array_map( 'intval', (array) $condition->value ) ) );

		if ( ! $any_term && array() === $term_ids ) {
			return array( '', array() );
		}

		$tt_ids = $this->term_taxonomy_ids( $taxonomy, $term_ids );

		if ( array() === $tt_ids ) {
			// Nothing in the catalogue can carry a term that does not exist. "Has
			// one of these" matches nothing; "has none of these" matches everything.
			$negative = Operator::NOT_IN === $operator || Operator::NOT_EXISTS === $operator;

			return array( $negative ? '1 = 1' : '1 = 0', array() );
		}

		$relationships = $this->wpdb->term_relationships;
		$object_column = $scope->is_variation() ? 'p.post_parent' : 'l.product_id';
		$placeholders  = implode( ', ', array_fill( 0, count( $tt_ids ), '%d' ) );

		// Exclusion stays an anti-join in the WHERE: that is the right shape for it,
		// it is free of NOT IN's NULL pitfalls, and it does not have the plan
		// problem positive membership does.
		if ( Operator::NOT_IN === $operator || Operator::NOT_EXISTS === $operator ) {
			$fragment = "NOT EXISTS (
				SELECT 1 FROM {$relationships} tr
				WHERE tr.object_id = {$object_column} AND tr.term_taxonomy_id IN ( {$placeholders} )
			)";

			return array( $fragment, $tt_ids );
		}

		if ( null === $join_slot ) {
			// An OR filter: a join would silently turn this condition into an AND.
			$fragment = "{$object_column} IN (
				SELECT tr.object_id FROM {$relationships} tr
				WHERE tr.term_taxonomy_id IN ( {$placeholders} )
			)";

			return array( $fragment, $tt_ids );
		}

		// DISTINCT because a product in two of the chosen categories has two
		// relationship rows, and without it the join would count it twice.
		$alias = 'co_tax' . $join_slot;
		$join  = "INNER JOIN (
			SELECT DISTINCT tr.object_id FROM {$relationships} tr
			WHERE tr.term_taxonomy_id IN ( {$placeholders} )
		) {$alias} ON {$alias}.object_id = {$object_column}";

		return array( '', array(), $join, $tt_ids );
	}

	/**
	 * The term_taxonomy_ids for a taxonomy, optionally narrowed to specific terms.
	 *
	 * One indexed lookup that keeps the membership subquery down to a single table
	 * — see {@see taxonomy_clause()} for why that matters so much.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int[]  $term_ids Term ids, or empty for every term of the taxonomy.
	 * @return list<int>
	 */
	private function term_taxonomy_ids( string $taxonomy, array $term_ids ): array {
		$taxonomies = $this->wpdb->term_taxonomy;

		$sql  = "SELECT term_taxonomy_id FROM {$taxonomies} WHERE taxonomy = %s";
		$args = array( $taxonomy );

		if ( array() !== $term_ids ) {
			$sql .= ' AND term_id IN ( ' . implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) ) . ' )';
			$args = array( ...$args, ...$term_ids );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $this->wpdb->get_col( $this->wpdb->prepare( $sql, ...$args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $ids );
	}

	/**
	 * Match a variation by its own chosen attribute value. Unlike a parent
	 * product, whose attributes are taxonomy terms, a variation stores the single
	 * value it was created for as post meta `attribute_{taxonomy}` holding the
	 * term slug. So a size-L filter matches only the L variations, not every
	 * variation of a parent that offers size L (CONTEXT §4 — variations first
	 * class). The condition's term ids are resolved to slugs to compare.
	 *
	 * @param string    $taxonomy  Attribute taxonomy, e.g. `pa_size`.
	 * @param Condition $condition Term id(s) for IN/NOT_IN, or none for (NOT_)EXISTS.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function variation_attribute_clause( string $taxonomy, Condition $condition ): array {
		if ( '' === $taxonomy ) {
			return array( '', array() );
		}

		$postmeta = $this->wpdb->postmeta;
		$meta_key = 'attribute_' . $taxonomy;
		$operator = $condition->operator;

		// Positive matches drive from the postmeta set of matching variations —
		// `l.product_id IN (SELECT post_id …)`, a semi-join the optimiser can start
		// from — for the same reason as {@see taxonomy_clause()}: a correlated
		// EXISTS over 50k+ variations mis-plans into a full scan. Negatives stay as
		// NOT EXISTS (anti-join, NULL-safe).

		// "Has any value for this attribute" (chosen with no specific value), or none.
		if ( Operator::EXISTS === $operator || Operator::NOT_EXISTS === $operator ) {
			if ( Operator::NOT_EXISTS === $operator ) {
				$fragment = "NOT EXISTS (
					SELECT 1 FROM {$postmeta} pm
					WHERE pm.post_id = l.product_id AND pm.meta_key = %s AND pm.meta_value <> ''
				)";

				return array( $fragment, array( $meta_key ) );
			}

			$fragment = "l.product_id IN (
				SELECT pm.post_id FROM {$postmeta} pm
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
			)";

			return array( $fragment, array( $meta_key ) );
		}

		$term_ids = array_values( array_filter( array_map( 'intval', (array) $condition->value ) ) );

		if ( array() === $term_ids ) {
			return array( '', array() );
		}

		$slugs = $this->term_slugs( $term_ids );

		if ( array() === $slugs ) {
			return array( '', array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		if ( Operator::NOT_IN === $operator ) {
			$fragment = "NOT EXISTS (
				SELECT 1 FROM {$postmeta} pm
				WHERE pm.post_id = l.product_id AND pm.meta_key = %s AND pm.meta_value IN ( {$placeholders} )
			)";

			return array( $fragment, array( $meta_key, ...$slugs ) );
		}

		$fragment = "l.product_id IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = %s AND pm.meta_value IN ( {$placeholders} )
		)";

		return array( $fragment, array( $meta_key, ...$slugs ) );
	}

	/**
	 * Resolve term ids to their slugs (variation attribute meta stores slugs).
	 *
	 * @param int[] $term_ids Term ids.
	 * @return list<string>
	 */
	private function term_slugs( array $term_ids ): array {
		$terms        = $this->wpdb->terms;
		$placeholders = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slugs = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT slug FROM {$terms} WHERE term_id IN ( {$placeholders} )",
				...$term_ids
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'strval', $slugs );
	}

	/**
	 * Post-meta comparison against a meta key.
	 *
	 * Positive tests drive from the postmeta meta_key index (one pass) rather than
	 * a correlated EXISTS that re-scans every product's meta — over WooCommerce
	 * products (~20-30 meta rows each) that difference is ~12x.
	 *
	 * A negative test only flips that keyword to NOT IN; the value test inside
	 * stays positive. Pushing the negation inside instead — `IN (… meta_value !=
	 * x)` — asks a different question, "has some *other* value for this key",
	 * which silently drops every product that has no such meta row at all. A
	 * brand exclusion has to keep the unbranded products: they are, definitively,
	 * not that brand. (It also builds a near-catalogue-sized id list to do it.)
	 *
	 * NOT IN, not a correlated NOT EXISTS. Both are NULL-safe here — postmeta's
	 * post_id is NOT NULL — but the correlated form re-runs per candidate row,
	 * and the uncorrelated one is materialised once. Measured on the 18.5k
	 * catalogue: excluding a brand with nothing else selected took **13.6s** as
	 * NOT EXISTS and **2.5s** as NOT IN. Once any positive condition narrows the
	 * candidates the two converge (594ms vs 568ms with a category and a tag
	 * joined), so the cheap shape costs nothing in the common case and saves the
	 * degenerate one — a lone exclusion is exactly what a user gets by opening
	 * the filter and setting one field to "is not".
	 *
	 * Unlike positive membership, this subquery is not a plan hazard: an
	 * anti-join gives the optimiser no join order to re-plan around. Two of them
	 * in one statement measured 800ms with a category and tag joined (CONTEXT §3
	 * — the four-minute plan needed positive `IN (SELECT …)` semi-joins).
	 *
	 * @param string    $meta_key  The meta key to test.
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function meta_clause( string $meta_key, Condition $condition ): array {
		if ( '' === $meta_key ) {
			return array( '', array() );
		}

		$postmeta = $this->wpdb->postmeta;
		$operator = $condition->operator;

		// A negative operator is asked as its positive twin, and negated by the
		// keyword outside the subquery.
		$positive = match ( $operator ) {
			Operator::NOT_IN     => Operator::IN,
			Operator::NOT_EQUALS => Operator::EQUALS,
			Operator::NOT_EXISTS => Operator::EXISTS,
			default              => null,
		};

		if ( ( Operator::IN === $operator || Operator::NOT_IN === $operator )
			&& array() === array_values( (array) $condition->value ) ) {
			// An empty list is not a question: asked positively it would decay into
			// "has this key at all", and negatively into "has it not".
			return array( '', array() );
		}

		list( $value_test, $value_args ) = $this->meta_value_test( $positive ?? $operator, $condition->value );

		$keyword = null !== $positive ? 'NOT IN' : 'IN';

		$fragment = "l.product_id {$keyword} (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = %s{$value_test}
		)";

		return array( $fragment, array( $meta_key, ...$value_args ) );
	}

	/**
	 * Build the value comparison inside a meta subquery.
	 *
	 * Positive operators only. Negation belongs to the caller, which wraps this
	 * test in a NOT EXISTS — see {@see meta_clause()} for why it may not be pushed
	 * in here alongside the value.
	 *
	 * @param Operator $operator The operator, already mapped to its positive form.
	 * @param mixed    $value    The operand.
	 * @return array{0: string, 1: list<mixed>} SQL fragment (with leading " AND ") and its args.
	 */
	private function meta_value_test( Operator $operator, mixed $value ): array {
		$numeric = array(
			Operator::GREATER_THAN->name     => '>',
			Operator::GREATER_OR_EQUAL->name => '>=',
			Operator::LESS_THAN->name        => '<',
			Operator::LESS_OR_EQUAL->name    => '<=',
		);

		if ( Operator::EXISTS === $operator ) {
			return array( '', array() );
		}

		if ( Operator::EQUALS === $operator ) {
			return array( ' AND pm.meta_value = %s', array( (string) $value ) );
		}

		if ( Operator::CONTAINS === $operator ) {
			return array( ' AND pm.meta_value LIKE %s', array( '%' . $this->wpdb->esc_like( (string) $value ) . '%' ) );
		}

		if ( isset( $numeric[ $operator->name ] ) ) {
			return array( " AND CAST( pm.meta_value AS DECIMAL(20,4) ) {$numeric[ $operator->name ]} %f", array( $value ) );
		}

		if ( Operator::BETWEEN === $operator ) {
			$range = array_values( (array) $value );
			if ( count( $range ) < 2 ) {
				return array( '', array() );
			}

			return array( ' AND CAST( pm.meta_value AS DECIMAL(20,4) ) BETWEEN %f AND %f', array( $range[0], $range[1] ) );
		}

		if ( Operator::IN === $operator ) {
			$values = array_map( 'strval', array_values( (array) $value ) );
			if ( array() === $values ) {
				return array( '', array() );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

			return array( " AND pm.meta_value IN ( {$placeholders} )", $values );
		}

		return array( '', array() );
	}
}
