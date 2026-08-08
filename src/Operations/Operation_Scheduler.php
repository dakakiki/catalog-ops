<?php
/**
 * The scheduling seam for the write engine.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The two scheduling capabilities the pipeline needs: enqueue the next chunk and
 * cancel an operation's remaining chunks. Keeping it an interface lets the real
 * Action Scheduler wrapper back it in production while tests drive the chain
 * synchronously with a recording double, never touching Action Scheduler.
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
	 * Cancel every pending chunk for an operation.
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel_operation( int $op_id ): void;
}
