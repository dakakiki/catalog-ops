<?php
/**
 * A compiled formula, ready to evaluate against a product's field values.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * The {@see Parser}'s output: a formula in Reverse Polish Notation plus the set
 * of variables it reads. Evaluation is a single left-to-right pass over a value
 * stack — no recursion, no {@see eval()} (CONTEXT §2, rule 6).
 *
 * Two rules make it safe for pricing (CONTEXT §3):
 *   - Any variable that resolves to an empty or non-numeric value makes the whole
 *     result null, so the object is skipped and logged — never coerced to zero,
 *     which would silently set a price to 0.
 *   - A division by zero (or a `roundto` with a zero step) also yields null and
 *     skips, rather than producing NaN/INF.
 *
 * The final value is rounded to {@see PRECISION} decimals so IEEE-754 artefacts
 * (`19.90 * 1.1` landing on `21.890000000000001`) never reach the catalog; a
 * formula that wants coarser rounding uses `round`/`roundto` explicitly.
 */
final class Expression {

	/**
	 * Decimal places the final result is rounded to — well beyond any catalog
	 * field's real precision (prices 2, weights 3–4), so it only clears float
	 * noise, never meaningful digits.
	 */
	private const PRECISION = 6;

	/**
	 * The formula in RPN. Each item is one of:
	 *   ['num', float] · ['var', string] · ['op', string] · ['call', string, int].
	 *
	 * @var array<int, array>
	 */
	private array $rpn;

	/**
	 * The original source text, for round-tripping to actions_json.
	 *
	 * @var string
	 */
	private string $source;

	/**
	 * The variable names this formula references.
	 *
	 * @var list<string>
	 */
	private array $variables;

	/**
	 * Build a compiled expression. Constructed by the {@see Parser}.
	 *
	 * @param string            $source    Original formula text.
	 * @param array<int, array> $rpn       The formula in RPN.
	 * @param string[]          $variables Variable names referenced.
	 */
	public function __construct( string $source, array $rpn, array $variables ) {
		$this->source    = $source;
		$this->rpn       = $rpn;
		$this->variables = $variables;
	}

	/**
	 * The original formula text.
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * The variable names this formula reads.
	 *
	 * @return list<string>
	 */
	public function variables(): array {
		return $this->variables;
	}

	/**
	 * Evaluate the formula, reading each variable through the supplied resolver.
	 *
	 * @param callable(string): mixed $resolve Maps a variable name to its current value.
	 * @return float|null The computed value, or null to skip the object.
	 */
	public function evaluate( callable $resolve ): ?float {
		$stack = array();

		foreach ( $this->rpn as $item ) {
			switch ( $item[0] ) {
				case 'num':
					$stack[] = $item[1];
					break;

				case 'var':
					$raw     = $resolve( $item[1] );
					$stack[] = is_numeric( $raw ) ? (float) $raw : null;
					break;

				case 'op':
					if ( 'u-' === $item[1] || 'u+' === $item[1] ) {
						$operand = array_pop( $stack );
						$stack[] = null === $operand ? null : ( 'u-' === $item[1] ? -$operand : $operand );
						break;
					}
					$right   = array_pop( $stack );
					$left    = array_pop( $stack );
					$stack[] = $this->binary( $item[1], $left, $right );
					break;

				case 'call':
					$args = array();
					for ( $k = 0; $k < $item[2]; $k++ ) {
						array_unshift( $args, array_pop( $stack ) );
					}
					// A null argument (an empty field, or an earlier skip) propagates.
					$stack[] = in_array( null, $args, true ) ? null : Functions::apply( $item[1], $args );
					break;
			}
		}

		$result = array_pop( $stack );

		return null === $result ? null : round( (float) $result, self::PRECISION );
	}

	/**
	 * Apply a binary operator, propagating null and refusing division by zero.
	 *
	 * @param string     $op    One of + - * /.
	 * @param float|null $left  Left operand.
	 * @param float|null $right Right operand.
	 */
	private function binary( string $op, ?float $left, ?float $right ): ?float {
		if ( null === $left || null === $right ) {
			return null;
		}

		return match ( $op ) {
			'+'     => $left + $right,
			'-'     => $left - $right,
			'*'     => $left * $right,
			'/'     => 0.0 === $right ? null : $left / $right,
			default => null,
		};
	}
}
