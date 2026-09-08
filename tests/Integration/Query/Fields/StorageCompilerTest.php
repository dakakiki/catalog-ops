<?php
/**
 * The first tests in this repository that assert emitted SQL text.
 *
 * Everything else here checks a count, which is the right thing to check almost
 * everywhere — and it is exactly the wrong thing for {@see Storage_Compiler}. The
 * shapes this class chooses between return the *same answer* on any catalogue a
 * test can build: a semi-join and a derived-table join agree on 201 fixtures and
 * disagree by four minutes on 18,583 products, and a negation pushed inside a
 * subquery agrees with one kept outside on every product that has a value and
 * differs only on the ones that have none. A count-based test cannot see either.
 *
 * So these assert the statement. Whitespace is normalised, because the indentation
 * is for whoever reads a slow-query log and is not part of the contract; the
 * keywords, the alias, the placeholder and the argument order are.
 *
 * @package CatalogOps\Tests\Integration\Query\Fields
 */

namespace CatalogOps\Tests\Integration\Query\Fields;

use CatalogOps\Query\Fields\Clause;
use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Lookup_Column;
use CatalogOps\Query\Fields\Object_Anchor;
use CatalogOps\Query\Fields\Storage_Compiler;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Fields\Value_Map;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Fields\Storage_Compiler
 * @covers \CatalogOps\Query\Fields\Field_Storage
 * @covers \CatalogOps\Query\Fields\Clause
 */
final class StorageCompilerTest extends WP_UnitTestCase {

