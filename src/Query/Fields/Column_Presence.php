<?php
/**
 * How a lookup column says it has no value.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * "Has a value" is not one question, and treating it as one was a measured
 * mistake: compiling every presence test to `IS NOT NULL` made
 * `l.sku IS NOT NULL` a tautology, because WooCommerce writes `''` there rather
 * than NULL. The filter "products that have a SKU" then matched the whole
 * catalogue and its negation matched nothing, with no error either way.
 *
 * So each column declares which of the three it is, and
 * {@see Storage_Compiler} refuses the one that cannot be asked instead of
 * answering it wrongly.
 */
enum Column_Presence {

	/** Genuinely NULL when absent — `min_price`, `stock_quantity`. */
	case NULLABLE;

	/** Written as an empty string when absent — `sku`. */
	case EMPTY_STRING;

	/**
	 * Populated on every row, so presence is not a question this column can
	 * answer — `stock_status` defaults to `instock`, `onsale` to 0.
	 */
	case ALWAYS_SET;
}
