<?php
/**
 * Action that sets a field to a fixed value.
 *
 * @package CatalogOps\Operations\Actions
 */

namespace CatalogOps\Operations\Actions;

/**
 * The simplest action and the only one v1.0 (M2) needs: overwrite a field with a
 * literal value regardless of what it held before. Naturally idempotent, so a
 * re-run of a partially applied chunk converges on the same result.
 */
final class Set_Value implements Action {

	public const TYPE = 'set';

	/**
	 * Build the action.
	 *
	 * @param string $field The field key to write.
	 * @param mixed  $value The literal value to set.
	 */
	public function __construct(
		private readonly string $field,
		private readonly mixed $value,
	) {}

	/**
	 * The targeted field key.
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * A literal reads nothing — it applies to every targeted object.
	 *
	 * @return list<string>
	 */
	public function reads(): array {
		return array();
	}

	/**
	 * Ignore the current value and return the literal target.
	 *
	 * @param mixed         $current  The object's current value (unused).
	 * @param callable|null $resolver Field resolver (unused; a literal reads nothing).
	 */
	public function apply( mixed $current, ?callable $resolver = null ): mixed {
		return $this->value;
	}

	/**
	 * Serialize to actions_json shape.
	 *
	 * @return array{type: string, field: string, value: mixed}
	 */
	public function to_array(): array {
		return array(
			'type'  => self::TYPE,
			'field' => $this->field,
			'value' => $this->value,
		);
	}
}
