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
	 * @param int                  $id          Row id.
	 * @param int                  $user_id     Owner user id (0 for shared/none).
	 * @param string               $name        Human-readable name.
	 * @param array<string, mixed> $filter_data Decoded filter_json.
	 * @param string               $created_at  Creation time (GMT, MySQL datetime).
	 * @param string               $updated_at  Last update time (GMT, MySQL datetime).
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly string $name,
		public readonly array $filter_data,
		public readonly string $created_at,
		public readonly string $updated_at,
	) {}

	/**
	 * Rebuild the stored filter.
	 *
	 * Kept as its decoded array and rebuilt on demand, exactly as
	 * {@see \CatalogOps\Operations\Operation::filter()} and
	 * {@see \CatalogOps\Operations\Schedule::filter()} do — and for a reason that
	 * matters more here than there: reading a saved filter must not be able to fail.
	 * A filter naming a field the engine can no longer answer has to stay listed,
	 * openable and repairable, and hydrating eagerly meant one unreadable row would
	 * throw out of {@see Saved_Filters::for_user()} and take the whole list with it.
	 * Refusing belongs at execution ({@see Filter_Fields}), never at load.
	 */
	public function filter(): Filter {
		return Filter::from_array( $this->filter_data );
	}
}
