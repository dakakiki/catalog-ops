<?php
/**
 * The scheduling seam for the write engine.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The scheduling capabilities the pipeline needs: enqueue the next chunk, ask the
 * queue to start now, and cancel an operation's remaining chunks. Keeping it an
 * interface lets the real Action Scheduler wrapper back it in production while
 * tests drive the chain synchronously with a recording double, never touching
 * Action Scheduler.
 */
interface Operation_Scheduler {

	/**
	 * Enqueue the next chunk of an operation.
	 *
	 * @param int $op_id      Operation id.
	 * @param int $batch_size Batch size for the next chunk.
	 */
	public function enqueue_chunk( int $op_id, int $batch_size ): void;

	/**
	 * Ask the background queue to start working now rather than whenever it next
	 * happens to wake up. Best-effort by definition — the queue may be busy, the
	 * host may block the request that carries the nudge — so callers must treat a
	 * silent no-op as normal and never depend on the work having started.
	 */
	public function kick(): void;

	/**
	 * Cancel every pending chunk for an operation.
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel_operation( int $op_id ): void;
}
