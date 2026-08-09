<?php
/**
 * Contract for reading and writing one family of product fields.
 *
 * @package CatalogOps\Operations\Fields
 */

namespace CatalogOps\Operations\Fields;

use WC_Product;

/**
 * A provider owns a set of field keys and knows how to read and stage them on a
 * WooCommerce product. This is the write-side abstraction from CONTEXT §3 — the
 * seam agencies extend in M7 to reach ACF, WPML, brands, and theme fields.
 *
 * The methods take an already-loaded {@see WC_Product} rather than an id: the
 * safe-mode runner loads each product once, snapshots and stages every field on
 * it, then saves once — so WooCommerce refreshes the meta lookup and transients a
 * single time per object (CONTEXT §3). A by-id public API is formalized in M7.
 */
interface Field_Provider {

	/**
	 * The editable fields this provider offers, for building the bulk-edit UI.
	 *
	 * @return list<array{key: string, label: string, type: string}>
	 */
	public function get_fields(): array;

	/**
	 * Whether this provider owns the given field key.
	 *
	 * @param string $key Field key (e.g. `regular_price`, `meta:_brand`).
	 */
	public function handles( string $key ): bool;

	/**
	 * The storage kind recorded for changes to this key.
	 *
	 * @param string $key Field key this provider handles.
	 */
	public function field_type( string $key ): Field_Type;

	/**
	 * The bare key stored in the changes table's field_key column — for meta,
	 * the key without the `meta:` prefix; for core fields, the key itself.
	 *
	 * @param string $key Field key this provider handles.
	 */
	public function storage_key( string $key ): string;

	/**
	 * Reverse of {@see storage_key()}: given a stored (field_type, field_key)
	 * pair from a changes row, return the field key this provider reads and writes
	 * by — or null when this provider does not own that storage.
	 *
	 * Undo (M3) works from recorded deltas, which carry only where a value lives
	 * (field_type + storage key), never the original UI key. This is how the
	 * runner routes a delta back to the provider that can write it (CONTEXT §3;
	 * see {@see Field_Type}). The returned key round-trips: passing it back through
	 * {@see storage_key()} yields the same storage key.
	 *
	 * @param Field_Type $type        Where the value is stored.
	 * @param string     $storage_key The bare stored key.
	 * @return string|null The provider's field key, or null if unowned.
	 */
	public function ui_key( Field_Type $type, string $storage_key ): ?string;

	/**
	 * Read the current value from a loaded product (the snapshot's old_value).
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Field key this provider handles.
	 * @return mixed The current value.
	 */
	public function read( WC_Product $product, string $key ): mixed;

	/**
	 * Stage a new value on a loaded product. Does not persist — the runner calls
	 * {@see WC_Product::save()} once after all of an object's fields are staged.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Field key this provider handles.
	 * @param mixed      $value   The value to write.
	 */
	public function stage( WC_Product $product, string $key, mixed $value ): void;
}
