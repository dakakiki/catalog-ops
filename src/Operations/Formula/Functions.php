<?php
/**
 * The functions a formula may call, with their arities and implementations.
 *
 * @package CatalogOps\Operations\Formula
 */

namespace CatalogOps\Operations\Formula;

/**
 * The fixed function vocabulary from CONTEXT §3: `round ceil floor roundto min
 * max abs`. Keeping it a closed set (rather than dispatching to arbitrary PHP
 * callables) is the whole point of the shunting-yard approach — a formula can
 * only ever do arithmetic the plugin vouches for, never reach `eval()` or a
 * function the author did not intend (CONTEXT §2, rule 6).
 *
 * Every implementation works on plain floats and returns a float, except where a
 * value cannot be produced — `roundto` with a zero step — which returns null so
 * the object is skipped rather than divided by zero (CONTEXT §3).
 */
final class Functions {

	/**
	 * Function name => [min args, max args]. A max of null means variadic.
	 *
	 * @var array<string, array{0: int, 1: int|null}>
	 */
	private const ARITY = array(
		'round'   => array( 1, 1 ),
		'ceil'    => array( 1, 1 ),
		'floor'   => array( 1, 1 ),
		'abs'     => array( 1, 1 ),
		'roundto' => array( 2, 2 ),
		'min'     => array( 2, null ),
		'max'     => array( 2, null ),
	);

	/**
	 * Whether $name is a known function.
	 *
	 * @param string $name Candidate function name.
	 */
	public static function exists( string $name ): bool {
		return isset( self::ARITY[ $name ] );
	}

	/**
	 * Assert that a call to $name with $count arguments has a valid arity.
	 *
	 * @param string $name  Function name (must already be known).
	 * @param int    $count Number of arguments supplied.
	 *
	 * @throws Formula_Error When the argument count is outside the function's arity.
	 */
	public static function check_arity( string $name, int $count ): void {
		list( $min, $max ) = self::ARITY[ $name ];

		if ( $count < $min || ( null !== $max && $count > $max ) ) {
			$expected = null === $max ? sprintf( '%d or more', $min ) : (string) $min;
			$message  = sprintf( 'Function %s() expects %s argument(s), got %d.', $name, $expected, $count );

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized at the REST boundary.
			throw new Formula_Error( $message );
		}
	}

	/**
	 * Evaluate a function over already-computed numeric arguments. Callers
	 * guarantee no argument is null (a null propagates to a skip before reaching
	 * here); the only null this returns is a value that genuinely cannot exist.
	 *
	 * @param string  $name Function name (must already be known).
	 * @param float[] $args Numeric arguments, arity already validated.
	 * @return float|null The result, or null when no value can be produced.
	 */
	public static function apply( string $name, array $args ): ?float {
		switch ( $name ) {
			case 'round':
				return round( $args[0] );
			case 'ceil':
				return ceil( $args[0] );
			case 'floor':
				return floor( $args[0] );
			case 'abs':
				return abs( $args[0] );
			case 'roundto':
				$step = $args[1];
				if ( 0.0 === (float) $step ) {
					return null; // No nearest multiple of zero — skip rather than divide by zero.
				}
				return round( $args[0] / $step ) * $step;
			case 'min':
				return (float) min( $args );
			case 'max':
				return (float) max( $args );
			default:
				return null;
		}
	}
}
