<?php
/**
 * Unit tests for the query scope carried by a filter.
 *
 * @package CatalogOps\Tests\Unit\Query
 */

namespace CatalogOps\Tests\Unit\Query;

use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Query\Filter
 * @covers \CatalogOps\Query\Query_Scope
 */
final class FilterScopeTest extends TestCase {

	public function test_default_scope_is_product(): void {
		$this->assertSame( Query_Scope::PRODUCT, ( new Filter() )->scope() );
		$this->assertSame( Query_Scope::PRODUCT, Query_Scope::default_scope() );
	}

	public function test_scope_round_trips_through_array_form(): void {
		$filter = new Filter(
			array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ),
			Filter::RELATION_AND,
			Query_Scope::VARIATION
		);

		$array = $filter->to_array();
		$this->assertSame( 'variation', $array['scope'] );

		$rebuilt = Filter::from_array( $array );
		$this->assertSame( Query_Scope::VARIATION, $rebuilt->scope() );
	}

	public function test_missing_scope_in_array_defaults_to_product(): void {
		// Filters serialized before M4 have no scope key.
		$rebuilt = Filter::from_array(
			array(
				'relation'   => 'AND',
				'conditions' => array(),
			)
		);

		$this->assertSame( Query_Scope::PRODUCT, $rebuilt->scope() );
	}

	public function test_unknown_scope_falls_back_to_product(): void {
		$rebuilt = Filter::from_array( array( 'scope' => 'nonsense', 'conditions' => array() ) );

		$this->assertSame( Query_Scope::PRODUCT, $rebuilt->scope() );
	}

	public function test_with_preserves_scope(): void {
		$filter = ( new Filter( array(), Filter::RELATION_AND, Query_Scope::VARIATION ) )
			->with( new Condition( 'price', Operator::GREATER_THAN, 5 ) );

		$this->assertSame( Query_Scope::VARIATION, $filter->scope() );
		$this->assertCount( 1, $filter->conditions() );
	}

	public function test_post_type_maps_from_scope(): void {
		$this->assertSame( 'product', Query_Scope::PRODUCT->post_type() );
		$this->assertSame( 'product_variation', Query_Scope::VARIATION->post_type() );
		$this->assertTrue( Query_Scope::VARIATION->is_variation() );
		$this->assertFalse( Query_Scope::PRODUCT->is_variation() );
	}
}
