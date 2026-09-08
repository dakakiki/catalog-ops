<?php
/**
 * Whose row carries the value.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Under the product scope both cases resolve to `l.product_id`, which is exactly
 * why this cannot be inferred from {@see \CatalogOps\Query\Query_Scope}: a wrong
 * anchor passes every product-scope test a provider author will write, and returns
 * a plausible, non-empty, wrong set the moment a user flips the Products/Variations
 * toggle.
 *
 * The question is not "which scope am I in" but "does this value live on the row
 * being selected, or on its parent?" — a fact about the data. A variation carries
 * its own price, stock, SKU, post meta and `attribute_{taxonomy}` meta, so those are
 * SELF. `product_cat`, `product_tag` and WooCommerce's `product_brand` are
 * registered on `product` only and never on `product_variation`, so a taxonomy is
 * PARENT and there is no other correct reading.
 *
 * The reasoning does not generalise by analogy, and WPML is the proof. It would be
 * natural to assume a language is a property of the parent, as a taxonomy is; it is
 * not. Verified against WPML 4.9.7 on a 31,084-product catalogue: every variation
 * carries its own `icl_translations` row keyed on its own post id, 50,000 rows for
 * 50,000 variations, so `wpml:language` is SELF in both scopes. The anchor is a
 * claim about where the bytes are, and it has to be checked rather than reasoned to.
 */
enum Object_Anchor: string {
	case SELF   = 'self';
	case PARENT = 'parent';
}
