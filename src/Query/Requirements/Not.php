<?php
/**
 * The logical negation of another predicate.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * Applicability rules answer "which objects can take this change"; a warning
 * often needs the opposite set — the ones that cannot, so the preview can say
 * what it protected the user from. Rather than write each rule twice, this wraps
 * one and inverts it.
 *
 * A predicate that renders an empty fragment (no constraint at all) inverts to
 * an empty fragment too, not to "nothing matches": an absent test excludes
 * nothing in either direction.
 */
final class Not implements Requirement {

	/**
	 * Build the negation.
	 *
	 * @param Requirement $inner  The predicate to invert.
	 * @param string      $reason Code matches are reported under.
	 */
	public function __construct(
		private readonly Requirement $inner,
		private readonly string $reason,
	) {}

	/**
	 * The code matches are reported under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The wrapped predicate, negated.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried.
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		list( $fragment, $args ) = $this->inner->sql( $wpdb, $scope );

		if ( '' === $fragment ) {
			return array( '', array() );
		}

		return array( "NOT ( {$fragment} )", $args );
	}
}
