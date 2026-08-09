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
	 * Build the plan.
	 *
	 * @param Action[]        $actions   The operation's actions.
	 * @param Field_Providers $providers Field provider registry.
	 */
	public function __construct( array $actions, Field_Providers $providers ) {
		$this->actions   = array_values( $actions );
		$this->providers = $providers;
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
			$new     = $action->apply( $current );

			if ( null === $new ) {
				$outcome->record_skipped( $row, Values::to_string( $current ) );
				continue;
			}

			$provider->stage( $product, $action->field(), $new );
			$outcome->record_applied( $row, Values::to_string( $current ), Values::to_string( $new ) );
		}

		return $outcome;
	}
}
