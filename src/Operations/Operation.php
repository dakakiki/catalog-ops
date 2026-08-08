<?php
/**
 * An immutable snapshot of a row in the operations table.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Query\Filter;

/**
 * A read-model of one operation: its identity, lifecycle state, the filter and
 * actions it was built from, and its progress counters. The repository hydrates
 * it from a row; the filter and actions are kept as their decoded arrays and
 * rebuilt into objects on demand, so the hot path (a chunk re-reading the row)
 * pays only for what it uses — the runner needs the actions, never the filter,
 * whose targets are already frozen in the changes table.
 */
final class Operation {

	/**
	 * Build the read-model.
	 *
	 * @param int                              $id               Row id.
	 * @param Operation_Status                 $status           Lifecycle state.
	 * @param Operation_Source                 $source           Where it originated.
	 * @param Operation_Mode                   $mode             Write strategy.
	 * @param int                              $user_id          Owner user id.
	 * @param int|null                         $parent_op_id     Parent operation (for undo), or null.
	 * @param array<string, mixed>             $filter_data  Decoded filter_json.
	 * @param array<int, array<string, mixed>> $actions_data Decoded actions_json.
	 * @param int                              $target_count     Frozen number of target objects.
	 * @param int                              $processed        Objects processed so far.
	 * @param int                              $failed           Objects that failed.
	 * @param int                              $batch_size       Current adaptive chunk size (0 before first run).
	 * @param string                           $created_at       Creation time (GMT, MySQL datetime).
	 * @param string|null                      $completed_at     Completion time, or null.
	 * @param string|null                      $last_progress_at Heartbeat time, or null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly Operation_Status $status,
		public readonly Operation_Source $source,
		public readonly Operation_Mode $mode,
		public readonly int $user_id,
		public readonly ?int $parent_op_id,
		public readonly array $filter_data,
		public readonly array $actions_data,
		public readonly int $target_count,
		public readonly int $processed,
		public readonly int $failed,
		public readonly int $batch_size,
		public readonly string $created_at,
		public readonly ?string $completed_at,
		public readonly ?string $last_progress_at,
	) {}

	/**
	 * Rebuild the filter object from its stored form.
	 */
	public function filter(): Filter {
		return Filter::from_array( $this->filter_data );
	}

	/**
	 * Rebuild the action list from its stored form.
	 *
	 * @return list<Action>
	 */
	public function actions(): array {
		return Action_Factory::list_from_array( $this->actions_data );
	}

	/**
	 * Progress as a whole-number percentage of the target count (0 when the
	 * target is still unknown).
	 */
	public function percent(): int {
		if ( $this->target_count <= 0 ) {
			return 0;
		}

		return (int) floor( min( $this->processed, $this->target_count ) / $this->target_count * 100 );
	}
}
