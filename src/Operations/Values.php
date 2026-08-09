<?php
/**
 * Helpers for normalizing field values to their stored form.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * Field values pass through WooCommerce as strings, ints, floats, or the odd
 * array; the changes table stores them as text. This normalizes any of them to
 * the one string form used for old_value/new_value, preserving null (an absent
 * value) as null so it is never confused with an empty string.
 */
final class Values {

	/**
	 * Normalize a value to its stored string form, or null.
	 *
	 * @param mixed $value A value read from or written to a product.
	 */
	public static function to_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * Compare two stored values for equality, tolerant of numeric formatting.
	 *
	 * This is the drift test undo relies on (CONTEXT §3): before writing an old
	 * value back, the runner reads the object's current value and asks whether it
	 * still equals what the original operation wrote. `"19.90"` and `19.9` are the
	 * same price — a strict string compare would report a false conflict and skip a
	 * change that is perfectly safe to revert.
	 *
	 * Values are compared in their stored string form (both sides pass through
	 * {@see self::to_string()} first). Rules:
	 *   - null equals only null. A null (an absent value) is deliberately distinct
	 *     from the empty string, so clearing a field is never mistaken for "unset".
	 *   - two numeric strings compare by numeric value, so trailing-zero and
	 *     integer/float formatting differences do not count as drift.
	 *   - everything else compares as an exact string.
	 *
	 * @param string|null $a One stored value.
	 * @param string|null $b The other stored value.
	 */
	public static function equal( ?string $a, ?string $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}

		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			// Compare by value, so trailing-zero and int/float formatting
			// ("19.90" vs 19.9) is not counted as drift. Casting to float is
			// enough for the ranges a catalog holds — prices, stock, weights —
			// and matches how WooCommerce normalizes these fields; bcmath is not
			// guaranteed on shared hosts.
			return (float) $a === (float) $b;
		}

		return $a === $b;
	}
}
