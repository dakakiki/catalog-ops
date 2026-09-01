<?php
/**
 * The inverse plan: revert each object to a parent operation's recorded value.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Fields\Field_Providers;
use WC_Product;

/**
 * The undo path (CONTEXT §3). For each pending row, the parent operation's delta
 * gives both the value to write back (its old_value) and the value the operation
 * wrote (its new_value). Before writing, the plan reads the object's current
 * value and detects drift: if the object has changed since the operation — its
 * current value no longer equals what the operation wrote — the conflict policy
 * decides. `SKIP` (the default) leaves it and records a skip, so undo never
 * silently clobbers a later change; `FORCE` writes the old value back regardless.
 *
 * Drift is compared tolerantly ({@see Values::equal()}) so `"19.90"` and `19.9`
 * are not a false conflict. The staged write records the current value as the
 * undo's own old_value and the reverted value as its new_value, which is what
 * makes an undo itself undoable.
 */
final class Revert_Plan implements Chunk_Plan {

	/**
	 * Field provider registry.
	 *
	 * @var Field_Providers
	 */
	private Field_Providers $providers;

	/**
	 * The parent operation's applied deltas for this chunk's objects, keyed by
	 * "{object_id}|{field_type}|{field_key}" (see {@see Changes::applied_index()}).
	 *
	 * @var array<string, array{old: ?string, new: ?string}>
	 */
	private array $parent_deltas;

	/**
	 * How to resolve a drifted object.
	 *
	 * @var Conflict_Policy
	 */
	private Conflict_Policy $policy;

	/**
	 * Build the plan.
	 *
	 * @param Field_Providers                                  $providers     Field provider registry.
	 * @param array<string, array{old: ?string, new: ?string}> $parent_deltas Parent deltas, indexed by object+field.
	 * @param Conflict_Policy                                  $policy        Drift resolution policy.
	 */
	public function __construct( Field_Providers $providers, array $parent_deltas, Conflict_Policy $policy ) {
		$this->providers     = $providers;
		$this->parent_deltas = $parent_deltas;
		$this->policy        = $policy;
	}

	/**
	 * Stage the revert for each of one object's rows, honoring drift.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param Change[]   $rows    The object's pending change rows.
	 */
	public function evaluate( WC_Product $product, array $rows ): Chunk_Outcome {
		$outcome = new Chunk_Outcome();

		foreach ( $rows as $row ) {
			$key = $row->object_id . '|' . $row->field_type->value . '|' . $row->field_key;

			if ( ! isset( $this->parent_deltas[ $key ] ) ) {
				// The parent delta is gone (e.g. retention pruned it): nothing to
				// revert to. Leave the object as it is.
				$outcome->record_skipped( $row, null, Skip_Reason::NO_RECORD );
				continue;
			}

			$resolved = $this->providers->for_storage( $row->field_type, $row->field_key );

			if ( null === $resolved ) {
				// No provider can write this storage (a module was deactivated).
				$outcome->record_skipped( $row, null, Skip_Reason::NO_RECORD );
				continue;
			}

			$provider = $resolved['provider'];
			$field    = $resolved['key'];

			$target   = $this->parent_deltas[ $key ]['old'];
			$expected = $this->parent_deltas[ $key ]['new'];
			$current  = Values::to_string( $provider->read( $product, $field ) );

			if ( ! Values::equal( $current, $expected ) && Conflict_Policy::SKIP === $this->policy ) {
				// The object changed since the operation and the policy is skip:
				// leave it exactly as it is now and record the drift (CONTEXT §3).
				$outcome->record_skipped( $row, $current, Skip_Reason::DRIFT );
				continue;
			}

			$provider->stage( $product, $field, $target );
			$outcome->record_applied( $row, $current, $target );
		}

		return $outcome;
	}
}
