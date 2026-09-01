<?php
/**
 * A test scheduler double shared across the write-engine tests.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Operation_Scheduler;

/**
 * Records enqueue calls instead of dispatching them, so the write chain can be
 * driven synchronously in tests without touching Action Scheduler. The file name
 * does not end in `Test.php`, so PHPUnit does not collect it as a test case.
 */
final class Recording_Scheduler implements Operation_Scheduler {

	/**
	 * Recorded enqueue calls.
	 *
	 * @var array<int, array{op_id: int, batch: int}>
	 */
	private array $enqueued = array();

	/**
	 * Record an enqueue.
	 *
	 * @param int $op_id      Operation id.
	 * @param int $batch_size Batch size.
	 */
	public function enqueue_chunk( int $op_id, int $batch_size ): void {
		$this->enqueued[] = array(
			'op_id' => $op_id,
			'batch' => $batch_size,
		);
	}

	/**
	 * Kicks recorded, so a test can assert the queue was asked to start.
	 *
	 * @var int
	 */
	private int $kicks = 0;

	/**
	 * Record a kick rather than dispatching a loopback request.
	 */
	public function kick(): void {
		++$this->kicks;
	}

	/**
	 * Cancellation is a no-op for the recorder.
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel_operation( int $op_id ): void {}

	/**
	 * How many enqueue calls were recorded.
	 */
	public function count(): int {
		return count( $this->enqueued );
	}

	/**
	 * How many times the queue was asked to start.
	 */
	public function kick_count(): int {
		return $this->kicks;
	}
}
