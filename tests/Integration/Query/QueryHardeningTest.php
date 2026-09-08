<?php
/**
 * Integration tests for the query engine's structural guards.
 *
 * Every case here is about the same failure: a filter that quietly matches MORE
 * than it was asked for. That direction is the dangerous one because nothing
 * downstream can notice it — preview and run resolve the same widened filter,
 * agree perfectly, and both report success, so a -10% price edit lands on the
 * whole shop and every check the plugin makes says it did the right thing.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\Query
 */

namespace CatalogOps\Tests\Integration\Query;

use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
use CatalogOps\Query\Requirements\Requirement;
use WC_Product_Simple;
use WP_UnitTestCase;
use wpdb;

/**
 * @covers \CatalogOps\Query\Query_Engine
 */
final class QueryHardeningTest extends WP_UnitTestCase {

	private Query_Engine $engine;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		global $wpdb;
		$this->engine = new Query_Engine( $wpdb );
	}

	// -----------------------------------------------------------------------
	// An empty operand list is an answer, not an absent condition.
	// -----------------------------------------------------------------------

	/**
	 * "Is one of nothing" matches nothing. It used to return the empty fragment
	 * {@see Query_Engine::build_where()} skips, so under AND the condition simply
	 * vanished and the filter matched everything else it said.
	 */
	public function test_an_empty_in_list_matches_nothing(): void {
		$this->make_product( 10 );
		$this->make_product( 20 );

		$this->assertSame( 2, $this->engine->count( new Filter() ), 'sanity: both products are in scope' );

		$this->assertSame(
			0,
			$this->engine->count(
				new Filter( array( new Condition( 'sku', Operator::IN, array() ) ) )
			)
		);
	}

	/**
	 * And "is not one of nothing" matches everything, which is the same convention
	 * {@see Query_Engine::taxonomy_clause()} already used for terms that no longer
	 * exist. Excluding a set that turns out to be empty must not exclude anything.
	 */
	public function test_an_empty_not_in_list_matches_everything(): void {
		$this->make_product( 10 );
		$this->make_product( 20 );

		$this->assertSame(
			2,
			$this->engine->count(
				new Filter( array( new Condition( 'sku', Operator::NOT_IN, array() ) ) )
			)
		);
	}

	/**
	 * The case that shows why it matters. An empty list beside a real condition
	 * must not leave the real condition standing alone over a wider set — which is
	 * exactly what dropping the fragment did.
	 */
	public function test_an_empty_in_list_does_not_widen_an_and_filter(): void {
		$this->make_product( 10 );
		$this->make_product( 500 );

		$filter = new Filter(
			array(
				new Condition( 'price', Operator::GREATER_THAN, 100 ),
				new Condition( 'category', Operator::IN, array() ),
			)
		);

		// Before: the category condition vanished and this answered 1 — the count
		// for "price > 100" alone, a set nobody asked for.
		$this->assertSame( 0, $this->engine->count( $filter ) );
	}

	/**
	 * The same for a numeric column, whose empty-list branch was separate.
	 */
	public function test_an_empty_numeric_in_list_matches_nothing(): void {
		$this->make_product( 10 );

		$this->assertSame(
			0,
			$this->engine->count(
				new Filter( array( new Condition( 'stock_quantity', Operator::IN, array() ) ) )
			)
		);
	}

	/**
	 * And for a meta key, where the widening decayed into "has this key at all".
	 */
	public function test_an_empty_meta_in_list_matches_nothing(): void {
		$id = $this->make_product( 10 );
		update_post_meta( $id, '_qh_flag', 'yes' );

		$this->assertSame(
			1,
			$this->engine->count(
				new Filter( array( new Condition( 'meta:_qh_flag', Operator::EXISTS, '' ) ) ) ),
			'sanity: the key is there'
		);

		$this->assertSame(
			0,
			$this->engine->count(
				new Filter( array( new Condition( 'meta:_qh_flag', Operator::IN, array() ) ) )
			)
		);
	}

	// -----------------------------------------------------------------------
	// A fragment carrying a top-level OR must not swallow the filter.
	// -----------------------------------------------------------------------

	/**
	 * The parenthesisation, proved rather than asserted about.
	 *
	 * No clause builder emits a top-level OR today, so this uses the one seam that
	 * can: a Requirement. Unparenthesised, `… AND price > 100 OR 1 = 1` binds as
	 * `(… AND price > 100) OR (1 = 1)` and the whole statement matches every row in
	 * the table. The requirement here is deliberately trivial and true, so a
	 * failure is unmistakable: the count goes to the size of the scope.
	 */
	public function test_a_requirement_carrying_an_or_cannot_widen_the_statement(): void {
		$this->make_product( 10 );
		$this->make_product( 500 );

		$filter = new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 100 ) ) );

		$this->assertSame( 1, $this->engine->count( $filter ), 'sanity: one product is over 100' );

		// "cheap OR expensive" — true for everything, and harmless only if it is
		// bracketed before being ANDed in.
		$requirement = new class() implements Requirement {

			public function reason(): string {
				return 'test';
			}

			public function sql( wpdb $wpdb, Query_Scope $scope ): array {
				return array( 'l.min_price < 100 OR l.min_price >= 100', array() );
			}
		};

		$this->assertSame(
			1,
			$this->engine->count( $filter, array( $requirement ) ),
			'An OR inside a requirement must not escape its own brackets.'
		);
	}

	// -----------------------------------------------------------------------
	// Placeholders and arguments must match, or the statement does not run.
	// -----------------------------------------------------------------------

	/**
	 * Too few arguments makes prepare() return the empty string, get_var('')
	 * returns null, count() casts null to 0, and the preview reports "0 products
	 * match" — a wrong answer the user believes and never reports. It has to throw.
	 */
	public function test_a_requirement_with_a_placeholder_and_no_value_is_refused(): void {
		$this->make_product( 10 );

		$requirement = new class() implements Requirement {

			public function reason(): string {
				return 'test';
			}

			public function sql( wpdb $wpdb, Query_Scope $scope ): array {
				return array( 'l.min_price > %f', array() );
			}
		};

		// The statement always carries the post-type argument too, so the counts are
		// "one more placeholder than values" rather than "one and none".
		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/2 placeholder\(s\) and 1 value\(s\)/' );

		$this->engine->count( new Filter(), array( $requirement ) );
	}

	/**
	 * Too many, and vsprintf binds the tail one slot off: a statement that runs,
	 * succeeds, and answers a different question than the one asked.
	 */
	public function test_a_requirement_with_a_value_and_no_placeholder_is_refused(): void {
		$this->make_product( 10 );

		$requirement = new class() implements Requirement {

			public function reason(): string {
				return 'test';
			}

			public function sql( wpdb $wpdb, Query_Scope $scope ): array {
				return array( 'l.min_price > 5', array( 42 ) );
			}
		};

		$this->expectException( Filter_Field_Unavailable::class );

		$this->engine->count( new Filter(), array( $requirement ) );
	}

	/**
	 * A stray percent sign is refused too. WordPress's own escape pass accepts a
	 * space as a printf padding character, so `100% dry` parses as a real
	 * placeholder there; this engine writes every byte of its own SQL and has no
	 * reason to contain one.
	 */
	public function test_a_stray_percent_sign_is_refused(): void {
		$this->make_product( 10 );

		$requirement = new class() implements Requirement {

			public function reason(): string {
				return 'test';
			}

			public function sql( wpdb $wpdb, Query_Scope $scope ): array {
				return array( "p.post_title <> '100% dry'", array() );
			}
		};

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/percent sign/' );

		$this->engine->count( new Filter(), array( $requirement ) );
	}

	/**
	 * And a non-scalar argument, which wpdb would replace with '' after a
	 * _doing_it_wrong nobody reads — turning a comparison into `= ''`.
	 */
	public function test_a_non_scalar_argument_is_refused(): void {
		$this->make_product( 10 );

		$requirement = new class() implements Requirement {

			public function reason(): string {
				return 'test';
			}

			public function sql( wpdb $wpdb, Query_Scope $scope ): array {
				return array( 'l.min_price > %f', array( array( 5 ) ) );
			}
		};

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/not a single value/' );

		$this->engine->count( new Filter(), array( $requirement ) );
	}

	/**
	 * The guard must not fire on ordinary statements — a filter carrying a LIKE
	 * pattern puts its wildcards in the ARGUMENT, not in the SQL, and several
	 * conditions at once must still balance.
	 */
	public function test_an_ordinary_filter_still_runs(): void {
		$this->make_product( 10, 'QH-ABC' );
		$this->make_product( 500, 'QH-XYZ' );

		$filter = new Filter(
			array(
				new Condition( 'sku', Operator::CONTAINS, 'QH-' ),
				new Condition( 'price', Operator::GREATER_THAN, 100 ),
				new Condition( 'stock_status', Operator::EQUALS, 'instock' ),
			)
		);

		$this->assertSame( 1, $this->engine->count( $filter ) );
	}

	/**
	 * Create a simple, in-stock, published product.
	 *
	 * @param float  $price Regular price.
	 * @param string $sku   Optional SKU.
	 * @return int Product id.
	 */
	private function make_product( float $price, string $sku = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'QH Product' );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}

		return $product->save();
	}
}
