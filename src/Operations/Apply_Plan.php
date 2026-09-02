<?php
/**
 * The forward plan: apply an operation's actions to each object.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Fields\Field_Providers;
use WC_Product;

/**
 * The normal write path (CONTEXT §2): for each of the operation's actions, read
 * the object's current value, derive the new value from it, and stage the write.
 * Deriving at execution time — rather than freezing a computed value at queue
 * time — is what lets a formula (M5) slot in without touching the pipeline.
 *
 * An action whose {@see Action::apply()} returns null (e.g. a formula over an
 * empty field) skips the object rather than writing, and is recorded as skipped —
 * never coerced to zero (CONTEXT §3).
 *
 * A result the field cannot hold is treated the same way. A formula is only
 * negative for *some* objects — `regular_price - 10` is fine at 40 and nonsense
 * at 4 — so this cannot be settled up front for the whole set the way the SQL
 * applicability rules settle a literal. It is settled here, per object, before
 * anything is staged: {@see Write_Rules::refuses()} decides, the object is
 * skipped with a reason, and the rest of the operation carries on.
 */
final class Apply_Plan implements Chunk_Plan {

	/**
	 * The operation's actions.
	 *
	 * @var list<Action>
	 */
	private array $actions;

	/**
	 * Field provider registry.
	 *
	 * @var Field_Providers
	 */
	private Field_Providers $providers;

	/**
	 * The rules saying what a field may be written.
	 *
	 * @var Write_Rules
	 */
	private Write_Rules $rules;

	/**
	 * Build the plan.
	 *
	 * @param Action[]         $actions   The operation's actions.
	 * @param Field_Providers  $providers Field provider registry.
	 * @param Write_Rules|null $rules     What a field may be written; the default
	 *                                    set is stateless, so it is built when
	 *                                    omitted.
	 */
	public function __construct( array $actions, Field_Providers $providers, ?Write_Rules $rules = null ) {
		$this->actions   = array_values( $actions );
		$this->providers = $providers;
		$this->rules     = $rules ?? new Write_Rules();
	}

	/**
	 * Stage each action's write for one object.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param Change[]   $rows    The object's pending change rows.
	 */
	public function evaluate( WC_Product $product, array $rows ): Chunk_Outcome {
		$outcome = new Chunk_Outcome();

		// Index this object's rows by their storage identity so each action can
		// find the row it should claim.
		$row_for = array();
		foreach ( $rows as $row ) {
			$row_for[ $row->field_type->value . '|' . $row->field_key ] = $row;
		}

		// A formula reads fields other than the one it writes; this resolver lets
		// any action pull a current value off the already-loaded product by field
		// key, without the plan knowing which fields a given action needs.
		$resolver = fn( string $field_key ): mixed => $this->read_field( $product, $field_key );

		foreach ( $this->actions as $action ) {
			$provider = $this->providers->for( $action->field() );

			if ( null === $provider ) {
				continue;
			}

			$key = $provider->field_type( $action->field() )->value . '|' . $provider->storage_key( $action->field() );

			if ( ! isset( $row_for[ $key ] ) ) {
				continue;
			}

			$row     = $row_for[ $key ];
			$current = $provider->read( $product, $action->field() );
			$new     = $action->apply( $current, $resolver );

			if ( null === $new ) {
				// A formula over an empty or non-numeric field. The frozen target
				// list already excludes objects missing a read field, so reaching
				// here means the input was present but unusable.
				$outcome->record_skipped( $row, Values::to_string( $current ), Skip_Reason::EMPTY_INPUT );
				continue;
			}

			if ( $this->rules->refuses( $action->field(), $new ) ) {
				// A price below zero. Not staged, not rounded up to zero, not
				// written: the object keeps the price it has and says why.
				$outcome->record_skipped( $row, Values::to_string( $current ), Skip_Reason::NEGATIVE_VALUE );
				continue;
			}

			$provider->stage( $product, $action->field(), $new );
			$outcome->record_applied( $row, Values::to_string( $current ), Values::to_string( $new ) );
		}

		return $outcome;
	}

	/**
	 * Read a single field's current value off a loaded product by its field key,
	 * or null when no provider owns the key. This is the seam a formula reads its
	 * inputs through.
	 *
	 * @param WC_Product $product   The loaded product.
	 * @param string     $field_key The field key to read.
	 */
	private function read_field( WC_Product $product, string $field_key ): mixed {
		$provider = $this->providers->for( $field_key );

		return null === $provider ? null : $provider->read( $product, $field_key );
	}
}
