<?php
/**
 * Provider for arbitrary post-meta fields.
 *
 * @package CatalogOps\Operations\Fields
 */

namespace CatalogOps\Operations\Fields;

use WC_Product;

/**
 * Handles any `meta:<key>` field — the open-ended custom fields a catalog
 * accumulates (brand, cost, supplier codes, theme and plugin meta). The prefix
 * keeps meta keys from colliding with core field names and mirrors the query
 * layer's `meta:` convention.
 *
 * Values are read and staged through WooCommerce's meta CRUD so the write goes
 * through the same save() the core provider relies on.
 */
final class Meta_Fields implements Field_Provider {

	/**
	 * Field-key prefix that routes a key to this provider.
	 */
	public const PREFIX = 'meta:';

	/**
	 * Meta keys are catalog-specific and open-ended, so the provider advertises
	 * none for the picker; the UI collects the key from the user instead.
	 *
	 * @return list<array{key: string, label: string, type: string}>
	 */
	public function get_fields(): array {
		return array();
	}

	/**
	 * Whether the key carries the `meta:` prefix and names a key after it.
	 *
	 * @param string $key Field key.
	 */
	public function handles( string $key ): bool {
		return str_starts_with( $key, self::PREFIX ) && strlen( $key ) > strlen( self::PREFIX );
	}

	/**
	 * Meta writes are recorded as meta in the changes log.
	 *
	 * @param string $key Field key (unused; type is uniform).
	 */
	public function field_type( string $key ): Field_Type {
		return Field_Type::META;
	}

	/**
	 * The meta key with the `meta:` prefix stripped.
	 *
	 * @param string $key Prefixed field key.
	 */
	public function storage_key( string $key ): string {
		return substr( $key, strlen( self::PREFIX ) );
	}

	/**
	 * A meta storage key maps back to the same key with the `meta:` prefix
	 * restored. This provider owns any non-empty meta key.
	 *
	 * @param Field_Type $type        Where the value is stored.
	 * @param string     $storage_key The bare stored key.
	 */
	public function ui_key( Field_Type $type, string $storage_key ): ?string {
		if ( Field_Type::META !== $type || '' === $storage_key ) {
			return null;
		}

		return self::PREFIX . $storage_key;
	}

	/**
	 * Read a single meta value from the loaded product.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Prefixed field key.
	 */
	public function read( WC_Product $product, string $key ): mixed {
		return $product->get_meta( $this->storage_key( $key ), true );
	}

	/**
	 * Stage a meta value on the loaded product (not yet saved).
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Prefixed field key.
	 * @param mixed      $value   The value to write.
	 */
	public function stage( WC_Product $product, string $key, mixed $value ): void {
		$product->update_meta_data( $this->storage_key( $key ), $value );
	}
}
