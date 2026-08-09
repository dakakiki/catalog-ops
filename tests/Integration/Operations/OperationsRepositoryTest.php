<?php
/**
 * Integration tests for the operations repository and migration 3.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;

/**
 * @covers \CatalogOps\Operations\Operations
 * @covers \CatalogOps\Operations\Operation
 * @covers \CatalogOps\Database\Migrations\Add_Operation_Progress_Columns
 */
final class OperationsRepositoryTest extends Operations_Database_Case {

	private Operations $operations;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->operations = new Operations( $wpdb, $this->schema );
	}

	public function test_migration_added_progress_columns(): void {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$this->schema->operations_table()
			)
		);

		$this->assertContains( 'last_progress_at', $columns );
		$this->assertContains( 'batch_size', $columns );
	}

	public function test_migration_added_conflict_policy_column(): void {
		global $wpdb;

		$columns = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$this->schema->operations_table()
			)
		);

		$this->assertContains( 'conflict_policy', $columns );
	}

	public function test_undo_metadata_round_trips(): void {
		$id = $this->operations->create(
			new Filter(),
			array(),
			Operation_Mode::SAFE,
			Operation_Source::UNDO,
			7,
			41,
			\CatalogOps\Operations\Conflict_Policy::FORCE
		);

		$operation = $this->operations->find( $id );
		$this->assertNotNull( $operation );
		$this->assertTrue( $operation->is_undo() );
		$this->assertSame( 41, $operation->parent_op_id );
		$this->assertSame( \CatalogOps\Operations\Conflict_Policy::FORCE, $operation->conflict_policy );
	}

	public function test_non_undo_operation_has_no_conflict_policy(): void {
		$id        = $this->make_operation();
		$operation = $this->operations->find( $id );

		$this->assertFalse( $operation->is_undo() );
		$this->assertNull( $operation->conflict_policy );
	}

	public function test_create_persists_a_draft_and_round_trips(): void {
		$filter  = new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) );
		$actions = array( new Set_Value( 'regular_price', '9.99' ) );

		$id = $this->operations->create( $filter, $actions, Operation_Mode::SAFE, Operation_Source::UI, 7 );
		$this->assertGreaterThan( 0, $id );

		$operation = $this->operations->find( $id );
		$this->assertNotNull( $operation );
		$this->assertSame( Operation_Status::DRAFT, $operation->status );
		$this->assertSame( Operation_Source::UI, $operation->source );
		$this->assertSame( Operation_Mode::SAFE, $operation->mode );
		$this->assertSame( 7, $operation->user_id );
		$this->assertNull( $operation->parent_op_id );
		$this->assertSame( 0, $operation->target_count );

		// Filter and actions rehydrate into working objects.
		$this->assertCount( 1, $operation->filter()->conditions() );
		$rebuilt_actions = $operation->actions();
		$this->assertCount( 1, $rebuilt_actions );
		$this->assertSame( 'regular_price', $rebuilt_actions[0]->field() );
		$this->assertSame( '9.99', $rebuilt_actions[0]->apply( 'anything' ) );
	}

	public function test_set_target_count_and_status_lifecycle(): void {
		$id = $this->make_operation();

		$this->operations->set_target_count( $id, 20000 );
		$this->operations->set_status( $id, Operation_Status::RUNNING );

		$running = $this->operations->find( $id );
		$this->assertSame( 20000, $running->target_count );
		$this->assertSame( Operation_Status::RUNNING, $running->status );
		$this->assertNull( $running->completed_at );

		$this->operations->set_status( $id, Operation_Status::COMPLETED, true );
		$completed = $this->operations->find( $id );
		$this->assertSame( Operation_Status::COMPLETED, $completed->status );
		$this->assertNotNull( $completed->completed_at );
	}

	public function test_record_progress_accumulates_and_reports_percent(): void {
		$id = $this->make_operation();
		$this->operations->set_target_count( $id, 200 );

		$this->operations->record_progress( $id, 50, 2 );
		$this->operations->record_progress( $id, 50, 0 );

		$operation = $this->operations->find( $id );
		$this->assertSame( 100, $operation->processed );
		$this->assertSame( 2, $operation->failed );
		$this->assertSame( 50, $operation->percent() );
		$this->assertNotNull( $operation->last_progress_at );
	}

	public function test_batch_size_is_persisted(): void {
		$id = $this->make_operation();

		$this->operations->set_batch_size( $id, 250 );

		$this->assertSame( 250, $this->operations->find( $id )->batch_size );
	}

	public function test_active_excluding_finds_a_competing_writer(): void {
		$mine  = $this->make_operation();
		$other = $this->make_operation();

		// Nothing active yet.
		$this->assertNull( $this->operations->active_excluding( $mine ) );

		$this->operations->set_status( $other, Operation_Status::RUNNING );

		$blocker = $this->operations->active_excluding( $mine );
		$this->assertNotNull( $blocker );
		$this->assertSame( $other, $blocker->id );

		// The competing op does not block itself.
		$this->assertNull( $this->operations->active_excluding( $other ) );
	}

	public function test_stalled_before_lists_only_stale_running_ops(): void {
		$fresh = $this->make_operation();
		$stale = $this->make_operation();

		$this->operations->set_status( $fresh, Operation_Status::RUNNING );
		$this->operations->set_status( $stale, Operation_Status::RUNNING );
		$this->operations->touch( $fresh );
		$this->operations->touch( $stale );

		// Backdate the stale operation's heartbeat by 20 minutes.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->schema->operations_table()} SET last_progress_at = %s WHERE id = %d",
				gmdate( 'Y-m-d H:i:s', time() - 20 * MINUTE_IN_SECONDS ),
				$stale
			)
		);

		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		$stalled = $this->operations->stalled_before( $cutoff );

		$ids = array_map( static fn( $op ) => $op->id, $stalled );
		$this->assertContains( $stale, $ids );
		$this->assertNotContains( $fresh, $ids );
	}

	private function make_operation(): int {
		return $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '5.00' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
	}
}
