<?php
/**
 * Requirement: a postmeta key must be present and non-empty.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * The original applicability rule, now one requirement among several: an object
 * qualifies only when it carries a value for every field the edit reads. The
 * formula engine propagates null strictly — any empty or non-numeric input makes
 * the whole expression skip (CONTEXT §3, never coerce to zero) — so an object
 * missing an input can never be changed and is excluded up front rather than
 * counted and then skipped.
 *
 * Expressed as `l.product_id IN (SELECT post_id …)` rather than a correlated
 * EXISTS: the subquery selects from the meta_key index in one pass, which the
 * optimiser can drive the whole query from (see {@see \CatalogOps\Query\Query_Engine}
 * for why correlated EXISTS mis-plans on a large catalogue).
 */
final class Meta_Present implements Requirement {

	/**
	 * Build the requirement.
	 *
	 * @param string $meta_key The postmeta key that must hold a value.
	 * @param string $reason   Skip-reason code for objects that lack it.
	 */
	public function __construct(
		private readonly string $meta_key,
		private readonly string $reason,
	) {}

	/**
	 * The reason objects failing this requirement are omitted under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects carrying a non-empty value for the key.
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
		)";

		return array( $fragment, array( $this->meta_key ) );
	}
}
