<?php
/**
 * Shunting-yard parser: formula string to a compiled {@see Expression}.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * Dijkstra's shunting-yard (CONTEXT §2, rule 6: "Nikad eval()"). It reads the
 * {@see Lexer}'s tokens once and rearranges them into Reverse Polish Notation —
 * a postfix list an {@see Expression} evaluates with a single value stack and no
 * recursion. Everything a formula can express is validated here, at build time:
 * unknown variables and functions, wrong argument counts, unbalanced
 * parentheses, and misplaced commas all raise {@see Formula_Error} now, so a
 * malformed formula never reaches a chunk run.
 *
 * Two details beyond the textbook algorithm: unary minus/plus (a `-` in operand
 * position becomes a distinct high-precedence right-associative operator), and
 * function arity — each open call tracks its argument count so `roundto(x)` is
 * rejected before it can under-pop the stack at evaluation time.
 */
final class Parser {

	/**
	 * Operator precedence. Higher binds tighter.
	 *
	 * @var array<string, int>
	 */
	private const PRECEDENCE = array(
		'u-' => 4,
		'u+' => 4,
		'*'  => 3,
		'/'  => 3,
		'+'  => 2,
		'-'  => 2,
	);

	/**
	 * Compile a formula string into an evaluable expression.
	 *
	 * @param string $source The formula text (e.g. `roundto( cost * 1.35, 0.99 )`).
	 *
	 * @throws Formula_Error When the formula is malformed in any way.
	 */
	public function parse( string $source ): Expression {
		$tokens = ( new Lexer() )->tokenize( $source );
		$count  = count( $tokens );

		$output = array();          // RPN being built.
		$ops    = array();          // Operator / paren / function stack.
		$argc   = array();          // Argument counter per open paren (null = grouping).
		$used   = array();          // Variable names referenced, as a set.
		$prev   = 'start';          // Prior token role, for unary and call detection.

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			switch ( $token->type ) {
				case Token::NUMBER:
					$output[] = array( 'num', (float) $token->value );
					$prev     = 'value';
					break;

				case Token::NAME:
					$is_call = ( $i + 1 < $count ) && Token::LPAREN === $tokens[ $i + 1 ]->type;

					if ( $is_call ) {
						if ( ! Functions::exists( $token->value ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
							throw new Formula_Error( sprintf( 'Unknown function "%s".', $token->value ) );
						}
						$ops[] = array( 'func', $token->value );
						$prev  = 'func';
					} else {
						if ( ! Variables::exists( $token->value ) ) {
							// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
							throw new Formula_Error( sprintf( 'Unknown field "%s" in formula.', $token->value ) );
						}
						$output[]              = array( 'var', $token->value );
						$used[ $token->value ] = true;
						$prev                  = 'value';
					}
					break;

				case Token::LPAREN:
					if ( 'func' === $prev ) {
						$empty  = ( $i + 1 < $count ) && Token::RPAREN === $tokens[ $i + 1 ]->type;
						$argc[] = $empty ? 0 : 1;
					} else {
						$argc[] = null; // A grouping paren, not a call.
					}
					$ops[] = array( 'lparen' );
					$prev  = 'lparen';
					break;

				case Token::COMMA:
					$this->drain_to_paren( $ops, $output );
					$top = count( $argc ) - 1;
					if ( $top < 0 || null === $argc[ $top ] ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
						throw new Formula_Error( 'A comma may only separate function arguments.' );
					}
					++$argc[ $top ];
					$prev = 'comma';
					break;

				case Token::OP:
					$op = $token->value;
					if ( ( '-' === $op || '+' === $op ) && in_array( $prev, array( 'start', 'op', 'lparen', 'comma' ), true ) ) {
						$op = 'u' . $op;
					}
					$this->pop_operators( $ops, $output, $op );
					$ops[] = array( 'op', $op );
					$prev  = 'op';
					break;

				case Token::RPAREN:
					$this->drain_to_paren( $ops, $output );
					if ( array() === $ops ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
						throw new Formula_Error( 'Unbalanced parentheses in formula.' );
					}
					array_pop( $ops ); // The matching lparen.
					$call_args = array_pop( $argc );

					if ( array() !== $ops && 'func' === $ops[ count( $ops ) - 1 ][0] ) {
						$func = array_pop( $ops );
						Functions::check_arity( $func[1], (int) $call_args );
						$output[] = array( 'call', $func[1], (int) $call_args );
					}
					$prev = 'value';
					break;
			}
		}

		while ( array() !== $ops ) {
			$entry = array_pop( $ops );
			if ( 'lparen' === $entry[0] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
				throw new Formula_Error( 'Unbalanced parentheses in formula.' );
			}
			$output[] = $entry;
		}

		if ( array() === $output ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
			throw new Formula_Error( 'Formula is empty.' );
		}

		$this->assert_well_formed( $output );

		return new Expression( $source, $output, array_keys( $used ) );
	}

