<?php
/**
 * Lifecycle states of a bulk operation.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The status column of the operations table (CONTEXT §3). The backing string is
 * the stable token persisted in the database.
 *
 * Flow: a UI request builds a `draft`, `queue()` freezes its targets and moves
 * it to `queued`, the first chunk flips it to `running`, and it settles in
 * `completed`, `failed`, or `reverted`. `paused` is an operator-requested halt
 * that a later chunk can resume from.
 */
enum Operation_Status: string {
	case DRAFT     = 'draft';
	case QUEUED    = 'queued';
	case RUNNING   = 'running';
	case PAUSED    = 'paused';
	case COMPLETED = 'completed';
	case FAILED    = 'failed';
	case REVERTED  = 'reverted';

	/**
	 * Whether the operation is still consuming its target list — the states in
	 * which the write-lock is held and chunks keep flowing.
	 */
	public function is_active(): bool {
		return self::QUEUED === $this || self::RUNNING === $this;
	}

	/**
	 * Whether the operation has reached a state from which no chunk will run
	 * again without an explicit new action (resume, undo).
	 */
	public function is_terminal(): bool {
		return self::COMPLETED === $this || self::FAILED === $this || self::REVERTED === $this;
	}
}
