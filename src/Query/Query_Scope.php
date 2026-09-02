<?php
/**
 * What kind of object a filter targets.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

/**
 * A filter resolves against either parent products or their variations
 * (CONTEXT §4 — variations are a first-class object, not a second-class detail).
 * The scope decides which post type the query engine reads and how a few
 * conditions are interpreted: a variation carries its own price, stock, sku, and
 * meta, but inherits its category from the parent product.
 *
 * The backing string is persisted in filter_json (and so in saved filters and an
 * operation's frozen filter), and it is the value written to the changes table's
 * object_type column, so undo and the audit log know what each delta touched.
 */
enum Query_Scope: string {
	case PRODUCT   = 'product';
	case VARIATION = 'variation';

	/**
	 * The scope a filter has when none was specified — parent products, matching
	 * every filter written before M4 added the notion.
	 */
	public static function default_scope(): self {
		return self::PRODUCT;
	}

	/**
	 * The post type this scope reads from.
	 */
	public function post_type(): string {
		return self::VARIATION === $this ? 'product_variation' : 'product';
	}

	/**
	 * Whether this scope targets variations.
	 */
	public function is_variation(): bool {
		return self::VARIATION === $this;
	}

	/**
	 * The other scope — the one the Products/Variations toggle would switch to.
	 *
	 * There are two, and a filter that finds nothing in one is often a filter
	 * pointed at the wrong one: a variable product keeps its price, stock and SKU
	 * on its variations, so a price filter over parents passes straight over the
	 * whole catalogue it was meant to find (CONTEXT §4).
	 */
	public function other(): self {
		return self::VARIATION === $this ? self::PRODUCT : self::VARIATION;
	}
}
