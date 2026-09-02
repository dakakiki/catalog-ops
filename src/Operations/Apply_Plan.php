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
 * The derivation itself belongs to {@see Evaluator}, which the preview asks the
 * same question: a preview that worked values out its own way could show a figure
 * this plan would never write. What is left here is the bookkeeping — matching
 * each answer to the change row that claims it, staging what will be written, and
 * recording the rest as skipped with its reason (never coerced to zero,
 * CONTEXT §3).
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
	 * Works out what each action would write.
	 *
	 * @var Evaluator
	 */
	private Evaluator $evaluator;

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
		$this->evaluator = new Evaluator( $providers, $rules );
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

		foreach ( $this->evaluator->derive( $product, $this->actions ) as $derived ) {
			$provider = $this->providers->for( $derived->field );

			if ( null === $provider ) {
				continue;
			}

			$key = $provider->field_type( $derived->field )->value . '|' . $provider->storage_key( $derived->field );

			if ( ! isset( $row_for[ $key ] ) ) {
				continue;
			}

			$row = $row_for[ $key ];

			if ( ! $derived->writes() ) {
				$outcome->record_skipped( $row, Values::to_string( $derived->old_value ), $derived->reason );
				continue;
			}

			$provider->stage( $product, $derived->field, $derived->new_value );
			$outcome->record_applied(
				$row,
				Values::to_string( $derived->old_value ),
				Values::to_string( $derived->new_value )
			);
		}

		return $outcome;
	}
}
