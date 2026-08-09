<?php
/**
 * Provider for WooCommerce's built-in product fields.
 *
 * @package CatalogOps\Operations\Fields
 */

namespace CatalogOps\Operations\Fields;

use WC_Product;

/**
 * Reads and writes the scalar product fields WooCommerce exposes through CRUD
 * getters and setters — price, stock, and the common descriptive fields. Each
 * key maps to a `get_*`/`set_*` pair on {@see WC_Product}, so staging a value is
 * just calling the setter; the runner's later save() persists it and refreshes
 * the lookup table (CONTEXT §3, safe mode).
 */
final class Core_Fields implements Field_Provider {

	/**
	 * Key => [label, getter, setter]. The set the bulk editor can target in M2;
	 * more fields are additive.
	 *
	 * @var array<string, array{label: string, get: string, set: string}>
	 */
	private const FIELDS = array(
		'regular_price'  => array(
			'label' => 'Regular price',
			'get'   => 'get_regular_price',
			'set'   => 'set_regular_price',
		),
		'sale_price'     => array(
			'label' => 'Sale price',
			'get'   => 'get_sale_price',
			'set'   => 'set_sale_price',
		),
		'stock_quantity' => array(
			'label' => 'Stock quantity',
			'get'   => 'get_stock_quantity',
			'set'   => 'set_stock_quantity',
		),
		'stock_status'   => array(
			'label' => 'Stock status',
			'get'   => 'get_stock_status',
			'set'   => 'set_stock_status',
		),
		'weight'         => array(
			'label' => 'Weight',
			'get'   => 'get_weight',
			'set'   => 'set_weight',
		),
		'name'           => array(
			'label' => 'Name',
			'get'   => 'get_name',
			'set'   => 'set_name',
		),
		'sku'            => array(
			'label' => 'SKU',
			'get'   => 'get_sku',
			'set'   => 'set_sku',
		),
		'tax_class'      => array(
			'label' => 'Tax class',
			'get'   => 'get_tax_class',
			'set'   => 'set_tax_class',
		),
		'status'         => array(
			'label' => 'Status',
			'get'   => 'get_status',
			'set'   => 'set_status',
		),
	);

	/**
	 * The editable core fields, as UI descriptors.
	 *
	 * @return list<array{key: string, label: string, type: string}>
	 */
	public function get_fields(): array {
		$fields = array();

		foreach ( self::FIELDS as $key => $meta ) {
			$fields[] = array(
				'key'   => $key,
				'label' => $meta['label'],
				'type'  => Field_Type::POST_FIELD->value,
			);
		}

		return $fields;
	}

	/**
	 * Whether the key is one of the known core fields.
	 *
	 * @param string $key Field key.
	 */
	public function handles( string $key ): bool {
		return isset( self::FIELDS[ $key ] );
	}

	/**
	 * Core fields are all post-field writes for the changes log.
	 *
	 * @param string $key Field key (unused; type is uniform).
	 */
	public function field_type( string $key ): Field_Type {
		return Field_Type::POST_FIELD;
	}

	/**
	 * Core fields store under their own key.
	 *
	 * @param string $key Field key.
	 */
	public function storage_key( string $key ): string {
		return $key;
	}

	/**
	 * A post-field storage key maps straight back to the same core field key,
	 * provided it is one this provider knows.
	 *
	 * @param Field_Type $type        Where the value is stored.
	 * @param string     $storage_key The bare stored key.
	 */
	public function ui_key( Field_Type $type, string $storage_key ): ?string {
		if ( Field_Type::POST_FIELD !== $type || ! isset( self::FIELDS[ $storage_key ] ) ) {
			return null;
		}

		return $storage_key;
	}

	/**
	 * Read a core field via its WooCommerce getter.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Field key this provider handles.
	 */
	public function read( WC_Product $product, string $key ): mixed {
		$getter = self::FIELDS[ $key ]['get'];

		return $product->{$getter}();
	}

	/**
	 * Stage a core field via its WooCommerce setter (not yet saved).
	 *
	 * @param WC_Product $product The loaded product.
	 * @param string     $key     Field key this provider handles.
	 * @param mixed      $value   The value to write.
	 */
	public function stage( WC_Product $product, string $key, mixed $value ): void {
		$setter = self::FIELDS[ $key ]['set'];

		$product->{$setter}( $value );
	}
}
