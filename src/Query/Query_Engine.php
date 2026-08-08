<?php
/**
 * Resolves a filter to a frozen list of product IDs.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

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
	 * @param Filter $filter The filter to resolve.
	 * @return list<int>
	 */
	public function resolve( Filter $filter ): array {
		$sql = $this->select( 'l.product_id', $filter ) . ' ORDER BY l.product_id ASC';

		// $sql is assembled in select() from trusted identifiers with all values
		// bound via $wpdb->prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $this->wpdb->get_col( $sql ) );
	}

	/**
	 * Count the products matching a filter without materializing the IDs.
	 *
	 * @param Filter $filter The filter to count.
	 */
	public function count( Filter $filter ): int {
		// select() returns a prepared statement built from trusted identifiers.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $this->select( 'COUNT(*)', $filter ) );
	}

	/**
	 * Build the full, prepared SELECT for a projection and filter.
	 *
	 * @param string $projection The select list, e.g. `l.product_id`.
	 * @param Filter $filter      The filter to translate.
	 */
	private function select( string $projection, Filter $filter ): string {
		$lookup = $this->wpdb->prefix . 'wc_product_meta_lookup';
		$posts  = $this->wpdb->posts;

		$sql = "SELECT {$projection}
			FROM {$lookup} l
			INNER JOIN {$posts} p ON p.ID = l.product_id
			WHERE p.post_type = 'product' AND p.post_status = 'publish'";

		list( $where, $args ) = $this->build_where( $filter );

		if ( '' !== $where ) {
			$sql .= ' AND ' . $where;
		}

		// Identifiers ($projection, table names) are trusted; every value is a
		// placeholder resolved here by prepare().
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array() === $args ? $sql : $this->wpdb->prepare( $sql, ...$args );
	}

	/**
	 * Translate a filter's conditions into a WHERE fragment and its arguments.
	 *
	 * @param Filter $filter The filter.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function build_where( Filter $filter ): array {
		$fragments = array();
		$args      = array();

		foreach ( $filter->conditions() as $condition ) {
			list( $fragment, $fragment_args ) = $this->clause_for( $condition );

			if ( '' === $fragment ) {
				continue;
			}

			$fragments[] = $fragment;
			$args        = array( ...$args, ...$fragment_args );
		}

		if ( array() === $fragments ) {
			return array( '', array() );
		}

		$glue = Filter::RELATION_OR === $filter->relation() ? ' OR ' : ' AND ';

		return array( '( ' . implode( $glue, $fragments ) . ' )', $args );
	}

	/**
	 * Dispatch a condition to the right clause builder based on its field.
	 *
	 * @param Condition $condition The condition.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function clause_for( Condition $condition ): array {
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

		if ( 'category' === $field ) {
			return $this->taxonomy_clause( 'product_cat', $condition );
		}

		if ( str_starts_with( $field, 'attribute:' ) ) {
			return $this->taxonomy_clause( substr( $field, strlen( 'attribute:' ) ), $condition );
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
	 * Membership in a taxonomy's terms, as a correlated EXISTS.
	 *
	 * @param string    $taxonomy  Taxonomy name, e.g. `product_cat` or `pa_color`.
	 * @param Condition $condition Value is a term id or a list of term ids.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function taxonomy_clause( string $taxonomy, Condition $condition ): array {
		$term_ids = array_values( array_filter( array_map( 'intval', (array) $condition->value ) ) );

		if ( '' === $taxonomy || array() === $term_ids ) {
			return array( '', array() );
		}

		$relationships = $this->wpdb->term_relationships;
		$taxonomies    = $this->wpdb->term_taxonomy;
		$placeholders  = implode( ', ', array_fill( 0, count( $term_ids ), '%d' ) );
		$negate        = Operator::NOT_IN === $condition->operator || Operator::NOT_EXISTS === $condition->operator;
		$keyword       = $negate ? 'NOT EXISTS' : 'EXISTS';

		$fragment = "{$keyword} (
			SELECT 1 FROM {$relationships} tr
			INNER JOIN {$taxonomies} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			WHERE tr.object_id = l.product_id AND tt.taxonomy = %s AND tt.term_id IN ( {$placeholders} )
		)";

		return array( $fragment, array( $taxonomy, ...$term_ids ) );
	}

	/**
	 * Post-meta comparison, as a correlated EXISTS on postmeta.
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

		list( $value_test, $value_args ) = $this->meta_value_test( $operator, $condition->value );

		$negate  = Operator::NOT_EXISTS === $operator;
		$keyword = $negate ? 'NOT EXISTS' : 'EXISTS';

		$fragment = "{$keyword} (
			SELECT 1 FROM {$postmeta} pm
			WHERE pm.post_id = l.product_id AND pm.meta_key = %s{$value_test}
		)";

		return array( $fragment, array( $meta_key, ...$value_args ) );
	}

	/**
	 * Build the value comparison inside a meta EXISTS.
	 *
	 * @param Operator $operator The operator.
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

		if ( Operator::EXISTS === $operator || Operator::NOT_EXISTS === $operator ) {
			return array( '', array() );
		}

		if ( Operator::EQUALS === $operator || Operator::NOT_EQUALS === $operator ) {
			$comparison = Operator::NOT_EQUALS === $operator ? '!=' : '=';

			return array( " AND pm.meta_value {$comparison} %s", array( (string) $value ) );
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

		if ( Operator::IN === $operator || Operator::NOT_IN === $operator ) {
			$values = array_map( 'strval', array_values( (array) $value ) );
			if ( array() === $values ) {
				return array( '', array() );
			}

			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
			$keyword      = Operator::NOT_IN === $operator ? 'NOT IN' : 'IN';

			return array( " AND pm.meta_value {$keyword} ( {$placeholders} )", $values );
		}

		return array( '', array() );
	}
}
