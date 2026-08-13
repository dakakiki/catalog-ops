<?php
/**
 * Action that computes a field's new value from a formula.
 *
 * @package CatalogOps\Operations\Actions
 */

namespace CatalogOps\Operations\Actions;

use CatalogOps\Operations\Formula\Expression;
use CatalogOps\Operations\Formula\Parser;
use CatalogOps\Operations\Formula\Variables;

/**
 * The M5 action (CONTEXT §3): write a field to the result of an arithmetic
 * formula over the object's other numeric fields — `regular_price * 1.2`,
 * `roundto( cost * 1.35, 0.99 )`, `max( regular_price * 0.8, cost * 1.1 )`.
 *
 * The formula is parsed once (at construction, via {@see Parser}) and evaluated
 * per object at execution time — deriving the value then, not freezing it at
 * queue time, is what lets formulas slot into the existing pipeline
 * ({@see Action}). A formula that reads an empty or non-numeric field returns
 * null, which the runner records as a skip rather than writing zero (CONTEXT §3).
 *
 * The formula's variables are friendly names ({@see Variables}); this action
 * bridges them to the provider field keys the resolver reads, so the expression
 * layer never has to know how `cost` is stored.
 */
final class Formula implements Action {

	public const TYPE = 'formula';

	/**
	 * Build the action from a target field and a compiled expression.
	 *
	 * @param string     $field      The field key to write.
	 * @param Expression $expression The compiled formula.
	 */
	public function __construct(
		private readonly string $field,
		private readonly Expression $expression,
	) {}

	/**
	 * Parse a formula source string into an action.
	 *
	 * @param string $field  The field key to write.
	 * @param string $source The formula text.
	 *
	 * @throws \CatalogOps\Operations\Formula\Formula_Error When the formula is malformed.
	 */
	public static function from_source( string $field, string $source ): self {
		return new self( $field, ( new Parser() )->parse( $source ) );
	}

	/**
	 * The targeted field key.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * The provider field keys the formula reads — its variables ({@see Variables})
	 * mapped to the keys the resolver uses. An object missing any of these makes
	 * the expression skip (strict null propagation), so these are exactly the
	 * fields an object must carry for the formula to produce a value.
	 *
	 * @return list<string>
	 */
	public function reads(): array {
		$keys = array();

		foreach ( $this->expression->variables() as $variable ) {
			$key = Variables::field_key( $variable );

			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Evaluate the formula for one object, reading its inputs through $resolver.
	 *
	 * @param mixed         $current  The field's current value (unused; the formula
	 *                                reads every input, including this field, by name).
	 * @param callable|null $resolver Maps a provider field key to the object's value.
	 * @return mixed The computed value, or null to skip the object.
	 */
	public function apply( mixed $current, ?callable $resolver = null ): mixed {
		if ( null === $resolver ) {
			// No object to read from (e.g. a serialization round-trip check): a
			// formula cannot be evaluated without its inputs, so it skips.
			return null;
		}

		return $this->expression->evaluate(
			static fn( string $variable ): mixed => $resolver( Variables::field_key( $variable ) )
		);
	}

	/**
	 * Serialize to actions_json shape.
	 *
	 * @return array{type: string, field: string, expression: string}
	 */
	public function to_array(): array {
		return array(
			'type'       => self::TYPE,
			'field'      => $this->field,
			'expression' => $this->expression->source(),
		);
	}
}