	/**
	 * Move operators from the stack to the output until a left paren is on top
	 * (the paren itself stays). Shared by the comma and right-paren handlers.
	 *
	 * @param array<int, array> $ops    Operator stack (by reference).
	 * @param array<int, array> $output RPN output (by reference).
	 */
	private function drain_to_paren( array &$ops, array &$output ): void {
		while ( array() !== $ops && 'lparen' !== $ops[ array_key_last( $ops ) ][0] ) {
			$output[] = array_pop( $ops );
		}
	}

	/**
	 * Pop higher- or equal-precedence operators before pushing $incoming, honoring
	 * right-associativity for the unary operators.
	 *
	 * @param array<int, array> $ops      Operator stack (by reference).
	 * @param array<int, array> $output   RPN output (by reference).
	 * @param string            $incoming The operator about to be pushed.
	 */
	private function pop_operators( array &$ops, array &$output, string $incoming ): void {
		$incoming_prec = self::PRECEDENCE[ $incoming ];
		$right_assoc   = 'u-' === $incoming || 'u+' === $incoming;

		while ( array() !== $ops ) {
			$top = $ops[ count( $ops ) - 1 ];

			if ( 'op' !== $top[0] ) {
				break;
			}

			$top_prec = self::PRECEDENCE[ $top[1] ];

			if ( $top_prec > $incoming_prec || ( $top_prec === $incoming_prec && ! $right_assoc ) ) {
				$output[] = array_pop( $ops );
				continue;
			}

			break;
		}
	}

	/**
	 * Walk the finished RPN tracking only stack depth, so a structurally broken
	 * expression (a dangling operator, a leftover operand) is caught at build time
	 * rather than surfacing as an evaluation error inside a chunk run.
	 *
	 * @param array<int, array> $rpn The RPN to validate.
	 *
	 * @throws Formula_Error When the expression does not reduce to a single value.
	 */
	private function assert_well_formed( array $rpn ): void {
		$depth = 0;

		foreach ( $rpn as $item ) {
			switch ( $item[0] ) {
				case 'num':
				case 'var':
					++$depth;
					break;

				case 'op':
					if ( 'u-' === $item[1] || 'u+' === $item[1] ) {
						// Unary: consumes one, produces one — net zero.
						if ( $depth < 1 ) {
							$this->malformed();
						}
					} else {
						// Binary: consumes two, produces one — net minus one.
						if ( $depth < 2 ) {
							$this->malformed();
						}
						--$depth;
					}
					break;

				case 'call':
					$needed = $item[2];
					if ( $depth < $needed ) {
						$this->malformed();
					}
					$depth = $depth - $needed + 1;
					break;
			}
		}

		if ( 1 !== $depth ) {
			$this->malformed();
		}
	}

	/**
	 * Raise the generic malformed-formula error.
	 *
	 * @throws Formula_Error Always.
	 */
	private function malformed(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
		throw new Formula_Error( 'Formula is malformed.' );
	}
}
