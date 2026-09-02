<?php
/**
 * Works out what a set of actions would write to one object.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Fields\Field_Providers;
use WC_Product;

/**
 * The one place that answers "what would this action write here".
 *
 * It exists because two callers need that answer and they must not disagree. The
 * runner asks it to stage a value ({@see Apply_Plan}); the preview asks it to
 * show the user a row before anything happens. A preview that derived values its
 * own way could show a figure the runner would never write — worse than showing
 * nothing at all, because it would be believed.
 *
 * Nothing here touches the database or the product: deriving is a question, and
 * the callers decide what to do with the answer.
 */
final class Evaluator {

	/**
	 * Field provider registry.
	 *
	 * @var Field_Providers
	 */
	private Field_Providers $providers;

	/**
	 * What a field will and will not take.
	 *
	 * @var Write_Rules
	 */
	private Write_Rules $rules;

	/**
	 * Build the evaluator.
	 *
	 * @param Field_Providers  $providers Field provider registry.
	 * @param Write_Rules|null $rules     What a field may be written; the default
	 *                                    set is stateless, so it is built when
	 *                                    omitted.
	 */
	public function __construct( Field_Providers $providers, ?Write_Rules $rules = null ) {
		$this->providers = $providers;
		$this->rules     = $rules ?? new Write_Rules();
	}

	/**
	 * Derive what each action would do to one loaded product.
	 *
	 * Actions whose field no provider handles are dropped rather than reported:
	 * they cannot be written by anyone, and the service refuses them at the
	 * boundary long before a run gets here.
	 *
	 * @param WC_Product $product The loaded product.
	 * @param Action[]   $actions The actions to derive.
	 * @return list<Derived_Change>
	 */
	public function derive( WC_Product $product, array $actions ): array {
		// A formula reads fields other than the one it writes; this resolver lets
		// any action pull a current value off the already-loaded product by field
		// key, without the caller knowing which fields a given action needs.
		$resolver = fn( string $field_key ): mixed => $this->read_field( $product, $field_key );

		$derived = array();

		foreach ( $actions as $action ) {
			$provider = $this->providers->for( $action->field() );

			if ( null === $provider ) {
				continue;
			}

			$current = $provider->read( $product, $action->field() );
			$new     = $action->apply( $current, $resolver );

			if ( null === $new ) {
				// A formula over an empty or non-numeric field. The frozen target
				// list already excludes objects missing a read field, so reaching
				// here means the input was present but unusable.
				$derived[] = new Derived_Change( $action->field(), $current, null, Skip_Reason::EMPTY_INPUT );
				continue;
			}

			if ( $this->rules->refuses( $action->field(), $new ) ) {
				// A price below zero. Not written, not rounded up to zero: the
				// object keeps what it has and says why.
				$derived[] = new Derived_Change( $action->field(), $current, null, Skip_Reason::NEGATIVE_VALUE );
				continue;
			}

			$derived[] = new Derived_Change( $action->field(), $current, $new );
		}

		return $derived;
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
