<?php
/**
 * The per-object result of evaluating a chunk plan.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * What a {@see Chunk_Plan} decided for one object's pending change rows: which
 * rows it staged a write for (applied) and which it left untouched (skipped). The
 * plan stages onto the product but records outcomes here; the runner reads them
 * back to persist a single save and then claim each row (CONTEXT §3). Keeping the
 * decision separate from the database writes is what lets the apply path and the
 * undo path share one runner.
 *
 * Each applied entry carries the value read before the write (old) and the value
 * staged (new); each skipped entry carries the value read before skipping, if
 * any, plus the {@see Skip_Reason} for it — a skip a user cannot see the reason
 * for is not a report, it is a shrug.
 */
final class Chunk_Outcome {

	/**
	 * Rows staged for writing, each {row: Change, old: ?string, new: ?string}.
	 *
	 * @var list<array{row: Change, old: ?string, new: ?string}>
	 */
	private array $applied = array();

	/**
	 * Rows left untouched, each {row: Change, old: ?string, reason: Skip_Reason}.
	 *
	 * @var list<array{row: Change, old: ?string, reason: Skip_Reason}>
	 */
	private array $skipped = array();

	/**
	 * Record that a row was staged for writing.
	 *
	 * @param Change      $row       The change row.
	 * @param string|null $old       Value read before the write.
	 * @param string|null $new_value Value staged.
	 */
	public function record_applied( Change $row, ?string $old, ?string $new_value ): void {
		$this->applied[] = array(
			'row' => $row,
			'old' => $old,
			'new' => $new_value,
		);
	}

	/**
	 * Record that a row was skipped (an empty formula, or a drift conflict).
	 *
	 * @param Change      $row    The change row.
	 * @param string|null $old    Value read before skipping, if any.
	 * @param Skip_Reason $reason Why the row was left untouched.
	 */
	public function record_skipped( Change $row, ?string $old, Skip_Reason $reason ): void {
		$this->skipped[] = array(
			'row'    => $row,
			'old'    => $old,
			'reason' => $reason,
		);
	}

	/**
	 * The rows staged for writing.
	 *
	 * @return list<array{row: Change, old: ?string, new: ?string}>
	 */
	public function applied(): array {
		return $this->applied;
	}

	/**
	 * The rows left untouched.
	 *
	 * @return list<array{row: Change, old: ?string, reason: Skip_Reason}>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Whether any row was staged for writing — i.e. whether the object needs a
	 * save at all.
	 */
	public function has_writes(): bool {
		return array() !== $this->applied;
	}
}
