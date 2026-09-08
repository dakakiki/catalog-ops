<?php
/**
 * The M7 seam, end to end: a registered module answering a real filter over real
 * products.
 *
 * Everything else about the provider API is tested one piece at a time — the
 * compiler's SQL text, the registry's refusals. This is the test that proves the
 * pieces are joined: a field no core builder knows, resolved through the
 * registry, compiled to SQL, and run against a catalogue, returning the products
 * a person would have picked by hand.
 *
 * @package CatalogOps\Tests\Integration\Query\Fields
 */

namespace CatalogOps\Tests\Integration\Query\Fields;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Filter_Provider;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Query\Query_Engine
 * @covers \CatalogOps\Query\Fields\Filter_Providers
 * @covers \CatalogOps\Query\Fields\Storage_Compiler
 */
final class ProviderClauseTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}
	}

	/**
	 * The whole point, in one assertion: a module's field narrows a real query.
	 */
	public function test_a_registered_provider_answers_a_filter(): void {
		$acme   = $this->make_product( 'Acme' );
		$globex = $this->make_product( 'Globex' );

		$engine = $this->engine();

		$this->assertSame( 2, $engine->count( new Filter() ), 'sanity: both are in scope' );

		$this->assertSame(
			array( $acme ),
			$engine->resolve( new Filter( array( new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ) ) ) )
		);

		$this->assertSame(
			array( $globex ),
			$engine->resolve( new Filter( array( new Condition( 'demo:supplier', Operator::NOT_EQUALS, 'Acme' ) ) ) )
		);
	}

	/**
	 * A positive membership and its negation must partition the scope exactly. This
	 * is the property that catches a negation pushed inside a subquery: a product
	 * with no supplier row at all belongs to the exclusion, and the wrong shape
	 * drops it from both halves.
	 */
	public function test_a_module_field_partitions_the_scope_including_objects_with_no_value(): void {
		$this->make_product( 'Acme' );
		$this->make_product( 'Globex' );
		$this->make_product( null ); // No supplier at all.

		$engine = $this->engine();

		$total = $engine->count( new Filter() );
		$yes   = $engine->count( new Filter( array( new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ) ) ) );
		$no    = $engine->count( new Filter( array( new Condition( 'demo:supplier', Operator::NOT_EQUALS, 'Acme' ) ) ) );

		$this->assertSame( 3, $total );
		$this->assertSame( 1, $yes );
		$this->assertSame( 2, $no, 'The product with no supplier is not "Acme", so it belongs to the exclusion.' );
		$this->assertSame( $total, $yes + $no );
	}

	/**
	 * A module field beside a core one, under AND. The provider's clause arrives as
	 * a join and the core one as a WHERE predicate, so this also proves the two
	 * argument lists stay in the right order through `prepare()` — the failure that
	 * would otherwise run, succeed, and answer a different question.
	 */
	public function test_a_module_field_composes_with_a_core_field(): void {
		$cheap = $this->make_product( 'Acme', 10 );
		$this->make_product( 'Acme', 500 );
		$this->make_product( 'Globex', 10 );

		$filter = new Filter(
			array(
				new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ),
				new Condition( 'price', Operator::LESS_THAN, 100 ),
			)
		);

		$this->assertSame( array( $cheap ), $this->engine()->resolve( $filter ) );
	}

	/**
	 * And under OR, where a join is not available and the compiler must fall back to
	 * the semi-join rather than silently turning the OR into an AND.
	 */
	public function test_a_module_field_composes_under_an_or_relation(): void {
		$acme     = $this->make_product( 'Acme', 500 );
		$cheap    = $this->make_product( 'Globex', 10 );
		$excluded = $this->make_product( 'Globex', 500 );

		$filter = new Filter(
			array(
				new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ),
				new Condition( 'price', Operator::LESS_THAN, 100 ),
			),
			Filter::RELATION_OR
		);

		$found = $this->engine()->resolve( $filter );

		sort( $found );
		$expected = array( $acme, $cheap );
		sort( $expected );

		$this->assertSame( $expected, $found );
		$this->assertNotContains( $excluded, $found );
	}

	/**
	 * A key nobody claims is still refused, and the refusal still names it. The
	 * fall-through used to be unconditional; opening it to providers must not open
	 * it to typos.
	 */
	public function test_an_unclaimed_key_is_still_refused(): void {
		$this->make_product( 'Acme' );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/demo:typo/' );

		$this->engine()->count( new Filter( array( new Condition( 'demo:typo', Operator::EQUALS, 'x' ) ) ) );
	}

	/**
	 * And an engine with no registry at all behaves exactly as it did before —
	 * which is what the twelve bare `new Query_Engine( $wpdb )` sites rely on.
	 */
	public function test_an_engine_without_a_registry_refuses_every_module_key(): void {
		global $wpdb;

		$this->make_product( 'Acme' );

		$this->expectException( Filter_Field_Unavailable::class );

		( new Query_Engine( $wpdb ) )->count(
			new Filter( array( new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ) ) )
		);
	}

	/**
	 * An unlicensed module refuses rather than being dropped. Dropping it would
	 * turn every saved filter naming the field into a *wider* filter, which is the
	 * failure the gate exists to prevent — and it must fire in the engine, not only
	 * in a REST callback, because a schedule rehydrates a filter on a cron tick
	 * without touching a controller.
	 */
	public function test_an_unlicensed_module_refuses_inside_the_engine(): void {
		global $wpdb;

		$this->make_product( 'Acme' );

		$engine = new Query_Engine(
			$wpdb,
			new Filter_Providers( new License( true, false ), $this->provider() )
		);

		$this->expectException( License_Limited::class );

		$engine->count( new Filter( array( new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ) ) ) );
	}

	/**
	 * A module that fatals becomes a refusal, not a 500 and not a starved cron
	 * tick. Unconverted, a TypeError from someone else's plugin would escape
	 * `Schedule_Runner::fire()`'s catch as an Error rather than an Exception and
	 * take every later schedule on that tick with it.
	 */
	public function test_a_provider_that_throws_becomes_a_refusal(): void {
		global $wpdb;

		$this->make_product( 'Acme' );

		$broken = new class() implements Filter_Provider {

			public function module(): string {
				return '';
			}

			public function filter_fields(): array {
				return array(
					new Filter_Field(
						'demo:supplier',
						'Supplier',
						Filter_Control::TEXT,
						array( Operator::EQUALS ),
						array( Query_Scope::PRODUCT )
					),
				);
			}

			public function handles_filter( string $key ): bool {
				return 'demo:supplier' === $key;
			}

			public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
				throw new \RuntimeException( 'the module exploded' );
			}
		};

		$engine = new Query_Engine( $wpdb, new Filter_Providers( License::unlimited(), $broken ) );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessageMatches( '/the module exploded/' );

		$engine->count( new Filter( array( new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ) ) ) );
	}

	/**
	 * The same field twice in one filter gets two aliases. A collision would make
	 * the second join silently replace the first.
	 */
	public function test_the_same_field_used_twice_does_not_collide(): void {
		$this->make_product( 'Acme' );
		$this->make_product( 'Globex' );

		$filter = new Filter(
			array(
				new Condition( 'demo:supplier', Operator::EQUALS, 'Acme' ),
				new Condition( 'demo:supplier', Operator::IN, array( 'Acme', 'Globex' ) ),
			)
		);

		$this->assertSame( 1, $this->engine()->count( $filter ) );
	}

	/**
	 * An engine over a registry holding one text field on products.
	 */
	private function engine(): Query_Engine {
		global $wpdb;

		return new Query_Engine( $wpdb, new Filter_Providers( License::unlimited(), $this->provider() ) );
	}

	/**
	 * A provider whose one field is an ordinary postmeta key — the shape roughly
	 * two thirds of ACF needs.
	 */
	private function provider(): Filter_Provider {
		return new class() implements Filter_Provider {

			public function module(): string {
				return 'acf';
			}

			public function filter_fields(): array {
				return array(
					new Filter_Field(
						'demo:supplier',
						'Supplier',
						Filter_Control::TEXT,
						array( Operator::EQUALS, Operator::NOT_EQUALS, Operator::IN, Operator::NOT_IN ),
						array( Query_Scope::PRODUCT )
					),
				);
			}

			public function handles_filter( string $key ): bool {
				return 'demo:supplier' === $key;
			}

			public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
				return Field_Storage::post_meta( 'demo_supplier', Value_Kind::TEXT );
			}
		};
	}

	/**
	 * A published, priced product carrying a supplier meta value.
	 *
	 * @param string|null $supplier The supplier, or null to write no meta at all.
	 * @param float       $price    Regular price.
	 * @return int Product id.
	 */
	private function make_product( ?string $supplier, float $price = 10 ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'PC Product' );
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		$id = $product->save();

		if ( null !== $supplier ) {
			update_post_meta( $id, 'demo_supplier', $supplier );
		}

		return $id;
	}
}
