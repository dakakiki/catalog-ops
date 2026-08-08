<?php
/**
 * A single filter condition: one field, one operator, one value.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

use InvalidArgumentException;

/**
 * An immutable value object describing one comparison.
 *
 * The field key is a stable string the query engine maps to a column or
 * relationship:
 *   - `price`, `stock_quantity`   — numeric columns on wc_product_meta_lookup
 *   - `stock_status`              — string column on wc_product_meta_lookup
 *   - `category`                  — product_cat term ids
 *   - `attribute:pa_color`        — a product attribute taxonomy's term ids
 *   - `meta:_catalogops_brand`    — a post meta key
 *
 * The value's shape depends on the operator: a scalar for comparisons, a
 * two-element list for BETWEEN, a list for IN/NOT_IN, and null for
 * EXISTS/NOT_EXISTS.
 */
final class Condition {

	/**
	 * Build a condition.
	 *
	 * @param string   $field    Field key (see class docblock).
	 * @param Operator $operator Comparison operator.
	 * @param mixed    $value    Operand; shape depends on the operator.
	 *
	 * @throws InvalidArgumentException When the field key is empty.
	 */
	public function __construct(
		public readonly string $field,
		public readonly Operator $operator,
		public readonly mixed $value = null,
	) {
		if ( '' === $field ) {
			throw new InvalidArgumentException( 'A condition field must not be empty.' );
		}
	}

	/**
	 * Rebuild a condition from its array form.
	 *
	 * @param array{field: string, operator: string, value?: mixed} $data Serialized condition.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['field'] ?? '' ),
			Operator::from( (string) ( $data['operator'] ?? '' ) ),
			$data['value'] ?? null,
		);
	}

	/**
	 * Serialize to a JSON-friendly array (for filter_json).
	 *
	 * @return array{field: string, operator: string, value: mixed}
	 */
	public function to_array(): array {
		return array(
			'field'    => $this->field,
			'operator' => $this->operator->value,
			'value'    => $this->value,
		);
	}
}
