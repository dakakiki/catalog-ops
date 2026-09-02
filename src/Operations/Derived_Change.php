<?php
/**
 * What one action would do to one object.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The answer to "what would this action write here", before anything is written.
 * The runner turns it into a staged value and a change row; the preview turns the
 * same answer into a line the user can read.
 *
 * A refused derivation carries the reason instead of a value — {@see $reason} is
 * the discriminator, not a null `$new_value`, so "no value" and "the value null"
 * can never be confused.
 */
final class Derived_Change {

	/**
	 * Build a derivation.
	 *
	 * @param string           $field     The field key the action targets.
	 * @param mixed            $old_value The object's current value.
	 * @param mixed            $new_value The value that would be written; meaningless
	 *                                    when $reason is set.
	 * @param Skip_Reason|null $reason    Why nothing would be written, or null when
	 *                                    the value stands.
	 */
	public function __construct(
		public readonly string $field,
		public readonly mixed $old_value,
		public readonly mixed $new_value,
		public readonly ?Skip_Reason $reason = null,
	) {}

	/**
	 * Whether this derivation would actually write something.
	 */
	public function writes(): bool {
		return null === $this->reason;
	}
}
