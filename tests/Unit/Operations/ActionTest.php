<?php
/**
 * Unit tests for the action model and its (de)serialization.
 *
 * @package CatalogOps\Tests\Unit\Operations
 */

namespace CatalogOps\Tests\Unit\Operations;

use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Actions\Adjust;
use CatalogOps\Operations\Actions\Formula;
use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Formula\Formula_Error;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Operations\Actions\Set_Value
 * @covers \CatalogOps\Operations\Actions\Adjust
 * @covers \CatalogOps\Operations\Actions\Formula
 * @covers \CatalogOps\Operations\Actions\Action_Factory
 */
final class ActionTest extends TestCase {

	public function test_set_value_ignores_current_and_returns_the_literal(): void {
		$action = new Set_Value( 'regular_price', '9.99' );

		$this->assertSame( 'regular_price', $action->field() );
		$this->assertSame( '9.99', $action->apply( '19.90' ) );
		$this->assertSame( '9.99', $action->apply( null ) );
	}

	public function test_set_value_round_trips_through_the_factory(): void {
		$action  = new Set_Value( 'meta:_catalogops_brand', 'Acme' );
		$rebuilt = Action_Factory::from_array( $action->to_array() );

		$this->assertInstanceOf( Set_Value::class, $rebuilt );
		$this->assertSame( 'meta:_catalogops_brand', $rebuilt->field() );
		$this->assertSame( 'Acme', $rebuilt->apply( 'anything' ) );
	}

	public function test_adjust_moves_the_current_value_and_lands_on_the_cent(): void {
		$up = new Adjust( 'regular_price', 200.0 );

		$this->assertSame( 'regular_price', $up->field() );
		$this->assertSame( 219.99, $up->apply( '19.99' ) );

		// A decrease is the same action with a negative amount; the sign lives in
		// one place rather than in a direction flag the action would have to read.
		$down = new Adjust( 'regular_price', -200.0 );
		$this->assertSame( 300.0, $down->apply( '500' ) );

		// Money keeps two decimals, the way the percentage does.
		$this->assertSame( 10.32, ( new Adjust( 'regular_price', 0.333 ) )->apply( '9.99' ) );
	}

	public function test_adjust_skips_an_object_with_no_number_to_move(): void {
		$action = new Adjust( 'regular_price', 10.0 );

		// Never treated as zero-plus-ten: an empty price is not a price
		// (CONTEXT §3), so the object is skipped and logged instead.
		$this->assertNull( $action->apply( '' ) );
		$this->assertNull( $action->apply( null ) );
		$this->assertNull( $action->apply( 'abc' ) );
	}

	public function test_adjust_declares_the_field_it_reads(): void {
		// It reads what it writes, which is what keeps objects lacking that field
		// out of the operation rather than in it and skipped.
		$this->assertSame(
			array( 'regular_price' ),
			( new Adjust( 'regular_price', 5.0 ) )->reads()
		);
	}

	public function test_adjust_round_trips_through_the_factory(): void {
		$action  = new Adjust( 'sale_price', -12.5 );
		$rebuilt = Action_Factory::from_array( $action->to_array() );

		$this->assertInstanceOf( Adjust::class, $rebuilt );
		$this->assertSame( 'sale_price', $rebuilt->field() );
		$this->assertSame( 7.5, $rebuilt->apply( '20' ) );
	}

	public function test_an_adjustment_without_a_number_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::from_array(
			array(
				'type'  => 'adjust',
				'field' => 'regular_price',
			)
		);
	}

	public function test_list_round_trips(): void {
		$actions = array(
			new Set_Value( 'regular_price', '5.00' ),
			new Set_Value( 'stock_status', 'outofstock' ),
		);

		$rebuilt = Action_Factory::list_from_array( Action_Factory::list_to_array( $actions ) );

		$this->assertCount( 2, $rebuilt );
		$this->assertSame( 'regular_price', $rebuilt[0]->field() );
		$this->assertSame( 'outofstock', $rebuilt[1]->apply( 'instock' ) );
	}

	public function test_formula_computes_from_the_resolved_field(): void {
		$action = Formula::from_source( 'regular_price', 'regular_price * 1.2' );

		// The resolver is keyed by provider field key (what the runner passes).
		$resolver = static fn( string $key ): mixed => array( 'regular_price' => '10' )[ $key ] ?? null;

		$this->assertSame( 'regular_price', $action->field() );
		$this->assertSame( 12.0, $action->apply( '10', $resolver ) );
	}

	public function test_formula_reads_cost_through_the_meta_key(): void {
		$action = Formula::from_source( 'regular_price', 'roundto( cost * 1.35, 0.99 )' );

		// `cost` resolves to a meta field key; default when no filter is registered.
		$resolver = static fn( string $key ): mixed => array( 'meta:_catalogops_cost' => 10 )[ $key ] ?? null;

		$this->assertSame( 13.86, $action->apply( null, $resolver ) );
	}

	public function test_formula_skips_on_empty_field(): void {
		$action   = Formula::from_source( 'regular_price', 'regular_price * 1.2' );
		$resolver = static fn( string $key ): mixed => '';

		$this->assertNull( $action->apply( '', $resolver ) );
	}

	public function test_formula_without_resolver_skips(): void {
		$action = Formula::from_source( 'regular_price', 'regular_price * 1.2' );

		$this->assertNull( $action->apply( '10' ) );
	}

	public function test_formula_round_trips_through_the_factory(): void {
		$action  = Formula::from_source( 'regular_price', 'cost * 1.35' );
		$rebuilt = Action_Factory::from_array( $action->to_array() );

		$this->assertInstanceOf( Formula::class, $rebuilt );
		$this->assertSame(
			array(
				'type'       => 'formula',
				'field'      => 'regular_price',
				'expression' => 'cost * 1.35',
			),
			$rebuilt->to_array()
		);
	}

	public function test_factory_rejects_a_malformed_formula(): void {
		$this->expectException( Formula_Error::class );

		Action_Factory::from_array(
			array(
				'type'       => 'formula',
				'field'      => 'regular_price',
				'expression' => 'regular_price * ',
			)
		);
	}

	public function test_unknown_type_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::from_array(
			array(
				'type'  => 'teleport',
				'field' => 'regular_price',
			)
		);
	}

	public function test_missing_field_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::from_array(
			array(
				'type'  => Set_Value::TYPE,
				'value' => 1,
			)
		);
	}

	public function test_empty_action_list_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::list_from_array( array() );
	}
}
