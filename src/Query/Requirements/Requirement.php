<?php
/**
 * Contract for an applicability constraint the query layer can enforce.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * A predicate that narrows a filter to the objects an edit can actually change,
 * plus the reason the rest are left out.
 *
 * This is the up-front half of CatalogOps' promise that a preview equals the
 * outcome (CONTEXT §2). WooCommerce silently refuses some writes on save — a sale
 * price at or above the regular price is cleared, a managed product's stock status
 * is recomputed from its quantity — and a formula over an empty field cannot
 * produce a value at all. Rather than queue those objects and report a bare
 * "skipped" afterwards, each rule is expressed here as SQL: the preview counts
 * exactly how many objects fail it and why, and {@see \CatalogOps\Operations\Operation_Service::queue()}
 * freezes only the objects that pass.
 *
 * Implementations return a boolean fragment over the query's `l` (meta lookup)
 * and `p` (posts) aliases, to be ANDed into the WHERE clause. The reason is an
 * opaque string to this layer; the operations layer supplies a
 * {@see \CatalogOps\Operations\Skip_Reason} value so the same vocabulary describes
 * a predicted omission and a skip recorded at write time.
 *
 * The same shape also carries the preview's *warnings* — a predicate matching the
 * objects a change would collaterally affect, counted rather than subtracted (see
 * {@see Sale_Price_Cleared}). Only the polarity differs, so `reason()` is the code
 * a match is reported under, whichever way the caller reads it.
 */
interface Requirement {

	/**
	 * The code objects singled out by this predicate are reported under — a
	 * {@see \CatalogOps\Operations\Skip_Reason} value for a requirement the preview
	 * subtracts, or a warning code for one it merely counts.
	 */
	public function reason(): string;

	/**
	 * The SQL predicate and its bound arguments.
	 *
	 * The fragment may reference `l.product_id` (the object) and, under the
	 * variation scope, `p.post_parent` (its parent product). Values must be
	 * returned as arguments for `$wpdb->prepare()`, never interpolated.
	 *
	 * @param wpdb        $wpdb  Database handle, for table names and escaping.
	 * @param Query_Scope $scope The object type being queried.
	 * @return array{0: string, 1: list<mixed>} Fragment and its arguments.
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array;
}
