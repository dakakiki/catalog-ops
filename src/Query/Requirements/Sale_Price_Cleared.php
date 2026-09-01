<?php
/**
 * Predicate: objects whose existing sale price a new regular price would wipe.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * The collateral half of WooCommerce's sale-price rule. The data store clears
 * `_sale_price` whenever it is not strictly below the regular price, and it runs
 * that check when *either* price is updated — so lowering regular prices in bulk
 * silently deletes every sale price that now sits at or above the new figure.
 *
 * Nothing about the regular-price edit itself is inapplicable, so this is not a
 * requirement the preview subtracts from the target count. It is counted and
 * shown as a warning: the edit will go through, and it will take these sale
 * prices with it. Worth surfacing loudly, because the loss leaves no change row
 * of its own — there is no `_sale_price` delta for undo to restore.
 *
 * Matches the objects that *would be affected*, the opposite polarity to the
 * requirements the preview uses to narrow a target list.
 */
final class Sale_Price_Cleared implements Requirement {

	/**
	 * Build the predicate.
	 *
	 * @param float  $regular_price The literal regular price being set.
	 * @param string $reason        Warning code affected objects are reported under.
	 */
	public function __construct(
		private readonly float $regular_price,
		private readonly string $reason,
	) {}

	/**
	 * The warning code affected objects are reported under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects holding a sale price at or above the new regular price.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried (unused; a variation
	 *                           carries its own prices — CONTEXT §4).
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		$postmeta = $wpdb->postmeta;

		$fragment = "l.product_id IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = '_sale_price' AND pm.meta_value <> ''
				AND CAST( pm.meta_value AS DECIMAL(20,4) ) >= %f
		)";

		return array( $fragment, array( $this->regular_price ) );
	}
}
