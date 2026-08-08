<?php
/**
 * An immutable snapshot of a row in the changes table.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Fields\Field_Type;

/**
 * One delta: the field on one object an operation intends to (or did) change,
 * with the recorded old and new values. Seeded as a pending row with the values
 * still null; the runner fills them in as it applies the change. This is both
 * the frozen execution unit and, once applied, the undo/audit record (CONTEXT §3).
 */
final class Change {

	/**
	 * Build the read-model.
	 *
	 * @param int           $id           Row id.
	 * @param int           $operation_id Owning operation id.
	 * @param string        $object_type  product|variation.
	 * @param int           $object_id    The product or variation id.
	 * @param Field_Type    $field_type   Where the value is stored.
	 * @param string        $field_key    The storage key (bare meta key or core field).
	 * @param string|null   $old_value    Value before the change, or null if not yet applied.
	 * @param string|null   $new_value    Value written, or null if not yet applied.
	 * @param Change_Status $status     Application state.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $operation_id,
		public readonly string $object_type,
		public readonly int $object_id,
		public readonly Field_Type $field_type,
		public readonly string $field_key,
		public readonly ?string $old_value,
		public readonly ?string $new_value,
		public readonly Change_Status $status,
	) {}
}
