<?php
/**
 * Unit tests for a single filter condition and its (de)serialization.
 *
 * @package CatalogOps\Tests\Unit\Query
 */

namespace CatalogOps\Tests\Unit\Query;

use CatalogOps\Query\Condition;
use CatalogOps\Query\Operator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Query\Condition
 */
final class ConditionTest extends TestCase {

	/**
	 * Pins that a mistyped operator token is bad input rather than a crash: it
	 * arrives as an InvalidArgumentException whose message names the token, which
	 * is the type the REST boundary maps to a 400.
	 *
	 * On 0.7.1 `Operator::from()` raised \ValueError, which extends \Error and not
	 * \Exception, so every catch ( InvalidArgumentException ) missed it and a
	 * mistyped token was an uncaught fatal 500 on all four write endpoints.
	 */
	public function test_an_unknown_operator_token_is_refused_as_bad_input(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'roughly' );

		Condition::from_array(
			array(
				'field'    => 'price',
				'operator' => 'roughly',
				'value'    => 5,
			)
		);
	}

	/**
	 * Pins that the array form survives the trip out and back unchanged — field,
	 * token and the operand's shape alike. A guard for behaviour 0.7.2 must not
	 * disturb: the array form is what sits in filter_json, so swapping `from()`
	 * for `tryFrom()` must not have narrowed the tokens the engine still reads.
	 */
	public function test_a_condition_round_trips_through_its_array_form(): void {
		$condition = new Condition( 'meta:_catalogops_brand', Operator::NOT_IN, array( 'Acme', 'Globex' ) );

		$rebuilt = Condition::from_array( $condition->to_array() );

		$this->assertSame( 'meta:_catalogops_brand', $rebuilt->field );
		$this->assertSame( Operator::NOT_IN, $rebuilt->operator );
		$this->assertSame( array( 'Acme', 'Globex' ), $rebuilt->value );
		$this->assertSame( $condition->to_array(), $rebuilt->to_array() );

		// EXISTS carries no operand, so a stored condition may have no `value`
		// key at all; the missing key reads as null rather than as a crash.
		$bare = Condition::from_array(
			array(
				'field'    => 'attribute:pa_color',
				'operator' => 'exists',
			)
		);

		$this->assertSame( Operator::EXISTS, $bare->operator );
		$this->assertNull( $bare->value );
	}

	/**
	 * Pins that an empty field key is refused at construction: it names nothing
	 * the engine could look up. A guard for behaviour 0.7.2 leaves alone — the
	 * refusal was already an InvalidArgumentException, and stays the one type the
	 * REST boundary has to catch.
	 */
	public function test_a_condition_without_a_field_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		// The array form funnels through the same guard: a missing `field` key
		// reads as the empty string.
		Condition::from_array( array( 'operator' => '=' ) );
	}
}
