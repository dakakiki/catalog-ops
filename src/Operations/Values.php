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
}
