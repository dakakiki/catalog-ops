<?php
/**
 * Action that moves a field by a fixed amount.
 *
 * @package CatalogOps\Operations\Actions
 */

namespace CatalogOps\Operations\Actions;

/**
 * "Raise every price by 200." The third thing anyone wants after setting a value
 * and taking a percentage off, and the one a shopkeeper describes in three words.
 *
 * It is its own action rather than a generated `price + 200` formula for two
 * reasons. The gate is the first: formulas are a paid feature, enforced on the
 * {@see Formula} class, and a formula the UI happened to write for an amount is
 * indistinguishable in the payload from any other — a free tier that could adjust
 * by an amount would need the server to whitelist expression shapes, which anyone
 * could post around. An action of its own is gated by being what it is.
 *
 * The second is honesty about the operation: this is addition, and saying so in
 * the stored action means the audit log, the preview and a future UI all read the
 * same intent rather than reverse-engineering it from a string.
 *
 * The result lands on the cent for the same reason the percentage does: money
 * with three decimals is not money. A field that holds no number yields null, so
 * the object is skipped and logged rather than treated as zero (CONTEXT §3).
 */
final class Adjust implements Action {

	public const TYPE = 'adjust';

	/**
	 * Decimal places money is kept to.
	 */
	private const PRECISION = 2;

	/**
	 * Build the action.
	 *
	 * @param string $field  The field key to write.
	 * @param float  $amount How far to move it; negative lowers.
	 */
	public function __construct(
		private readonly string $field,
		private readonly float $amount,
	) {}

	/**
	 * The targeted field key.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * It reads the field it writes — an object without one cannot be adjusted, so
	 * naming it here keeps those objects out of the operation entirely rather than
	 * targeting them and skipping them later (CONTEXT §2).
	 *
	 * @return list<string>
	 */
	public function reads(): array {
		return array( $this->field );
	}

	/**
	 * Move the current value by the amount.
	 *
	 * @param mixed         $current  The object's current value.
	 * @param callable|null $resolver Field resolver (unused; it reads only its own field).
	 * @return mixed The new value, or null when there is no number to move.
	 */
	public function apply( mixed $current, ?callable $resolver = null ): mixed {
		if ( ! is_numeric( $current ) ) {
			return null;
		}

		return round( (float) $current + $this->amount, self::PRECISION );
	}

	/**
	 * Serialize to actions_json shape.
	 *
	 * @return array{type: string, field: string, amount: float}
	 */
	public function to_array(): array {
		return array(
			'type'   => self::TYPE,
			'field'  => $this->field,
			'amount' => $this->amount,
		);
	}
}
