<?php
/**
 * The columns of wc_product_meta_lookup a field may be declared to be.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * A closed enum rather than a column name, because the alternative is a
 * provider-supplied identifier interpolated into the projection's own FROM clause.
 * WooCommerce keeps these indexed and they are already in every statement the
 * engine builds, so a field declared here costs no join and no subquery — the
 * cheapest shape available.
 */
enum Lookup_Column: string {
	case MIN_PRICE      = 'min_price';
	case MAX_PRICE      = 'max_price';
	case STOCK_QUANTITY = 'stock_quantity';
	case STOCK_STATUS   = 'stock_status';
	case SKU            = 'sku';
	case ON_SALE        = 'onsale';

	/**
	 * The value kind this column holds.
	 */
	public function value_kind(): Value_Kind {
		return match ( $this ) {
			self::MIN_PRICE, self::MAX_PRICE => Value_Kind::DECIMAL,
			self::STOCK_QUANTITY, self::ON_SALE => Value_Kind::INTEGER,
			self::STOCK_STATUS, self::SKU => Value_Kind::TEXT,
		};
	}

	/**
	 * Whether the column is nullable, so the compiler knows to guard a negative
	 * comparison with `IS NULL OR NOT ( … )`. `stock_quantity` is NULL on every
	 * product that does not manage stock, and `col != 5` silently excludes those.
	 */
	public function is_nullable(): bool {
		return self::STOCK_QUANTITY === $this;
	}
}
