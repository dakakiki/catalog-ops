<?php
/**
 * Requirement: a sale price WooCommerce will actually keep.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * WooCommerce refuses a sale price at or above the regular price. On save,
 * `WC_Product_Data_Store_CPT::handle_updated_props()` compares the two and, when
 * the sale is not strictly lower, writes an empty `_sale_price` instead of the
 * value it was given.
 *
 * That makes an out-of-range sale price worse than a no-op: it silently *clears*
 * whatever sale price the object already had. Setting 1000 across a catalogue
 * priced at 500 does not fail loudly — it wipes every existing sale. So the
 * objects that would refuse the value are excluded from the operation up front,
 * counted under {@see \CatalogOps\Operations\Skip_Reason::SALE_NOT_BELOW_REGULAR},
 * and never queued.
 *
 * An object qualifies when its regular price is present and strictly greater than
 * the sale price being set. A missing or empty regular price never qualifies:
 * WooCommerce's comparison against an empty string is true for any non-empty sale
 * price, so the sale would be dropped.
 *
 * This models a *literal* sale price. A formula's result cannot be predicted in
 * SQL, so a formula edit carries no such requirement and relies on the runner's
 * post-save read-back instead — the rare safety net this rule exists to keep rare.
 */
final class Sale_Price_Sticks implements Requirement {

	/**
	 * Build the requirement.
	 *
	 * @param float       $sale_price     The literal sale price being set.
	 * @param string      $reason         Skip-reason code for objects that would refuse it.
	 * @param string|null $regular_price  The regular price the same operation sets, when
	 *                                    it sets one. WooCommerce compares against the
	 *                                    value in memory at save time, so a paired
	 *                                    regular-price action — not the stored price —
	 *                                    decides the outcome, identically for every
	 *                                    object. Null (the usual case) compares against
	 *                                    each object's stored `_regular_price`.
	 */
	public function __construct(
		private readonly float $sale_price,
		private readonly string $reason,
		private readonly ?string $regular_price = null,
	) {}

	/**
	 * The reason objects failing this requirement are omitted under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects whose regular price leaves room for this sale price.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried (unused; a variation
	 *                           carries its own prices — CONTEXT §4).
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		if ( null !== $this->regular_price ) {
			// The operation sets both prices, so the comparison is between two
			// literals and lands the same way for every object: either all of them
			// keep the sale price or none do.
			$fits = '' !== $this->regular_price
				&& is_numeric( $this->regular_price )
				&& $this->sale_price < (float) $this->regular_price;

			return array( $fits ? '1 = 1' : '1 = 0', array() );
		}

		$postmeta = $wpdb->postmeta;

		$fragment = "l.product_id IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = '_regular_price' AND pm.meta_value <> ''
				AND CAST( pm.meta_value AS DECIMAL(20,4) ) > %f
		)";

		return array( $fragment, array( $this->sale_price ) );
	}
}
