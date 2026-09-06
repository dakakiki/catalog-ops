<?php
/**
 * Integration tests for the query engine's correctness.
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
use WC_Product_Attribute;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Query_Engine
 * @covers \CatalogOps\Query\Filter
 * @covers \CatalogOps\Query\Condition
 */
final class QueryEngineTest extends WP_UnitTestCase {

	private Query_Engine $engine;
	private int $cat_a;
	private int $cat_b;
	private string $color_tax;
	private int $red;
	private int $blue;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$this->reset_attribute_state();

		global $wpdb;
		$this->engine = new Query_Engine( $wpdb );

		$this->cat_a = $this->ensure_term( 'QE Cat A', 'product_cat' );
		$this->cat_b = $this->ensure_term( 'QE Cat B', 'product_cat' );

		$this->color_tax = $this->ensure_color_attribute();
		$this->red       = $this->ensure_term( 'Red', $this->color_tax );
		$this->blue      = $this->ensure_term( 'Blue', $this->color_tax );
	}

	public function tear_down(): void {
		$this->reset_attribute_state();
		parent::tear_down();
	}

	public function test_empty_filter_matches_every_published_product(): void {
		$a = $this->make_product( array( 'price' => 10 ) );
		$b = $this->make_product( array( 'price' => 20 ) );

		$this->assertEqualsCanonicalizing( array( $a, $b ), $this->engine->resolve( new Filter() ) );
		$this->assertSame( 2, $this->engine->count( new Filter() ) );
	}

	public function test_price_greater_than(): void {
		$cheap = $this->make_product( array( 'price' => 10 ) );
		$mid   = $this->make_product( array( 'price' => 50 ) );
		$dear  = $this->make_product( array( 'price' => 100 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 40 ) ) )
		);

		$this->assertEqualsCanonicalizing( array( $mid, $dear ), $ids );
		$this->assertNotContains( $cheap, $ids );
	}

	public function test_price_between(): void {
		$this->make_product( array( 'price' => 10 ) );
		$in = $this->make_product( array( 'price' => 50 ) );
		$this->make_product( array( 'price' => 100 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'price', Operator::BETWEEN, array( 40, 60 ) ) ) )
		);

		$this->assertSame( array( $in ), $ids );
	}

	public function test_stock_status_in(): void {
		$in1 = $this->make_product( array( 'stock_status' => 'instock' ) );
		$out = $this->make_product( array( 'stock_status' => 'outofstock' ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'stock_status', Operator::IN, array( 'outofstock' ) ) ) )
		);

		$this->assertSame( array( $out ), $ids );
		$this->assertNotContains( $in1, $ids );
	}

	public function test_stock_quantity_less_than(): void {
		$low  = $this->make_product( array( 'stock_qty' => 3 ) );
		$high = $this->make_product( array( 'stock_qty' => 200 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'stock_quantity', Operator::LESS_THAN, 10 ) ) )
		);

		$this->assertSame( array( $low ), $ids );
		$this->assertNotContains( $high, $ids );
	}

	public function test_category_membership(): void {
		$in_a = $this->make_product( array( 'category' => $this->cat_a ) );
		$in_b = $this->make_product( array( 'category' => $this->cat_b ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::IN, array( $this->cat_a ) ) ) )
		);

		$this->assertSame( array( $in_a ), $ids );
		$this->assertNotContains( $in_b, $ids );
	}

	public function test_attribute_term(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'attribute:' . $this->color_tax, Operator::IN, array( $this->red ) ) ) )
		);

		$this->assertSame( array( $red_product ), $ids );
		$this->assertNotContains( $blue_product, $ids );
	}

	public function test_attribute_in_multiple_terms(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );
		$plain        = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter(
				array(
					new Condition( 'attribute:' . $this->color_tax, Operator::IN, array( $this->red, $this->blue ) ),
				)
			)
		);

		$this->assertEqualsCanonicalizing( array( $red_product, $blue_product ), $ids );
		$this->assertNotContains( $plain, $ids );
	}

	public function test_attribute_exists_matches_any_value(): void {
		$red_product  = $this->make_product( array( 'color' => $this->red ) );
		$blue_product = $this->make_product( array( 'color' => $this->blue ) );
		$plain        = $this->make_product( array( 'price' => 10 ) );

		// "Filter by Colour, any value": every product that has a colour term.
		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'attribute:' . $this->color_tax, Operator::EXISTS ) ) )
		);

		$this->assertEqualsCanonicalizing( array( $red_product, $blue_product ), $ids );
		$this->assertNotContains( $plain, $ids );
	}

	public function test_category_in_multiple_terms(): void {
		$in_a    = $this->make_product( array( 'category' => $this->cat_a ) );
		$in_b    = $this->make_product( array( 'category' => $this->cat_b ) );
		$neither = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'category', Operator::IN, array( $this->cat_a, $this->cat_b ) ) )
			)
		);

		$this->assertEqualsCanonicalizing( array( $in_a, $in_b ), $ids );
		$this->assertNotContains( $neither, $ids );
	}

	public function test_two_category_conditions_are_anded(): void {
		// Each becomes its own join, so they need distinct aliases — and a product
		// in both categories must be returned once, not twice.
		$both  = $this->make_product( array( 'category' => $this->cat_a ) );
		wp_set_object_terms( $both, array( $this->cat_a, $this->cat_b ), 'product_cat' );
		$only_a = $this->make_product( array( 'category' => $this->cat_a ) );

		$ids = $this->engine->resolve(
			new Filter(
				array(
					new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
					new Condition( 'category', Operator::IN, array( $this->cat_b ) ),
				)
			)
		);

		$this->assertSame( array( $both ), $ids );
		$this->assertNotContains( $only_a, $ids );
	}

	public function test_or_relation_keeps_category_membership_a_disjunction(): void {
		// Joins are ANDs, so an OR filter must not use one. If it did, this would
		// return only the products matching both halves instead of either.
		$in_a  = $this->make_product( array( 'category' => $this->cat_a, 'price' => 5 ) );
		$cheap = $this->make_product( array( 'price' => 5 ) );
		$other = $this->make_product( array( 'price' => 500 ) );

		$ids = $this->engine->resolve(
			new Filter(
				array(
					new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
					new Condition( 'price', Operator::LESS_THAN, 10 ),
				),
				Filter::RELATION_OR
			)
		);

		$this->assertContains( $in_a, $ids );
		$this->assertContains( $cheap, $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function test_a_product_in_several_of_the_chosen_categories_appears_once(): void {
		$product = $this->make_product( array( 'category' => $this->cat_a ) );
		wp_set_object_terms( $product, array( $this->cat_a, $this->cat_b ), 'product_cat' );

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'category', Operator::IN, array( $this->cat_a, $this->cat_b ) ) )
			)
		);

		$this->assertSame( array( $product ), $ids );
	}

	public function test_category_not_in_excludes_only_those_terms(): void {
		$in_a  = $this->make_product( array( 'category' => $this->cat_a ) );
		$in_b  = $this->make_product( array( 'category' => $this->cat_b ) );
		$plain = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::NOT_IN, array( $this->cat_a ) ) ) )
		);

		$this->assertNotContains( $in_a, $ids );
		$this->assertContains( $in_b, $ids );
		$this->assertContains( $plain, $ids );
	}

	public function test_a_term_that_does_not_exist_matches_nothing(): void {
		// The clause resolves term ids to term_taxonomy_ids before querying, so a
		// term with no row resolves to an empty set. "Has one of these" must then
		// match nothing rather than quietly dropping the condition and matching
		// the whole catalogue.
		$this->make_product( array( 'category' => $this->cat_a ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::IN, array( 99999999 ) ) ) )
		);

		$this->assertSame( array(), $ids );
	}

	/**
	 * "Not equal to" excludes, on every path that can be asked it.
	 *
	 * It used to include. `taxonomy_clause()` and `variation_attribute_clause()`
	 * each kept their own list of which operators were negative, both listed
	 * `NOT_IN` and `NOT_EXISTS`, and neither remembered `NOT_EQUALS` — so it fell
	 * through to the *positive* membership branch and `category != 5` returned
	 * exactly the products it had been asked to leave out. An exclusion that
	 * inverts is the worst shape a filter bug can take: the count looks
	 * reasonable, the preview agrees with the run, and the edit lands on precisely
	 * the wrong products. `meta_clause()` kept a third copy of the same rule and
	 * happened to get it right, which is why nobody noticed.
	 *
	 * The rule lives in {@see Operator::is_negative()} now, so these three paths
	 * cannot disagree again.
	 */
	public function test_not_equals_excludes_rather_than_includes(): void {
		$inside  = $this->make_product( array( 'category' => $this->cat_a ) );
		$outside = $this->make_product( array( 'category' => $this->cat_b ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::NOT_EQUALS, $this->cat_a ) ) )
		);

		$this->assertNotContains( $inside, $ids, 'The excluded category came back.' );
		$this->assertContains( $outside, $ids );

		// Its positive twin still means what it says, so the pair is symmetric.
		$positive = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::EQUALS, $this->cat_a ) ) )
		);

		$this->assertContains( $inside, $positive );
		$this->assertNotContains( $outside, $positive );
	}

	public function test_not_equals_on_a_meta_key_keeps_objects_without_it(): void {
		// The path that was already right, pinned so the shared rule cannot break
		// it: an exclusion has to keep the objects that carry no such value at all.
		// They are, definitively, not that brand.
		$acme      = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$globex    = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );
		$unbranded = $this->make_product( array() );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::NOT_EQUALS, 'Acme' ) ) )
		);

		$this->assertNotContains( $acme, $ids );
		$this->assertContains( $globex, $ids );
		$this->assertContains( $unbranded, $ids );
	}

	public function test_not_equals_on_a_term_that_does_not_exist_matches_everything(): void {
		// The sentinel branch had the same split rule: excluding a term nothing can
		// carry excludes nobody, and asking it with != used to answer "nothing".
		$product = $this->make_product( array( 'category' => $this->cat_a ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::NOT_EQUALS, 99999999 ) ) )
		);

		$this->assertContains( $product, $ids );
	}

	public function test_not_in_a_term_that_does_not_exist_matches_everything(): void {
		// The mirror of the above: nothing can carry a term that is not there, so
		// excluding it excludes nobody.
		$product = $this->make_product( array( 'category' => $this->cat_a ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'category', Operator::NOT_IN, array( 99999999 ) ) ) )
		);

		$this->assertContains( $product, $ids );
	}

	public function test_meta_in_multiple_values(): void {
		$acme   = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$globex = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );
		$hooli  = $this->make_product( array( 'meta' => array( '_brand' => 'Hooli' ) ) );

		$ids = $this->engine->resolve(
			new Filter(
				array( new Condition( 'meta:_brand', Operator::IN, array( 'Acme', 'Globex' ) ) )
			)
		);

		$this->assertEqualsCanonicalizing( array( $acme, $globex ), $ids );
		$this->assertNotContains( $hooli, $ids );
	}

	/**
	 * The shape, not the answer — because the answer is identical either way and
	 * that is exactly how this went unnoticed.
	 *
	 * The rule this engine is built around is that positive set membership is
	 * JOINed, never tested as `l.product_id IN (SELECT …)`, because every one of
	 * those invites MySQL to re-plan the whole query. It was applied to categories
	 * and tags and never carried across to meta — so brand, the one meta field the
	 * UI offers, kept writing the hazardous form. Measured on the live catalogue on
	 * 2026-09-06, with each brand on ~10,000 products: four brands with nothing else
	 * selected did not finish inside 45 seconds, and took 4.4s once joined. The user
	 * met it as a filter that stopped responding.
	 *
	 * A results assertion cannot catch that — both shapes return the same rows —
	 * so this reads the SQL the engine actually emits.
	 */
	public function test_positive_meta_membership_is_joined_rather_than_tested(): void {
		$this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );

		$seen = $this->capture_sql(
			function (): void {
				$this->engine->count(
					new Filter(
						array(
							new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
							new Condition( 'meta:_brand', Operator::IN, array( 'Acme', 'Globex' ) ),
						)
					)
				);
			}
		);

		$this->assertNotSame( '', $seen, 'no query was captured, so nothing was tested' );
		$this->assertStringContainsString( 'co_meta1', $seen, 'the meta condition did not become a join' );
		$this->assertMatchesRegularExpression( '/INNER JOIN \(\s*SELECT DISTINCT pm\.post_id/', $seen );
		$this->assertStringNotContainsString( 'l.product_id IN (', $seen );
	}

	/**
	 * Negation keeps the tested form on purpose: an anti-join gives the optimiser no
	 * join order to re-plan around, and a join would silently drop every product
	 * that has no such meta row — which is precisely the set an exclusion must keep.
	 */
	public function test_meta_exclusion_stays_out_of_the_join(): void {
		$this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );

		$seen = $this->capture_sql(
			function (): void {
				$this->engine->count(
					new Filter( array( new Condition( 'meta:_brand', Operator::NOT_IN, array( 'Acme' ) ) ) )
				);
			}
		);

		$this->assertStringContainsString( 'l.product_id NOT IN (', $seen );
		$this->assertStringNotContainsString( 'co_meta', $seen );
	}

	/**
	 * A join is an AND, so under OR the condition has to stay in the WHERE or it
	 * would quietly narrow the filter instead of widening it.
	 */
	public function test_meta_membership_under_or_is_not_joined(): void {
		$this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );

		$seen = $this->capture_sql(
			function (): void {
				$this->engine->count(
					new Filter(
						array(
							new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
							new Condition( 'meta:_brand', Operator::IN, array( 'Acme' ) ),
						),
						Filter::RELATION_OR
					)
				);
			}
		);

		$this->assertStringContainsString( 'l.product_id IN (', $seen );
		$this->assertStringNotContainsString( 'co_meta', $seen );
	}

	/**
	 * Run something and return the longest statement it sent to MySQL — the engine's
	 * own, rather than the small lookups around it.
	 *
	 * @param callable $run What to run.
	 */
	private function capture_sql( callable $run ): string {
		$seen = '';

		$watch = static function ( $query ) use ( &$seen ) {
			if ( str_contains( $query, 'wc_product_meta_lookup' ) && strlen( $query ) > strlen( $seen ) ) {
				$seen = $query;
			}

			return $query;
		};

		add_filter( 'query', $watch );

		try {
			$run();
		} finally {
			remove_filter( 'query', $watch );
		}

		return $seen;
	}

	public function test_sku_contains_search(): void {
		$alpha = $this->make_product( array( 'sku' => 'COPS-ALPHA-1' ) );
		$beta  = $this->make_product( array( 'sku' => 'COPS-BETA-2' ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'sku', Operator::CONTAINS, 'ALPHA' ) ) )
		);

		$this->assertSame( array( $alpha ), $ids );
		$this->assertNotContains( $beta, $ids );
	}

	public function test_meta_equals(): void {
		$acme  = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$other = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::EQUALS, 'Acme' ) ) )
		);

		$this->assertSame( array( $acme ), $ids );
		$this->assertNotContains( $other, $ids );
	}

	public function test_meta_not_in_keeps_products_that_have_no_such_meta(): void {
		// "Not this brand" has to keep the unbranded products: they are not that
		// brand either. Asking it as `IN (… meta_value != x)` would answer a
		// different question — "has some other brand" — and drop them.
		$acme   = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$globex = $this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );
		$plain  = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::NOT_IN, array( 'Acme' ) ) ) )
		);

		$this->assertNotContains( $acme, $ids );
		$this->assertContains( $globex, $ids );
		$this->assertContains( $plain, $ids );
	}

	public function test_meta_not_equals_keeps_products_that_have_no_such_meta(): void {
		$acme  = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$plain = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::NOT_EQUALS, 'Acme' ) ) )
		);

		$this->assertNotContains( $acme, $ids );
		$this->assertContains( $plain, $ids );
	}

	public function test_meta_not_exists_matches_only_products_without_the_key(): void {
		$acme  = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$plain = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::NOT_EXISTS ) ) )
		);

		$this->assertSame( array( $plain ), $ids );
		$this->assertNotContains( $acme, $ids );
	}

	public function test_meta_not_in_nothing_excludes_nobody(): void {
		// An empty exclusion list is not a question. It must not decay into
		// "has no brand at all", which is what an empty value test would leave.
		$acme  = $this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$plain = $this->make_product( array( 'price' => 10 ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_brand', Operator::NOT_IN, array() ) ) )
		);

		$this->assertEqualsCanonicalizing( array( $acme, $plain ), $ids );
	}

	public function test_an_exclusion_narrows_an_inclusion_rather_than_replacing_it(): void {
		// The shape the filter UI emits: one category joined, one brand excluded.
		// The join and the anti-join have to compose.
		$keep    = $this->make_product(
			array(
				'category' => $this->cat_a,
				'meta'     => array( '_brand' => 'Globex' ),
			)
		);
		$wrong   = $this->make_product(
			array(
				'category' => $this->cat_a,
				'meta'     => array( '_brand' => 'Acme' ),
			)
		);
		$off_cat = $this->make_product(
			array(
				'category' => $this->cat_b,
				'meta'     => array( '_brand' => 'Globex' ),
			)
		);

		$ids = $this->engine->resolve(
			new Filter(
				array(
					new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
					new Condition( 'meta:_brand', Operator::NOT_IN, array( 'Acme' ) ),
				)
			)
		);

		$this->assertSame( array( $keep ), $ids );
		$this->assertNotContains( $wrong, $ids );
		$this->assertNotContains( $off_cat, $ids );
	}

	public function test_meta_numeric_greater_than(): void {
		$this->make_product( array( 'meta' => array( '_cost' => '5.00' ) ) );
		$expensive = $this->make_product( array( 'meta' => array( '_cost' => '42.50' ) ) );

		$ids = $this->engine->resolve(
			new Filter( array( new Condition( 'meta:_cost', Operator::GREATER_THAN, 10 ) ) )
		);

		$this->assertSame( array( $expensive ), $ids );
	}

	public function test_multiple_conditions_are_anded(): void {
		$match     = $this->make_product( array( 'price' => 50, 'category' => $this->cat_a ) );
		$wrong_cat = $this->make_product( array( 'price' => 50, 'category' => $this->cat_b ) );
		$too_cheap = $this->make_product( array( 'price' => 5, 'category' => $this->cat_a ) );

		$filter = new Filter(
			array(
				new Condition( 'price', Operator::GREATER_OR_EQUAL, 20 ),
				new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
			)
		);

		$ids = $this->engine->resolve( $filter );

		$this->assertSame( array( $match ), $ids );
		$this->assertNotContains( $wrong_cat, $ids );
		$this->assertNotContains( $too_cheap, $ids );
	}

	/**
	 * Pins that a field the engine cannot answer is refused, not dropped.
	 *
	 * The filter reads "in category A *and* on clearance", and `acf:clearance` is
	 * the shape a third-party field key takes — a plugin's own prefix the engine
	 * has never claimed. In 0.7.1 `clause_for()` fell through to
	 * `return array( '', array() )`, `build_where()` skipped the empty fragment,
	 * and the filter ran as "in category A" alone: both products below came back,
	 * a strictly *wider* set than was asked for. Preview and run resolved the same
	 * widened filter and agreed, so nothing downstream could notice — which is why
	 * widening is the one direction that may never happen quietly.
	 */
	public function test_an_unknown_field_is_refused_rather_than_silently_widening(): void {
		// The two products 0.7.1 handed back for a filter that asked for neither.
		$this->make_product( array( 'category' => $this->cat_a ) );
		$this->make_product( array( 'category' => $this->cat_a ) );

		$filter = new Filter(
			array(
				new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
				new Condition( 'acf:clearance', Operator::EQUALS, 1 ),
			)
		);

		try {
			$this->engine->resolve( $filter );
			$this->fail( 'Expected the engine to refuse a filter naming an unknown field.' );
		} catch ( Filter_Field_Unavailable $e ) {
			// The message names the offending key so the user can repair the filter.
			$this->assertStringContainsString( 'acf:clearance', $e->getMessage() );
		}
	}

	/**
	 * Pins that the refusal covers `count()` as well as `resolve()`.
	 *
	 * The two public entry points have to agree: `count()` is what the preview
	 * asks, `resolve()` is what the freezing path asks. A fix on only one of them
	 * would let the preview refuse while the write went ahead against the wider
	 * set — or the reverse, a preview promising a count the run then refuses.
	 * 0.7.1 answered 2 here: every product in the category, clearance ignored.
	 */
	public function test_count_refuses_an_unknown_field_too(): void {
		$this->make_product( array( 'category' => $this->cat_a ) );
		$this->make_product( array( 'category' => $this->cat_a ) );

		$filter = new Filter(
			array(
				new Condition( 'category', Operator::IN, array( $this->cat_a ) ),
				new Condition( 'acf:clearance', Operator::EQUALS, 1 ),
			)
		);

		try {
			$this->engine->count( $filter );
			$this->fail( 'Expected count() to refuse a filter naming an unknown field.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'acf:clearance', $e->getMessage() );
		}
	}

	/**
	 * Pins that a known field asked an impossible comparison is refused.
	 *
	 * The field key is fine in both halves; it is the operator the column cannot
	 * make — "price contains 5" on a numeric column, "sku greater than 5" on a
	 * text one. 0.7.1 dropped each condition whole, so a one-condition filter
	 * became no filter and matched the entire published catalogue.
	 *
	 * Two throws, so two assertions: PHPUnit stops at the first expected
	 * exception, and the second half would never run under one `expectException`.
	 */
	public function test_a_comparison_the_column_cannot_make_is_refused(): void {
		// On 0.7.1 this product answered both filters below.
		$this->make_product(
			array(
				'price' => 50,
				'sku'   => 'COPS-REFUSE-1',
			)
		);

		try {
			$this->engine->resolve(
				new Filter( array( new Condition( 'price', Operator::CONTAINS, '5' ) ) )
			);
			$this->fail( 'Expected a "contains" comparison on price to be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'price', $e->getMessage() );
		}

		try {
			$this->engine->resolve(
				new Filter( array( new Condition( 'sku', Operator::GREATER_THAN, 5 ) ) )
			);
			$this->fail( 'Expected a "greater than" comparison on sku to be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'sku', $e->getMessage() );
		}
	}

	/**
	 * Pins that a half-built "between" is refused rather than ignored.
	 *
	 * A range with only its low end is a filter the user is still writing, and it
	 * cannot be run as asked. 0.7.1 returned an empty fragment for it, which under
	 * a single-condition filter is the widest possible answer: every published
	 * product, including the one below that is nowhere near the range.
	 */
	public function test_a_between_filter_with_one_end_is_refused(): void {
		$this->make_product( array( 'price' => 500 ) );

		try {
			$this->engine->resolve(
				new Filter( array( new Condition( 'price', Operator::BETWEEN, array( 10 ) ) ) )
			);
			$this->fail( 'Expected a one-ended "between" filter to be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'between', $e->getMessage() );
		}
	}

	/**
	 * Pins that a prefix with no identifier after it is refused.
	 *
	 * `meta:` names no key. It is not a typo anyone types by hand: it is what a
	 * site whose `catalogops_brand_meta_key` filter returns an empty string emits
	 * from `/fields/brands`, so the brand dropdown builds a condition on nothing.
	 * In 0.7.1 `meta_clause()` was still reached with an empty key and returned an
	 * empty fragment, so "brand is Acme" quietly meant "every product".
	 */
	public function test_a_meta_prefix_with_no_key_is_refused(): void {
		$this->make_product( array( 'meta' => array( '_brand' => 'Acme' ) ) );
		$this->make_product( array( 'meta' => array( '_brand' => 'Globex' ) ) );

		try {
			$this->engine->resolve(
				new Filter( array( new Condition( 'meta:', Operator::IN, array( 'Acme' ) ) ) )
			);
			$this->fail( 'Expected a "meta:" key with nothing after the colon to be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'meta:', $e->getMessage() );
		}
	}

	/**
	 * The same half-built range over a meta key, which widens by a different route.
	 *
	 * A meta condition keeps its subquery whatever the value test comes to, so an
	 * empty test did not drop the condition — it decayed it into "has a `_cost` at
	 * all". Both products below carry one, so 0.7.1 answered a one-ended range with
	 * every costed product, and the price path and the meta path disagreed about
	 * the identical mistake.
	 */
	public function test_a_one_ended_between_on_a_meta_key_is_refused_too(): void {
		$this->make_product( array( 'meta' => array( '_cost' => '12.00' ) ) );
		$this->make_product( array( 'meta' => array( '_cost' => '900.00' ) ) );

		try {
			$this->engine->resolve(
				new Filter( array( new Condition( 'meta:_cost', Operator::BETWEEN, array( 10 ) ) ) )
			);
			$this->fail( 'Expected a one-ended "between" on a meta key to be refused.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertStringContainsString( 'between', $e->getMessage() );
		}
	}

	/**
	 * Create a simple product with controlled attributes.
	 *
	 * @param array<string, mixed> $args price, stock_status, stock_qty, category, color, meta.
	 * @return int Product ID.
	 */
	private function make_product( array $args ): int {
		$status = (string) ( $args['stock_status'] ?? 'instock' );
		// Keep quantity and status consistent: WooCommerce forces "instock" on
		// save when managing stock with a positive quantity.
		$quantity = (int) ( $args['stock_qty'] ?? ( 'outofstock' === $status ? 0 : 5 ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'QE Product' );
		$product->set_regular_price( (string) ( $args['price'] ?? 10 ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_stock_status( $status );

		if ( ! empty( $args['sku'] ) ) {
			$product->set_sku( (string) $args['sku'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$product->set_category_ids( array_map( 'intval', (array) $args['category'] ) );
		}

		if ( ! empty( $args['color'] ) ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) );
			$attribute->set_name( $this->color_tax );
			$attribute->set_options( array_map( 'intval', (array) $args['color'] ) );
			$attribute->set_visible( true );
			$product->set_attributes( array( $attribute ) );
		}

		foreach ( (array) ( $args['meta'] ?? array() ) as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	private function ensure_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy );

		return (int) $created['term_id'];
	}

	private function ensure_color_attribute(): string {
		$taxonomy = wc_attribute_taxonomy_name( 'color' );

		if ( ! wc_attribute_taxonomy_id_by_name( 'color' ) ) {
			wc_create_attribute(
				array(
					'name' => 'Color',
					'slug' => 'color',
					'type' => 'select',
				)
			);
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'query_var' => true ) );
		}

		return $taxonomy;
	}

	private function reset_attribute_state(): void {
		if ( taxonomy_exists( 'pa_color' ) ) {
			unregister_taxonomy( 'pa_color' );
		}

		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );
	}
}
