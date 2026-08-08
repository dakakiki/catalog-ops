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
	 * Compute the value to write from the object's current value.
	 *
	 * Returning `null` signals "skip this object" — for example a formula over an
	 * empty or non-numeric field (CONTEXT §3: never coerce to zero). The runner
	 * records skipped rows rather than writing.
	 *
	 * @param mixed $current The object's current value for {@see field()}.
	 * @return mixed The new value, or null to skip.
	 */
	public function apply( mixed $current ): mixed;

	/**
	 * Serialize to a JSON-friendly array (for actions_json). Must round-trip
	 * through {@see Action_Factory::from_array()}.
	 *
	 * @return array{type: string, field: string, value?: mixed}
	 */
	public function to_array(): array;
}
