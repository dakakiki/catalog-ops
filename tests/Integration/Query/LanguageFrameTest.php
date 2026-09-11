<?php
/**
 * Integration tests for the language frame — the WPML language a filter carries.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 * WPML itself is never required: the engine reads `icl_translations` and asks the
 * database whether it exists, so the table the bootstrap creates is the whole of
 * what these tests have to stand in for.
 *
 * @package CatalogOps\Tests\Integration\Query
 */

namespace CatalogOps\Tests\Integration\Query;

use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Query_Engine
 * @covers \CatalogOps\Query\Filter
 */
final class LanguageFrameTest extends WP_UnitTestCase {

	private Query_Engine $engine;
	private string $translations;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		global $wpdb;
		$this->engine       = new Query_Engine( $wpdb );
		$this->translations = $wpdb->prefix . 'icl_translations';
	}

	public function test_a_filter_with_no_language_sees_every_product(): void {
		$english     = $this->make_product( 10 );
		$serbian     = $this->make_product( 20 );
		$untranslated = $this->make_product( 30 );

		$this->translate( $english, 'en' );
		$this->translate( $serbian, 'sr' );

		$this->assertEqualsCanonicalizing(
			array( $english, $serbian, $untranslated ),
			$this->engine->resolve( new Filter() )
		);
	}

	public function test_a_language_confines_the_result_to_that_language(): void {
		$english = $this->make_product( 10 );
		$serbian = $this->make_product( 20 );

		$this->translate( $english, 'en' );
		$this->translate( $serbian, 'sr' );

		$this->assertSame( array( $serbian ), $this->engine->resolve( $this->in( 'sr' ) ) );
		$this->assertSame( array( $english ), $this->engine->resolve( $this->in( 'en' ) ) );
	}

	/**
	 * The two languages partition the catalogue, minus whatever belongs to neither.
	 * A count that does not add up is the signature of a join that fans out.
	 */
	public function test_the_languages_partition_the_catalogue_and_the_join_never_duplicates(): void {
		$english = array( $this->make_product( 10 ), $this->make_product( 11 ) );
		$serbian = array( $this->make_product( 20 ), $this->make_product( 21 ), $this->make_product( 22 ) );

		foreach ( $english as $id ) {
			$this->translate( $id, 'en' );
		}
		foreach ( $serbian as $id ) {
			$this->translate( $id, 'sr' );
		}

		$this->assertSame( 2, $this->engine->count( $this->in( 'en' ) ) );
		$this->assertSame( 3, $this->engine->count( $this->in( 'sr' ) ) );
		$this->assertSame( 5, $this->engine->count( new Filter() ) );

		// resolve() and count() must answer the same question; they assemble the
		// same statement with a different projection, and this is what says so.
		$this->assertCount( $this->engine->count( $this->in( 'sr' ) ), $this->engine->resolve( $this->in( 'sr' ) ) );
	}

	/**
	 * The state a CSV import leaves behind: a product WPML has never indexed. It is
	 * invisible in every language on purpose — WPML cannot see it either, and
	 * `SitePress::make_duplicate()` refuses to translate it.
	 */
	public function test_a_product_with_no_translation_row_is_invisible_in_every_language(): void {
		$known   = $this->make_product( 10 );
		$unknown = $this->make_product( 20 );

		$this->translate( $known, 'sr' );

		$this->assertSame( array( $known ), $this->engine->resolve( $this->in( 'sr' ) ) );
		$this->assertSame( array(), $this->engine->resolve( $this->in( 'en' ) ) );

		// And still visible when nothing is asking about languages.
		$this->assertContains( $unknown, $this->engine->resolve( new Filter() ) );
	}

	/**
	 * WPML's "All languages" places no constraint at all, and it reaches the filter
	 * as the literal string `all` because that is what SitePress returns for it.
	 */
	public function test_all_languages_is_the_same_statement_as_no_language(): void {
		$english = $this->make_product( 10 );
		$serbian = $this->make_product( 20 );

		$this->translate( $english, 'en' );
		$this->translate( $serbian, 'sr' );

		$all = new Filter( array(), Filter::RELATION_AND, null, 'all' );

		$this->assertNull( $all->language() );
		$this->assertEqualsCanonicalizing(
			$this->engine->resolve( new Filter() ),
			$this->engine->resolve( $all )
		);
	}

	/**
	 * The trap the specification got wrong by reasoning from taxonomies: a variation
	 * carries its OWN `icl_translations` row, so a variation-scope filter asks about
	 * the variation and never about its parent. Anchoring on the parent returns a
	 * plausible, non-empty, wrong set — which is why the parent here is deliberately
	 * in the other language from its variations.
	 */
	public function test_a_variation_is_anchored_on_its_own_row_not_its_parent(): void {
		list( $parent, $variations ) = $this->make_variable_product( array( 'Small' => 10, 'Large' => 90 ) );

		$this->translate( $parent, 'en', 'post_product' );
		foreach ( $variations as $variation_id ) {
			$this->translate( $variation_id, 'sr', 'post_product_variation' );
		}

		$serbian = $this->engine->resolve( $this->in( 'sr', Query_Scope::VARIATION ) );
		$english = $this->engine->resolve( $this->in( 'en', Query_Scope::VARIATION ) );

		$this->assertEqualsCanonicalizing( array_values( $variations ), $serbian );

		// The parent's English row must not pull its variations into English.
		$this->assertSame( array(), $english );
	}

	/**
	 * The element type is part of the join, so a product and a variation that
	 * happened to share an id could never be confused for one another. They cannot
	 * share an id in WordPress, so this pins the clause rather than a live risk —
	 * but the clause is what would go wrong if the type were dropped from it.
	 */
	public function test_the_element_type_follows_the_scope(): void {
		$product = $this->make_product( 10 );

		// Indexed under the wrong element type: the row exists, the language matches,
		// and it must still not match a product-scope filter.
		$this->translate( $product, 'sr', 'post_product_variation' );

		$this->assertSame( array(), $this->engine->resolve( $this->in( 'sr' ) ) );
	}

	/**
	 * The "nothing matched here, try Variations" hint asks the same filter of the
	 * other scope. If it dropped the language it would offer a count drawn from
	 * languages the user is not working in and can never reach.
	 */
	public function test_the_other_scope_hint_keeps_the_language(): void {
		$filter = $this->in( 'sr' )->for_scope( Query_Scope::VARIATION );

		$this->assertSame( 'sr', $filter->language() );
		$this->assertSame( Query_Scope::VARIATION, $filter->scope() );
	}

	/**
	 * A language sits beside the conditions rather than among them, so both must
	 * narrow: this is the case where a language-only filter would look right and a
	 * condition-only one would too.
	 */
	public function test_the_language_and_the_conditions_both_narrow(): void {
		$cheap_sr = $this->make_product( 10 );
		$dear_sr  = $this->make_product( 90 );
		$dear_en  = $this->make_product( 95 );

		$this->translate( $cheap_sr, 'sr' );
		$this->translate( $dear_sr, 'sr' );
		$this->translate( $dear_en, 'en' );

		$filter = $this->in( 'sr' )->with( new Condition( 'price', Operator::GREATER_THAN, 50 ) );

		$this->assertSame( array( $dear_sr ), $this->engine->resolve( $filter ) );
	}

	/**
	 * Every filter written before this existed carries no `language` key at all, and
	 * has to go on meaning exactly what it meant. Absent, null and the empty string
	 * are the same unconfined filter.
	 */
	public function test_a_filter_persisted_before_languages_is_unconfined(): void {
		$serbian = $this->make_product( 10 );
		$this->translate( $serbian, 'sr' );

		$before = Filter::from_array(
			array(
				'relation'   => 'AND',
				'scope'      => 'product',
				'conditions' => array(),
			)
		);

		$this->assertNull( $before->language() );
		$this->assertSame( array( $serbian ), $this->engine->resolve( $before ) );
	}

	/**
	 * A filter with no language must emit the statement it emitted before this
	 * existed — no join, no placeholder, nothing. That is the whole of the
	 * backward-compatibility promise, and it is a property of the SQL text rather
	 * than of any one result set, so this asserts the text.
	 *
	 * The refusal that guards the other direction — a language on a site whose
	 * translations table has gone — belongs to the unit suite, where a `wpdb` with
	 * no database behind it is the natural way to say "the table is not there".
	 */
	public function test_a_filter_with_no_language_emits_no_translations_join(): void {
		$without = $this->engine->count_sql( new Filter() );
		$with    = $this->engine->count_sql( $this->in( 'sr' ) );

		$this->assertStringNotContainsString( 'icl_translations', $without );
		$this->assertStringContainsString( 'icl_translations', $with );

		// The language is bound, never interpolated.
		$this->assertStringContainsString( "language_code = 'sr'", $with );
		$this->assertStringContainsString( "element_type = 'post_product'", $with );
	}

	/**
	 * The variation scope names the variation element type, and nothing else about
	 * the statement changes. Getting this wrong is the failure that returns a
	 * plausible, non-empty, wrong set.
	 */
	public function test_the_variation_scope_names_the_variation_element_type(): void {
		$sql = $this->engine->count_sql( $this->in( 'sr', Query_Scope::VARIATION ) );

		$this->assertStringContainsString( "element_type = 'post_product_variation'", $sql );
	}

	/**
	 * A filter aimed at one language and one aimed at another are different
	 * questions, and filter_json has to be able to tell them apart three days later
	 * on a cron tick that cannot ask anybody.
	 */
	public function test_the_language_survives_a_round_trip_through_filter_json(): void {
		$filter = $this->in( 'sr' )->with( new Condition( 'price', Operator::GREATER_THAN, 50 ) );

		$json    = (string) wp_json_encode( $filter->to_array() );
		$rebuilt = Filter::from_array( (array) json_decode( $json, true ) );

		$this->assertSame( 'sr', $rebuilt->language() );
		$this->assertSame( $filter->scope(), $rebuilt->scope() );
		$this->assertCount( 1, $rebuilt->conditions() );
	}

	/**
	 * A filter confined to a language, aimed at products.
	 *
	 * @param string           $language Language code.
	 * @param Query_Scope|null $scope    Scope, products by default.
	 */
	private function in( string $language, ?Query_Scope $scope = null ): Filter {
		return new Filter( array(), Filter::RELATION_AND, $scope, $language );
	}

	/**
	 * Index an object in `icl_translations` the way WPML would.
	 *
	 * One row per (element_type, element_id) — the table's own unique key — with the
	 * object's id doubling as the trid, which is enough here: nothing under test
	 * follows a trid to find a translation of anything.
	 *
	 * @param int    $object_id    Post id.
	 * @param string $language     Language code.
	 * @param string $element_type WPML element type.
	 */
	private function translate( int $object_id, string $language, string $element_type = 'post_product' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$this->translations,
			array(
				'element_type'  => $element_type,
				'element_id'    => $object_id,
				'trid'          => $object_id,
				'language_code' => $language,
			),
			array( '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * A simple, published, priced product.
	 *
	 * @param float $price Regular price.
	 */
	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'LF Product' );
		$product->set_regular_price( (string) $price );
		$product->set_stock_status( 'instock' );

		return (int) $product->save();
	}

	/**
	 * A variable product with one variation per named size.
	 *
	 * @param array<string, float> $sizes Size name => price.
	 * @return array{0: int, 1: array<string, int>} Parent id and size => variation id.
	 */
	private function make_variable_product( array $sizes ): array {
		$taxonomy = $this->ensure_size_attribute();

		$terms = array();
		foreach ( array_keys( $sizes ) as $name ) {
			$terms[ $name ] = $this->ensure_term( $name, $taxonomy );
		}

		$parent = new WC_Product_Variable();
		$parent->set_name( 'LF Variable' );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'lfsize' ) );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( array_values( $terms ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent_id = (int) $parent->save();

		$variations = array();
		foreach ( $sizes as $name => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $taxonomy => get_term( $terms[ $name ] )->slug ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_stock_status( 'instock' );
			$variations[ $name ] = (int) $variation->save();
		}

		return array( $parent_id, $variations );
	}

	/**
	 * The size attribute taxonomy, created once.
	 */
	private function ensure_size_attribute(): string {
		$id = wc_attribute_taxonomy_id_by_name( 'lfsize' );

		if ( ! $id ) {
			wc_create_attribute(
				array(
					'name'         => 'LF Size',
					'slug'         => 'lfsize',
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			);
		}

		$taxonomy = wc_attribute_taxonomy_name( 'lfsize' );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false ) );
		}

		return $taxonomy;
	}

	/**
	 * A term in a taxonomy, created once.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 */
	private function ensure_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );

		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( $name, $taxonomy );

		return (int) $created['term_id'];
	}
}
