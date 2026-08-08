<?php
/**
 * Integration tests for the stalled-operation watchdog.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Watchdog;
use CatalogOps\Query\Filter;

/**
 * @covers \CatalogOps\Operations\Watchdog
 */
final class WatchdogTest extends Operations_Database_Case {

	private Operations $operations;
	private Lock $lock;
	private Watchdog $watchdog;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->lock       = new Lock( $this->operations );
		$this->watchdog   = new Watchdog( $this->operations, $this->lock );
	}

	public function tear_down(): void {
		delete_option( 'catalogops_active_operation' );
		parent::tear_down();
	}

	public function test_fails_a_stalled_operation_and_frees_its_lock(): void {
		$op_id = $this->make_running_operation();
		$this->lock->acquire( $op_id );
		$this->backdate_heartbeat( $op_id, 20 );

		$failed = $this->watchdog->run();

		$this->assertSame( 1, $failed );
		$this->assertSame( Operation_Status::FAILED, $this->operations->find( $op_id )->status );
		$this->assertSame( 0, $this->lock->holder() );
	}

	public function test_leaves_a_live_operation_alone(): void {
		$op_id = $this->make_running_operation();
		$this->lock->acquire( $op_id );
		$this->operations->touch( $op_id ); // fresh heartbeat

		$failed = $this->watchdog->run();

		$this->assertSame( 0, $failed );
		$this->assertSame( Operation_Status::RUNNING, $this->operations->find( $op_id )->status );
		$this->assertSame( $op_id, $this->lock->holder() );
	}

	public function test_ignores_non_running_operations(): void {
		// A queued (not yet running) operation with an old heartbeat is not the
		// watchdog's concern — only `running` ones are.
		$op_id = $this->make_running_operation( Operation_Status::QUEUED );
		$this->backdate_heartbeat( $op_id, 30 );

		$this->assertSame( 0, $this->watchdog->run() );
		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $op_id )->status );
	}

	/**
	 * Create an operation and move it to a status (running by default), with a
	 * heartbeat set.
	 *
	 * @param Operation_Status $status Target status.
	 * @return int Operation id.
	 */
	private function make_running_operation( Operation_Status $status = Operation_Status::RUNNING ): int {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '5.00' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->operations->set_status( $op_id, $status );
		$this->operations->touch( $op_id );

		return $op_id;
	}

	/**
	 * Push an operation's heartbeat into the past.
	 *
	 * @param int $op_id   Operation id.
	 * @param int $minutes How many minutes ago.
	 */
	private function backdate_heartbeat( int $op_id, int $minutes ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->schema->operations_table()} SET last_progress_at = %s WHERE id = %d",
				gmdate( 'Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS ),
				$op_id
			)
		);
	}
}
