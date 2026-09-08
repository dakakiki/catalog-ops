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
	 * comparison with `IS NULL OR NOT ( … )`.
	 *
	 * Every one of them is, and an earlier version of this method claimed only
	 * `stock_quantity` was — which would have made `price != 100` silently drop
	 * every product whose `min_price` is NULL, since `NULL != 100` is UNKNOWN
	 * rather than true. WooCommerce's own DDL, verified against a live table:
	 *
	 *     `sku`            varchar(100) NULL default ''
	 *     `min_price`      decimal(19,4) NULL default NULL
	 *     `max_price`      decimal(19,4) NULL default NULL
	 *     `onsale`         tinyint(1) NULL default 0
	 *     `stock_quantity` double NULL default NULL
	 *     `stock_status`   varchar(100) NULL default 'instock'
	 *
	 * The method stays rather than becoming a constant `true`, because it is where
	 * that evidence lives and where a future WooCommerce schema change would be
	 * recorded. A guard on a column that happens never to be NULL costs one
	 * predicate the optimiser discards; being wrong the other way costs products.
	 */
	public function is_nullable(): bool {
		return true;
	}

	/**
	 * How "does this object have a value at all" is asked of this column.
	 *
	 * Not every column can be asked, and reading them all as NULL-ness was a real
	 * defect: `l.sku IS NOT NULL` is a tautology. WooCommerce's own DDL gives
	 * `sku`, `stock_status` and `onsale` non-NULL defaults, and its data store
	 * fills the lookup row from `get_post_meta()`, which returns `''` rather than
	 * NULL for an absent key. So "products that have a SKU" matched the entire
	 * catalogue and "products missing a SKU" matched nothing — the two directions
	 * of the same mistake, both silent.
	 *
	 *   - NULLABLE — genuinely absent, so `IS NULL` is the truth.
	 *   - EMPTY_STRING — written as `''` when unset, the same test
	 *     {@see \CatalogOps\Query\Requirements\Meta_Present} already uses.
	 *   - ALWAYS_SET — the column has a value on every row, so the question has no
	 *     answer worth giving and the compiler refuses it rather than returning a
	 *     tautology the user would read as a working filter.
	 */
	public function presence(): Column_Presence {
		return match ( $this ) {
			self::MIN_PRICE, self::MAX_PRICE, self::STOCK_QUANTITY => Column_Presence::NULLABLE,
			self::SKU => Column_Presence::EMPTY_STRING,
			self::STOCK_STATUS, self::ON_SALE => Column_Presence::ALWAYS_SET,
		};
	}
}
