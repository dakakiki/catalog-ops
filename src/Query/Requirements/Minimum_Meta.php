<?php
/**
 * Requirement: a numeric postmeta value must be at least a given figure.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * What makes a fixed subtraction countable up front.
 *
 * Taking 100 off a price is refused wherever it would leave the price below zero
 * — a rule the write guard applies one object at a time. But unlike a formula,
 * whose result depends on arithmetic the database cannot do, this one has an
 * exact SQL form: a price survives the cut precisely when it is at least the
 * amount being taken off. Expressing it here is what keeps the preview's count
 * equal to what the run applies (CONTEXT §2) — otherwise the panel promises 167
 * changes and delivers 154, with no hint of where the other thirteen went.
 *
 * Driven from the meta_key index as a semi-join, for the reason
 * {@see Meta_Present} explains: a correlated EXISTS mis-plans on a large
 * catalogue. The cast matches the one the engine's own numeric meta comparisons
 * use, so "at least 100" means the same thing in both places.
 */
final class Minimum_Meta implements Requirement {

	/**
	 * Build the requirement.
	 *
	 * @param string $meta_key The postmeta key holding the number.
	 * @param float  $minimum  The smallest value that qualifies.
	 * @param string $reason   Skip-reason code for objects below it.
	 */
	public function __construct(
		private readonly string $meta_key,
		private readonly float $minimum,
		private readonly string $reason,
	) {}

	/**
	 * The reason objects failing this requirement are omitted under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects whose value for the key is at least the minimum.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried (unused; the key is
	 *                           read off the object itself in both scopes).
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		$postmeta = $wpdb->postmeta;

		$fragment = "l.product_id IN (
			SELECT pm.post_id FROM {$postmeta} pm
			WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND CAST( pm.meta_value AS DECIMAL(20,4) ) >= %f
		)";

		return array( $fragment, array( $this->meta_key, $this->minimum ) );
	}
}
