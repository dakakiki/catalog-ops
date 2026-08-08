<?php
/**
 * A stored, named filter.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

/**
 * An immutable snapshot of a row in the saved_filters table: the persisted
 * {@see Filter} plus its identity and timestamps.
 */
final class Saved_Filter {

	/**
	 * Build a saved filter.
	 *
	 * @param int    $id         Row id.
	 * @param int    $user_id    Owner user id (0 for shared/none).
	 * @param string $name       Human-readable name.
	 * @param Filter $filter     The stored filter.
	 * @param string $created_at Creation time (GMT, MySQL datetime).
	 * @param string $updated_at Last update time (GMT, MySQL datetime).
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly string $name,
		public readonly Filter $filter,
		public readonly string $created_at,
		public readonly string $updated_at,
	) {}
}
