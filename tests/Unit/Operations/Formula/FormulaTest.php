<?php
/**
 * Unit tests for the shunting-yard formula engine.
 *
 * @package CatalogOps\Tests\Unit\Operations\Formula
 */

namespace CatalogOps\Tests\Unit\Operations\Formula;

use CatalogOps\Operations\Formula\Expression;
use CatalogOps\Operations\Formula\Formula_Error;
use CatalogOps\Operations\Formula\Parser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Operations\Formula\Parser
 * @covers \CatalogOps\Operations\Formula\Expression
 * @covers \CatalogOps\Operations\Formula\Lexer
 * @covers \CatalogOps\Operations\Formula\Functions
 */
final class FormulaTest extends TestCase {

	/**
	 * Parse a source string into a compiled expression.
	 *
	 * @param string $source Formula text.
	 */
	private function parse( string $source ): Expression {
		return ( new Parser() )->parse( $source );
	}

	/**
	 * Evaluate a formula against a map of variable => value.
	 *
	 * @param string               $source Formula text.
	 * @param array<string, mixed> $vars   Variable values (by friendly name).
	 */
	private function eval( string $source, array $vars = array() ): ?float {
		return $this->parse( $source )->evaluate(
			static fn( string $name ): mixed => $vars[ $name ] ?? null
		);
	}

	public function test_arithmetic_precedence(): void {
		$this->assertSame( 7.0, $this->eval( '1 + 2 * 3' ) );
		$this->assertSame( 9.0, $this->eval( '(1 + 2) * 3' ) );
		$this->assertSame( 1.0, $this->eval( '10 / 2 / 5' ) );
		$this->assertSame( 2.0, $this->eval( '8 - 4 - 2' ) );
	}

	public function test_unary_minus_and_plus(): void {
		$this->assertSame( -2.0, $this->eval( '-5 + 3' ) );
		$this->assertSame( 5.0, $this->eval( '3 - -2' ) );
		$this->assertSame( 6.0, $this->eval( '-2 * -3' ) );
		$this->assertSame( 4.0, $this->eval( '+4' ) );
	}

	public function test_variables_resolve(): void {
		$this->assertSame( 12.0, $this->eval( 'regular_price * 1.2', array( 'regular_price' => '10' ) ) );
		$this->assertSame(
			24.0,
			$this->eval(
				'regular_price + cost',
				array(
					'regular_price' => 10,
					'cost'          => 14,
				)
			)
		);
	}

	public function test_functions(): void {
		$this->assertSame( 20.0, $this->eval( 'round(19.6)' ) );
		$this->assertSame( 20.0, $this->eval( 'ceil(19.1)' ) );
		$this->assertSame( 19.0, $this->eval( 'floor(19.9)' ) );
		$this->assertSame( 7.0, $this->eval( 'abs(0 - 7)' ) );
		$this->assertSame( 3.0, $this->eval( 'min(3, 8)' ) );
		$this->assertSame( 8.0, $this->eval( 'max(3, 8)' ) );
		$this->assertSame( 9.0, $this->eval( 'max(3, 9, 5)' ) );
	}

	public function test_dod_roundto_formula(): void {
		// CONTEXT §4 definition-of-done anchor: roundto( cost * 1.35, 0.99 ).
		// 10 * 1.35 = 13.5; round(13.5 / 0.99) = 14; 14 * 0.99 = 13.86.
		$this->assertSame( 13.86, $this->eval( 'roundto( cost * 1.35, 0.99 )', array( 'cost' => 10 ) ) );
	}

	public function test_max_of_two_price_formulas(): void {
		// max( regular_price * 0.8, cost * 1.1 ): 100*0.8=80, 100*1.1=110 -> 110.
		$value = $this->eval(
			'max( regular_price * 0.8, cost * 1.1 )',
			array(
				'regular_price' => 100,
				'cost'          => 100,
			)
		);
		$this->assertSame( 110.0, $value );
	}

	public function test_result_is_rounded_to_kill_float_noise(): void {
		$this->assertSame( 0.3, $this->eval( '0.1 + 0.2' ) );
	}

	public function test_empty_field_skips_rather_than_zero(): void {
		// CONTEXT §3: an empty or non-numeric input yields null (skip), never 0.
		$this->assertNull( $this->eval( 'regular_price * 1.2', array( 'regular_price' => '' ) ) );
		$this->assertNull( $this->eval( 'regular_price * 1.2', array( 'regular_price' => null ) ) );
		$this->assertNull( $this->eval( 'cost * 1.35', array( 'cost' => 'n/a' ) ) );
	}

	public function test_null_propagates_through_functions_and_operators(): void {
		$this->assertNull(
			$this->eval(
				'max(regular_price, cost)',
				array(
					'regular_price' => 10,
					'cost'          => '',
				)
			)
		);
		$this->assertNull( $this->eval( 'roundto(cost * 1.35, 0.99)', array( 'cost' => '' ) ) );
	}

	public function test_zero_is_a_real_value_not_a_skip(): void {
		$this->assertSame( 0.0, $this->eval( 'regular_price * 2', array( 'regular_price' => '0' ) ) );
	}

	public function test_division_by_zero_skips(): void {
		$this->assertNull( $this->eval( 'regular_price / 0', array( 'regular_price' => 10 ) ) );
		$this->assertNull( $this->eval( '10 / (cost - cost)', array( 'cost' => 5 ) ) );
	}

	public function test_roundto_zero_step_skips(): void {
		$this->assertNull( $this->eval( 'roundto(cost, 0)', array( 'cost' => 10 ) ) );
	}

	public function test_reports_referenced_variables(): void {
		$expression = $this->parse( 'max( regular_price * 0.8, cost * 1.1 )' );

		$variables = $expression->variables();
		sort( $variables );

		$this->assertSame( array( 'cost', 'regular_price' ), $variables );
	}

	public function test_source_round_trips(): void {
		$source = 'roundto( cost * 1.35, 0.99 )';

		$this->assertSame( $source, $this->parse( $source )->source() );
	}

	/**
	 * @dataProvider malformed_formulas
	 *
	 * @param string $source A formula that must be rejected at parse time.
	 */
	public function test_malformed_formulas_are_rejected( string $source ): void {
		$this->expectException( Formula_Error::class );

		$this->parse( $source );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_formulas(): array {
		return array(
			'unknown function'   => array( 'sqrt(4)' ),
			'unknown variable'   => array( 'unit_price * 2' ),
			'too few args'       => array( 'roundto(5)' ),
			'too many args'      => array( 'round(1, 2)' ),
			'one arg to min'     => array( 'min(5)' ),
			'unbalanced open'    => array( '(1 + 2' ),
			'unbalanced close'   => array( '1 + 2)' ),
			'empty'              => array( '' ),
			'whitespace only'    => array( '   ' ),
			'trailing operator'  => array( '1 +' ),
			'leading binary op'  => array( '* 3' ),
			'double operator'    => array( '1 * * 2' ),
			'bare comma'         => array( '1, 2' ),
			'comma in group'     => array( '(1, 2)' ),
			'two decimal points' => array( '1.2.3' ),
			'illegal character'  => array( 'regular_price % 2' ),
			'empty parens'       => array( '1 + ()' ),
			'adjacent values'    => array( '2 3' ),
		);
	}
}
