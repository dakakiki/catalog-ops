<?php
/**
 * Integration tests for the retention window and its purge job.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Retention;
use CatalogOps\Query\Filter;

/**
 * @covers \CatalogOps\Operations\Retention
 * @covers \CatalogOps\Operations\Changes
 */
final class RetentionTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Retention $retention;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
		$this->retention  = new Retention( $this->changes );
	}

	public function tear_down(): void {
		delete_option( Retention::OPTION );
		parent::tear_down();
	}

	public function test_days_defaults_and_clamps_to_the_allowed_range(): void {
		$this->assertSame( Retention::DEFAULT_DAYS, $this->retention->days() );

		$this->assertSame( Retention::MIN_DAYS, $this->retention->set_days( 1 ) );
		$this->assertSame( Retention::MIN_DAYS, $this->retention->days() );

		$this->assertSame( Retention::MAX_DAYS, $this->retention->set_days( 9999 ) );
		$this->assertSame( 45, $this->retention->set_days( 45 ) );
	}

	public function test_purge_removes_deltas_of_operations_past_the_window(): void {
		// An operation that completed 40 days ago, and one that completed today.
		$old    = $this->completed_operation( array( 10, 11 ), 40 );
		$recent = $this->completed_operation( array( 20, 21 ), 1 );

		$this->retention->set_days( 30 );
		$result = $this->retention->purge();

		// The old operation's deltas are gone; the recent one's remain.
		$this->assertSame( 1, $result['operations'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertSame( 0, $this->changes->counts( $old )['applied'] );
		$this->assertSame( 2, $this->changes->counts( $recent )['applied'] );

		// The operation rows themselves survive as the audit trail.
		$this->assertNotNull( $this->operations->find( $old ) );
		$this->assertNotNull( $this->operations->find( $recent ) );
	}

	public function test_purge_is_idempotent(): void {
		$this->completed_operation( array( 30 ), 90 );
		$this->retention->set_days( 30 );

		$first = $this->retention->purge();
		$this->assertSame( 1, $first['operations'] );

		// A second run finds nothing left to purge.
		$second = $this->retention->purge();
		$this->assertSame( 0, $second['operations'] );
		$this->assertSame( 0, $second['rows'] );
	}

	public function test_purge_ignores_operations_that_never_completed(): void {
		// A running operation (no completed_at) is never purged, however old.
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->changes->seed( $op_id, array( array( 'object_id' => 40, 'field_type' => 'post_field', 'field_key' => 'regular_price' ) ) );
		$this->backdate_created( $op_id, 200 );
		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		$result = $this->retention->purge();

		$this->assertSame( 0, $result['operations'] );
		$this->assertSame( 1, $this->changes->pending_count( $op_id ) );
	}

	/**
	 * Create a completed operation with applied deltas, its completion backdated.
	 *
	 * @param int[] $object_ids  Changed object ids.
	 * @param int   $days_ago    How long ago it completed.
	 * @return int Operation id.
	 */
	private function completed_operation( array $object_ids, int $days_ago ): int {
		$op_id = $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$rows = array_map(
			static fn( int $id ): array => array(
				'object_id'  => $id,
				'field_type' => 'post_field',
				'field_key'  => 'regular_price',
			),
			$object_ids
		);
		$this->changes->seed( $op_id, $rows );
		foreach ( $this->changes->pending_chunk( $op_id, 100 ) as $row ) {
			$this->changes->mark_applied( $row->id, '19.90', '9.99' );
		}

		$when = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->schema->operations_table()} SET status = %s, completed_at = %s WHERE id = %d",
				Operation_Status::COMPLETED->value,
				$when,
				$op_id
			)
		);

		return $op_id;
	}

	/**
	 * Backdate an operation's created_at (completed_at stays null).
	 *
	 * @param int $op_id    Operation id.
	 * @param int $days_ago How long ago it was created.
	 */
	private function backdate_created( int $op_id, int $days_ago ): void {
		$when = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->schema->operations_table()} SET created_at = %s WHERE id = %d",
				$when,
				$op_id
			)
		);
	}
}
