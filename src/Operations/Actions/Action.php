<?php
/**
 * Contract for a single field transformation.
 *
 * @package CatalogOps\Operations\Actions
 */

namespace CatalogOps\Operations\Actions;

/**
 * One action = one field plus the rule that turns its current value into the new
 * one. An operation carries a list of actions (persisted as actions_json).
 *
 * {@see apply()} takes the object's current value and returns the value to
 * write. A literal {@see Set_Value} ignores the current value; an arithmetic or
 * formula action (M5) derives the new value from it. Deriving at execution time
 * — rather than freezing a computed value at queue time — is what lets formulas
 * slot in without touching the pipeline. The status-guarded write
 * (CONTEXT §3) keeps non-idempotent transforms from applying twice.
 */
interface Action {

	/**
	 * The field key this action targets (the same keys the query layer uses,
	 * e.g. `regular_price`, `stock_status`, `meta:_catalogops_brand`).
	 */
	public function field(): string;

	/**
	 * The provider field keys this action reads to compute the new value. The
	 * formula engine propagates null strictly — any empty or non-numeric input
	 * makes the whole expression skip — so an object missing any read field is
	 * always skipped. Reporting the read keys lets an operation target only the
	 * objects that carry them, so its counted targets match what it actually
	 * applies (and what an undo reverts). A literal {@see Set_Value} reads nothing.
	 *
	 * @return list<string>
	 */
	public function reads(): array;

	/**
	 * Compute the value to write from the object's current value.
	 *
	 * Returning `null` signals "skip this object" — for example a formula over an
	 * empty or non-numeric field (CONTEXT §3: never coerce to zero). The runner
	 * records skipped rows rather than writing.
	 *
	 * A literal {@see Set_Value} needs only `$current` (and ignores even that). A
	 * {@see Formula} reads other fields on the same object — `cost`, another price
	 * — so the runner passes a `$resolver` that maps a field key to that object's
	 * current value. It is optional so a value literal can still be applied with no
	 * object in hand (previews, tests); a formula given no resolver skips.
	 *
	 * @param mixed         $current  The object's current value for {@see field()}.
	 * @param callable|null $resolver Maps a provider field key to the object's value.
	 * @return mixed The new value, or null to skip.
	 */
	public function apply( mixed $current, ?callable $resolver = null ): mixed;

	/**
	 * Serialize to a JSON-friendly array (for actions_json). Must round-trip
	 * through {@see Action_Factory::from_array()}.
	 *
	 * @return array{type: string, field: string, value?: mixed}
	 */
	public function to_array(): array;
}