	private Storage_Compiler $compiler;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->compiler = new Storage_Compiler( $wpdb );
	}

	// -----------------------------------------------------------------------
	// A lookup column: no join, no subquery, and a NULL guard on exclusion.
	// -----------------------------------------------------------------------

	public function test_a_lookup_column_is_a_plain_predicate(): void {
		$clause = $this->compile(
			Field_Storage::lookup_column( Lookup_Column::MIN_PRICE ),
			Operator::GREATER_THAN,
			100
		);

		$this->assertSame( 'l.min_price > %f', $this->sql( $clause->where ) );
		$this->assertSame( array( 100.0 ), $clause->where_args );
		$this->assertSame( '', $clause->join, 'A lookup column costs no join.' );
	}

	/**
	 * The trap this guard exists for. `stock_quantity` is NULL on every product
	 * that does not manage stock — most of a real catalogue — and `col != 5` is
	 * UNKNOWN there, not true. Without the guard, "stock is not 5" silently drops
	 * every unmanaged product, which is the opposite of what excluding a value
	 * means.
	 */
	public function test_excluding_a_nullable_column_keeps_the_rows_that_have_no_value(): void {
		$clause = $this->compile(
			Field_Storage::lookup_column( Lookup_Column::STOCK_QUANTITY ),
			Operator::NOT_EQUALS,
			5
		);

		$this->assertSame(
			'( l.stock_quantity IS NULL OR NOT ( l.stock_quantity = %d ) )',
			$this->sql( $clause->where )
		);
	}

	/**
	 * And a column that cannot be NULL does not pay for the guard.
	 */
	public function test_excluding_a_non_nullable_column_is_a_bare_negation(): void {
		$clause = $this->compile(
			Field_Storage::lookup_column( Lookup_Column::SKU ),
			Operator::NOT_EQUALS,
			'ABC'
		);

		$this->assertSame( 'NOT ( l.sku = %s )', $this->sql( $clause->where ) );
	}

	// -----------------------------------------------------------------------
	// Post meta: a join under AND, a semi-join under OR, NOT IN for exclusion.
	// -----------------------------------------------------------------------

	/**
	 * Positive membership under AND is a join over a DISTINCT derived table, and
	 * its values go in the JOIN argument list — not the WHERE one. Placeholders
	 * bind by textual position through one vsprintf and the FROM clause precedes
	 * the WHERE, so a join value put in the WHERE list produces a statement that
	 * runs, succeeds, and answers a different question.
	 */
	public function test_positive_post_meta_under_and_is_a_join_over_a_distinct_derived_table(): void {
		global $wpdb;

		$clause = $this->compile(
			Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT ),
			Operator::EQUALS,
			'Globex',
			Query_Scope::PRODUCT,
			3
		);

		$this->assertSame( '', $clause->where, 'The clause is entirely a join.' );
		$this->assertSame( array(), $clause->where_args );

		$this->assertSame(
			"INNER JOIN ( SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm "
				. 'WHERE pm.meta_key = %s AND pm.meta_value = %s ) co_f3 ON co_f3.post_id = l.product_id',
			$this->sql( $clause->join )
		);
		$this->assertSame( array( 'co_supplier', 'Globex' ), $clause->join_args );
	}

	/**
	 * Under OR there is no join to be had — a join is an AND — so the same question
	 * degrades to the semi-join, in the WHERE, with its values in the WHERE list.
	 */
	public function test_positive_post_meta_under_or_is_a_semi_join_in_the_where(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT ),
			Operator::EQUALS,
			'Globex',
			Query_Scope::PRODUCT,
			null
		);

		$this->assertStringStartsWith( 'l.product_id IN ( SELECT pm.post_id', $this->sql( $clause->where ) );
		$this->assertSame( array( 'co_supplier', 'Globex' ), $clause->where_args );
		$this->assertSame( '', $clause->join );
	}

	/**
	 * Exclusion is the uncorrelated NOT IN, measured at 2.5s against 13.6s for the
	 * correlated NOT EXISTS on a lone exclusion over the 18.5k catalogue. The
	 * negation sits outside the subquery: asked as `IN ( … value != x )` it would
	 * answer "has some other value", dropping every object with no row at all — so
	 * the exclusion would lose exactly the products that are definitively not the
	 * thing being excluded.
	 */
	public function test_excluding_post_meta_negates_outside_the_subquery(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT ),
			Operator::NOT_EQUALS,
			'Globex',
			Query_Scope::PRODUCT,
			3
		);

		$sql = $this->sql( $clause->where );

		$this->assertStringStartsWith( 'l.product_id NOT IN ( SELECT pm.post_id', $sql );
		$this->assertStringContainsString( 'pm.meta_value = %s', $sql, 'The value test is the positive twin.' );
		$this->assertStringNotContainsString( '!=', $sql, 'The negation may not be pushed inside.' );
		$this->assertSame( '', $clause->join, 'An exclusion is never a join.' );
	}

	/**
	 * A repeater's sub-fields: one LIKE over a key pattern assembled from two
	 * literals and bound, never interpolated. The prefix is required non-empty so
	 * the meta_key(191) index can still range-scan.
	 */
	public function test_a_repeater_sub_field_matches_a_key_pattern(): void {
		$clause = $this->compile(
			Field_Storage::post_meta_rows( 'co_specs_', '_value', Value_Kind::TEXT ),
			Operator::EQUALS,
			'Steel',
			Query_Scope::PRODUCT,
			1
		);

		$this->assertStringContainsString( 'pm.meta_key LIKE %s', $this->sql( $clause->join ) );
		$this->assertSame( array( 'co\\_specs\\_%\\_value', 'Steel' ), $clause->join_args );
	}

	// -----------------------------------------------------------------------
	// Value kinds: the cast, and where it is not wanted.
	// -----------------------------------------------------------------------

	/**
	 * A number stored as text has to be cast to be ordered — `'9' > '10'` is true
	 * otherwise — and the cast is also what makes the comparison unindexed.
	 */
	public function test_ordering_a_number_stored_as_text_casts_it(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_cost', Value_Kind::NUMERIC_TEXT ),
			Operator::GREATER_THAN,
			100,
			Query_Scope::PRODUCT,
			null
		);

		$sql = $this->sql( $clause->where );

		$this->assertStringContainsString( 'CAST( pm.meta_value AS DECIMAL(20,4) ) > %f', $sql );
		$this->assertSame( array( 'co_cost', 100.0 ), $clause->where_args );
	}

	/**
	 * Equality is deliberately not cast: `'9' = '9'` is already right, and casting
	 * would defeat the index for no gain. Ordering is the only place the cast is
	 * needed and the only place it costs something.
	 */
	public function test_equality_on_a_number_stored_as_text_is_not_cast(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_cost', Value_Kind::NUMERIC_TEXT ),
			Operator::EQUALS,
			100,
			Query_Scope::PRODUCT,
			null
		);

		$sql = $this->sql( $clause->where );

		$this->assertStringContainsString( 'pm.meta_value = %s', $sql );
		$this->assertStringNotContainsString( 'CAST', $sql );
	}

	/**
	 * A zero-padded big-endian date sorts correctly as text, so a range over it
	 * needs no cast at all.
	 */
	public function test_a_date_range_is_lexical_and_uncast(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_launch', Value_Kind::DATE_TEXT ),
			Operator::BETWEEN,
			array( '20240101', '20240630' ),
			Query_Scope::PRODUCT,
			null
		);

		$sql = $this->sql( $clause->where );

		$this->assertStringContainsString( 'pm.meta_value BETWEEN %s AND %s', $sql );
		$this->assertStringNotContainsString( 'CAST', $sql );
		$this->assertSame( array( 'co_launch', '20240101', '20240630' ), $clause->where_args );
	}

	/**
	 * A serialised list is probed with the quoted token, never compared. A bare
	 * `%12%` would also match 112 and 2012-12-01; `%"12"%` matches the element.
	 *
	 * The engine expands the operand list itself, which is why the "is one of" that
	 * becomes "is all of" cannot be written by a provider — and why the disjunction
	 * it emits is bracketed before it can be ANDed to anything.
	 */
	public function test_a_serialised_list_is_probed_with_the_quoted_token(): void {
		$clause = $this->compile(
			Field_Storage::post_meta( 'co_badges', Value_Kind::SERIALIZED_LIST ),
			Operator::IN,
			array( 'eco', 'sale' ),
			Query_Scope::PRODUCT,
			null
		);

		$sql = $this->sql( $clause->where );

		$this->assertStringContainsString(
			'( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )',
			$sql,
			'The one disjunction this compiler emits, and it is bracketed.'
		);
		$this->assertSame( array( 'co_badges', '%"eco"%', '%"sale"%' ), $clause->where_args );
	}

	// -----------------------------------------------------------------------
	// Taxonomy: NOT EXISTS for exclusion, and the parent anchor.
	// -----------------------------------------------------------------------

	public function test_excluding_a_taxonomy_is_a_correlated_not_exists(): void {
		global $wpdb;

		list( $term_a, $tt_a ) = $this->make_term( 'Acme' );
		list( $term_b, $tt_b ) = $this->make_term( 'Globex' );

		$clause = $this->compile(
			Field_Storage::taxonomy( 'product_cat' ),
			Operator::NOT_IN,
			array( $term_a, $term_b ),
			Query_Scope::PRODUCT,
			2
		);

		$this->assertSame(
			"NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr "
				. 'WHERE tr.object_id = l.product_id AND tr.term_taxonomy_id IN ( %d, %d ) )',
			$this->sql( $clause->where )
		);

		// The operands are term_taxonomy_ids by the time they bind, not term ids.
		// Resolving them is what keeps the subquery to one table — and it is also
		// what carries the taxonomy, since a tt_id belongs to exactly one.
		$this->assertSame( array( $tt_a, $tt_b ), $clause->where_args );
	}

	/**
	 * The defect an adversarial review found, pinned so it cannot return.
	 *
	 * `taxonomy_clause()` expressed "which taxonomy" only through the operand list,
	 * and never read the taxonomy name at all. Under EXISTS the operand list is
	 * empty by construction, so the subquery lost its last predicate and became
	 * "has any term in any taxonomy" — measured on the live catalogue as 18,583
	 * products where the honest answer was 5,500. A -10% Adjust behind "has a
	 * brand" would have landed on the whole shop, with preview and run agreeing.
	 */
	public function test_taxonomy_presence_still_names_the_taxonomy(): void {
		list( , $tt_id ) = $this->make_term( 'Acme' );

		$clause = $this->compile(
			Field_Storage::taxonomy( 'product_cat' ),
			Operator::EXISTS,
			'',
			Query_Scope::PRODUCT,
			0
		);

		$sql = $this->sql( $clause->join );

		$this->assertStringContainsString( 'tr.term_taxonomy_id IN (', $sql, 'The taxonomy must reach the SQL.' );
		$this->assertStringNotContainsString( 'WHERE 1 = 1', $sql, 'An unpredicated subquery matches everything.' );
		$this->assertContains( $tt_id, $clause->join_args, 'Presence means every term of THIS taxonomy.' );
	}

	/**
	 * And a taxonomy with no terms at all answers honestly rather than widening.
	 */
	public function test_a_taxonomy_with_no_terms_matches_nothing_and_its_negation_everything(): void {
		register_taxonomy( 'qh_empty_tax', 'product' );

		$this->assertSame(
			'1 = 0',
			$this->compile( Field_Storage::taxonomy( 'qh_empty_tax' ), Operator::EXISTS, '', Query_Scope::PRODUCT, 0 )->where
		);
		$this->assertSame(
			'1 = 1',
			$this->compile( Field_Storage::taxonomy( 'qh_empty_tax' ), Operator::NOT_EXISTS, '', Query_Scope::PRODUCT, 0 )->where
		);
	}

	/**
	 * A taxonomy is registered on the product, never on the variation, so under the
	 * variation scope the subquery must match the parent. Both anchors resolve to
	 * `l.product_id` under the product scope, which is exactly why the anchor
	 * cannot be inferred from the scope: a wrong one passes every product-scope
	 * test and returns a plausible wrong set the moment the toggle is flipped.
	 */
	public function test_a_parent_anchored_field_matches_the_parent_under_the_variation_scope(): void {
		list( $term ) = $this->make_term( 'Acme' );

		$clause = $this->compile(
			Field_Storage::taxonomy( 'product_cat' ),
			Operator::IN,
			array( $term ),
			Query_Scope::VARIATION,
			null
		);

		$this->assertStringStartsWith( 'p.post_parent IN (', $this->sql( $clause->where ) );
	}

	public function test_a_parent_anchored_field_matches_the_object_under_the_product_scope(): void {
		list( $term ) = $this->make_term( 'Acme' );

		$clause = $this->compile(
			Field_Storage::taxonomy( 'product_cat' ),
			Operator::IN,
			array( $term ),
			Query_Scope::PRODUCT,
			null
		);

		$this->assertStringStartsWith( 'l.product_id IN (', $this->sql( $clause->where ) );
	}

	// -----------------------------------------------------------------------
	// Related rows: the shape WPML's language field needs.
	// -----------------------------------------------------------------------

	public function test_related_rows_carry_their_constants_into_the_subquery(): void {
		$storage = Field_Storage::related_rows(
			'co_icl_translations',
			'element_id',
			'language_code',
			Value_Kind::TEXT,
			Object_Anchor::SELF
		)->matching( 'element_type', 'post_product', Value_Kind::TEXT );

		$clause = $this->compile( $storage, Operator::EQUALS, 'sr', Query_Scope::PRODUCT, 4 );

		$this->assertSame(
			'INNER JOIN ( SELECT DISTINCT r.`element_id` FROM `co_icl_translations` r '
				. 'WHERE r.`language_code` = %s AND r.`element_type` = %s ) co_f4 '
				. 'ON co_f4.`element_id` = l.product_id',
			$this->sql( $clause->join )
		);
		$this->assertSame( array( 'sr', 'post_product' ), $clause->join_args );
	}

	/**
	 * The IS NOT NULL is load-bearing rather than defensive. An uncorrelated NOT IN
	 * over a nullable column returns UNKNOWN for every row and matches nothing,
	 * which a user reads as "my filter is too narrow" rather than as a bug — and
	 * WPML's `icl_translations.element_id` really is `BIGINT NULL`. On a NOT NULL
	 * column MySQL removes the predicate, so the measured shape is preserved.
	 */
	public function test_excluding_related_rows_guards_against_a_nullable_object_column(): void {
		$storage = Field_Storage::related_rows(
			'co_icl_translations',
			'element_id',
			'language_code',
			Value_Kind::TEXT
		);

		$clause = $this->compile( $storage, Operator::NOT_EQUALS, 'sr', Query_Scope::PRODUCT, 4 );

		$this->assertStringContainsString( 'r.`element_id` IS NOT NULL', $this->sql( $clause->where ) );
	}

	// -----------------------------------------------------------------------
	// An empty operand set is an answer.
	// -----------------------------------------------------------------------

	public function test_an_empty_list_matches_nothing_and_its_negation_matches_everything(): void {
		$storage = Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT );

		$this->assertSame(
			'1 = 0',
			$this->compile( $storage, Operator::IN, array(), Query_Scope::PRODUCT, 1 )->where
		);
		$this->assertSame(
			'1 = 1',
			$this->compile( $storage, Operator::NOT_IN, array(), Query_Scope::PRODUCT, 1 )->where
		);
	}

	/**
	 * And a value map that resolves to nothing is the same answer — "exclude a
	 * brand that was deleted" has to keep every product.
	 */
	public function test_a_value_map_that_resolves_to_nothing_is_a_real_answer(): void {
		$empty = new class() implements Value_Map {

			public function map( array $values ): array {
				return array();
			}
		};

		$clause = $this->compile(
			Field_Storage::taxonomy( 'product_cat', $empty ),
			Operator::NOT_IN,
			array( 7 ),
			Query_Scope::PRODUCT,
			1
		);

		$this->assertSame( '1 = 1', $clause->where );
	}

	/**
	 * The second defect the review found. The empty-set rule was gated on "is this
	 * a list operator", so an emptied IN was caught and an emptied EQUALS was not —
	 * and a Value_Map can return nothing for any operator. What fell through
	 * reached a shape with no predicate left, matching the whole catalogue where
	 * the honest answer was nothing.
	 */
	public function test_an_emptied_single_value_operator_is_answered_not_dropped(): void {
		$empty = new class() implements Value_Map {

			public function map( array $values ): array {
				return array();
			}
		};

		$storage = Field_Storage::taxonomy( 'product_cat', $empty );

		$this->assertSame(
			'1 = 0',
			$this->compile( $storage, Operator::EQUALS, 42, Query_Scope::PRODUCT, 1 )->where,
			'"brand is Acme", where Acme was deleted, matches nothing.'
		);
		$this->assertSame(
			'1 = 1',
			$this->compile( $storage, Operator::NOT_EQUALS, 42, Query_Scope::PRODUCT, 1 )->where,
			'And excluding a brand that no longer exists keeps every product.'
		);
	}

	// -----------------------------------------------------------------------
	// Presence on a lookup column, which is not one question.
	// -----------------------------------------------------------------------

	/**
	 * The third defect. Presence compiled to IS NOT NULL for every column, but
	 * WooCommerce writes '' rather than NULL for an unset SKU — so "has a SKU"
	 * was a tautology matching the whole catalogue, and "has no SKU" matched
	 * nothing. Both silent, in opposite directions.
	 */
	public function test_presence_on_a_column_written_as_an_empty_string_tests_for_emptiness(): void {
		$storage = Field_Storage::lookup_column( Lookup_Column::SKU );

		$this->assertSame(
			"( l.sku IS NOT NULL AND l.sku <> '' )",
			$this->sql( $this->compile( $storage, Operator::EXISTS, '' )->where )
		);
		$this->assertSame(
			"( l.sku IS NULL OR l.sku = '' )",
			$this->sql( $this->compile( $storage, Operator::NOT_EXISTS, '' )->where )
		);
	}

	public function test_presence_on_a_genuinely_nullable_column_is_a_null_test(): void {
		$this->assertSame(
			'l.stock_quantity IS NOT NULL',
			$this->sql( $this->compile( Field_Storage::lookup_column( Lookup_Column::STOCK_QUANTITY ), Operator::EXISTS, '' )->where )
		);
	}

	/**
	 * And a column every row carries is refused rather than answered with a
	 * tautology the user would read as a working filter.
	 */
	public function test_presence_on_an_always_populated_column_is_refused(): void {
		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/every product has a value for it/' );

		$this->compile( Field_Storage::lookup_column( Lookup_Column::ON_SALE ), Operator::EXISTS, '' );
	}

	/**
	 * The fourth defect. matching() is a method on the class rather than on a
	 * per-kind builder, so it was callable on all five kinds — and honoured by two.
	 * A narrowing that is silently dropped leaves a WIDER clause than the provider
	 * asked for, so it has to refuse.
	 */
	public function test_a_constant_on_a_kind_that_cannot_carry_one_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/cannot carry a constant test/' );

		Field_Storage::lookup_column( Lookup_Column::STOCK_STATUS )->matching( 'onsale', 1, Value_Kind::INTEGER );
	}

	public function test_a_constant_on_a_taxonomy_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Field_Storage::taxonomy( 'product_cat' )->matching( 'term_order', 1, Value_Kind::INTEGER );
	}

	// -----------------------------------------------------------------------
	// Refusals.
	// -----------------------------------------------------------------------

	public function test_more_operands_than_one_condition_may_carry_is_refused(): void {
		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/more than 1000 values/' );

		$this->compile(
			Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT ),
			Operator::IN,
			range( 1, Field_Storage::MAX_OPERANDS + 1 )
		);
	}

	public function test_a_non_scalar_operand_is_refused(): void {
		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/not a single value/' );

		$this->compile(
			Field_Storage::post_meta( 'co_supplier', Value_Kind::TEXT ),
			Operator::EQUALS,
			array( 'a' => array( 'nested' ) )
		);
	}

	public function test_a_one_ended_range_is_refused(): void {
		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/both a low and a high value/' );

		$this->compile(
			Field_Storage::post_meta( 'co_cost', Value_Kind::NUMERIC_TEXT ),
			Operator::BETWEEN,
			array( 10 )
		);
	}

	/**
	 * A dot in an identifier is what would let a provider name a second table
	 * inside a membership subquery, and that two-table subquery is the measured
	 * four-minute plan. It has to be unbuildable, not merely discouraged.
	 */
	public function test_a_qualified_identifier_cannot_be_built(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/bare column name/' );

		Field_Storage::related_rows( 'co_icl_translations', 'tt.element_id', 'language_code', Value_Kind::TEXT );
	}

	public function test_a_repeater_pattern_needs_a_literal_prefix(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/leading wildcard/' );

		Field_Storage::post_meta_rows( '', '_value', Value_Kind::TEXT );
	}

	/**
	 * Compile one condition against a throwaway descriptor.
	 *
	 * @param Field_Storage $storage   Where the value lives.
	 * @param Operator      $operator  The comparison.
	 * @param mixed         $value     The operand.
	 * @param Query_Scope   $scope     The scope.
	 * @param int|null      $join_slot Alias number, or null for the OR path.
	 */
	private function compile(
		Field_Storage $storage,
		Operator $operator,
		mixed $value,
		Query_Scope $scope = Query_Scope::PRODUCT,
		?int $join_slot = 1
	): Clause {
		$field = new Filter_Field(
			'test:field',
			'Test field',
			Filter_Control::TEXT,
			$storage->value_kind->operators(),
			array( Query_Scope::PRODUCT, Query_Scope::VARIATION )
		);

		return $this->compiler->compile( $field, $storage, $operator, $value, $scope, $join_slot );
	}

	/**
	 * Create a product_cat term and return its term id and term_taxonomy_id.
	 *
	 * The two are different numbers, and the difference is the point: the compiler
	 * is handed term ids and must bind term_taxonomy_ids.
	 *
	 * @param string $name Term name.
	 * @return array{0: int, 1: int}
	 */
	private function make_term( string $name ): array {
		$term = wp_insert_term( $name . ' ' . wp_generate_password( 6, false ), 'product_cat' );

		$this->assertIsArray( $term, 'The fixture term must exist.' );

		return array( (int) $term['term_id'], (int) $term['term_taxonomy_id'] );
	}

	/**
	 * Collapse the whitespace a statement is formatted with, which is for whoever
	 * reads a slow-query log and is not part of the contract.
	 *
	 * @param string $sql The fragment.
	 */
	private function sql( string $sql ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	}
}
