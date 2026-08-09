<?php
/**
 * How undo resolves a drifted object.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * When undo reverts a change it first checks for drift: has the object's current
 * value changed since the operation wrote it (CONTEXT §3)? If it has, this policy
 * decides what happens — the choice the user makes when starting an undo.
 *
 * `SKIP` is the default and the safe one: a drifted object is left exactly as it
 * is now and recorded as skipped, so undo never silently clobbers a change made
 * after the operation. `FORCE` writes the old value back regardless, for the user
 * who is certain they want the catalog restored to the pre-operation state.
 *
 * The backing string is persisted in the operations table's conflict_policy
 * column (null for operations that are not undos).
 */
enum Conflict_Policy: string {
	case SKIP  = 'skip';
	case FORCE = 'force';

	/**
	 * The default policy: leave drifted objects untouched (CONTEXT §3).
	 */
	public static function safe_default(): self {
		return self::SKIP;
	}
}
