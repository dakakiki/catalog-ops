<?php
/**
 * Excludes the objects a schedule has already changed.
 *
 * @package CatalogOps\Query\Requirements
 */

namespace CatalogOps\Query\Requirements;

use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * What makes a repeating schedule a repeater rather than a treadmill.
 *
 * A schedule re-resolves its filter on every tick, and until this existed it
 * re-applied its action to everything the filter matched — including everything
 * it had already changed. For an *absolute* action that is merely redundant: "set
 * the price to 9.99" writes 9.99 over 9.99. For a *relative* one it is ruinous,
 * because the action reads the value it is about to replace. Measured on the test
 * catalogue with an hourly `roundto( regular_price * 0.95, 0.01 )`, one product
 * went 430.14 → 408.63 → 388.20 over three ticks, each taking the already-reduced
 * price as its base. Nobody wants an hourly discount compounding against itself,
 * and an overnight schedule left running would take a catalogue to nothing.
 *
 * The owner settled what a repeat is *for*: catching the products that have since
 * entered the segment. So a schedule touches any given object once, and this is
 * the rule that says so — in SQL, so the objects are never frozen in the first
 * place rather than being seeded and skipped one by one at write time.
 *
 * Deliberately narrow in three ways:
 *
 * 1. **Only what was applied.** A row this schedule skipped or failed is not a
 *    change, and the reason it did not stick (an empty input, a sale price that
 *    would not have held) may well be gone by the next tick. Those objects stay
 *    eligible.
 * 2. **Not what has been undone.** An undo puts the value back, so the schedule
 *    is entitled to apply again — its runs go to `reverted`, and this ignores
 *    them. Without that, undoing a scheduled run would quietly retire its objects
 *    for ever.
 * 3. **Only this schedule.** Another schedule's work, or a manual bulk edit, says
 *    nothing about whether this one has done its job.
 *
 * What it does not do is notice a value changed by hand afterwards. That was
 * raised and settled: whoever edits a price by hand owns the result.
 *
 * The subquery is an uncorrelated `NOT IN`, which is the shape exclusions want
 * here — an anti-join gives the optimiser no join order to re-plan around, and it
 * materialises once instead of re-running per candidate row. `operations` is
 * scanned rather than sought, its `schedule_id` being deliberately unindexed, but
 * that table holds one row per run and the join into `changes` is by primary key.
 */
final class Untouched_By_Schedule implements Requirement {

	/**
	 * Build the requirement.
	 *
	 * @param int    $schedule_id      The schedule whose own history to exclude.
	 * @param string $changes_table    Fully-qualified changes table name.
	 * @param string $operations_table Fully-qualified operations table name.
	 * @param string $reason           Skip-reason code for the objects it removes.
	 */
	public function __construct(
		private readonly int $schedule_id,
		private readonly string $changes_table,
		private readonly string $operations_table,
		private readonly string $reason,
	) {}

	/**
	 * The reason objects failing this requirement are omitted under.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Objects this schedule has not already applied a change to.
	 *
	 * `l.product_id` in both scopes, and that is correct rather than convenient: a
	 * variation operation records the variation's own id on its change rows, and
	 * under the variation scope `l.product_id` is that same id. The parent column
	 * the taxonomy clause reaches for exists because categories live on the parent;
	 * a change does not.
	 *
	 * @param wpdb        $wpdb  Database handle.
	 * @param Query_Scope $scope The object type being queried (unused; a change is
	 *                           recorded against the object it was written to).
	 * @return array{0: string, 1: list<mixed>}
	 */
	public function sql( wpdb $wpdb, Query_Scope $scope ): array {
		$changes    = $this->changes_table;
		$operations = $this->operations_table;

		$fragment = "l.product_id NOT IN (
			SELECT c.object_id
			FROM {$changes} c
			INNER JOIN {$operations} o ON o.id = c.operation_id
			WHERE o.schedule_id = %d
				AND o.status <> %s
				AND c.status = %d
		)";

		return array(
			$fragment,
			array( $this->schedule_id, 'reverted', 1 ),
		);
	}
}
