<?php
/**
 * Unit tests for the action model and its (de)serialization.
 *
 * @package CatalogOps\Tests\Unit\Operations
 */

namespace CatalogOps\Tests\Unit\Operations;

use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Actions\Set_Value;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Operations\Actions\Set_Value
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

	public function test_unknown_type_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::from_array( array( 'type' => 'teleport', 'field' => 'regular_price' ) );
	}

	public function test_missing_field_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::from_array( array( 'type' => Set_Value::TYPE, 'value' => 1 ) );
	}

	public function test_empty_action_list_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		Action_Factory::list_from_array( array() );
	}
}
