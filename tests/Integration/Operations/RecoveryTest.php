<?php
/**
 * Integration tests for putting a run back to work after its writer disappeared.
 *
 * The failure these defend against is the one that cannot fix itself: a process
 * killed mid-chunk leaves its queue entry claimed, Action Scheduler runs one batch
 * at a time, so nothing else starts — including the watchdog that exists to clean
 * up after exactly this. The run stays active, the write lock stays held, and the
 * site does nothing at all until somebody notices. Measured twice on 2026-09-04.
 *
 * So recovery runs on ordinary requests instead, and these tests pin both halves
 * of what that has to mean: it acts when a run has genuinely gone quiet, and it
 * keeps its hands off everything else — the frozen list, the operation's status,
 * and above all the schedule, which a machine failure must never stop.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Recovery;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Status;
use CatalogOps\Operations\Schedules;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Recovery
 * @covers \CatalogOps\Operations\Lock
 */
final class RecoveryTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Schedules $schedules;
	private Lock $lock;
	private Recording_Scheduler $scheduler;
	private Operation_Service $service;
	private Recovery $recovery;

	/**
	 * Product ids created during a test, deleted in tear_down.
	 *
	 * @var int[]
	 */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		global $wpdb;
		$engine           = new Query_Engine( $wpdb );
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
		$this->schedules  = new Schedules( $wpdb, $this->schema );
		$providers        = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$this->lock       = new Lock( $this->operations );
		$this->scheduler  = new Recording_Scheduler();

		$this->service = new Operation_Service(
			$engine,
			$this->operations,
			$this->changes,
			$providers,
			$this->lock,
			$this->scheduler,
			null,
			null,
			$this->schedules
		);

		$this->recovery = new Recovery( $this->operations, $this->changes, $this->lock, $this->scheduler );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );
		delete_option( 'catalogops_writer_active' );

		parent::tear_down();
	}

	public function test_an_idle_site_is_left_alone(): void {
		$this->assertFalse( $this->recovery->run() );
	}

	public function test_a_run_that_is_still_breathing_is_left_alone(): void {
		$op_id = $this->queued_run();

		$this->operations->set_status( $op_id, Operation_Status::RUNNING );
		$this->operations->touch( $op_id );

		$this->assertFalse( $this->recovery->run() );
	}

	public function test_a_run_that_has_gone_quiet_is_handed_to_a_new_worker(): void {
		$op_id  = $this->queued_run();
		$before = $this->scheduler->count();

		$this->strand( $op_id );
		$mine = $this->lock->generation();
		$this->scheduler->strand_chunks( 1 );

		$this->assertTrue( $this->recovery->run() );

		// The abandoned queue entry was let go, a chunk was asked for, and the queue
		// was nudged.
		$this->assertSame( $before + 1, $this->scheduler->count() );
		$this->assertGreaterThan( 0, $this->scheduler->kick_count() );

		// And the worker that vanished can no longer write: its turn is over.
		$this->assertFalse( $this->lock->still_held( $mine ) );
		$this->assertSame( $op_id, $this->lock->holder() );
	}

	/**
	 * The rule the owner set down, as an assertion. A server that restarted is not a
	 * decision about the change, and an hourly schedule left paused by one would mean
	 * somebody has to get up in the night to start it again.
	 */
	public function test_recovery_never_stops_the_schedule_behind_the_run(): void {
		$schedule_id = $this->schedules->create(
			'Nightly cut',
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::HOURLY,
			'2099-01-01 00:00:00',
			'',
			1
		);

		$op_id = $this->queued_run( $schedule_id );
		$this->strand( $op_id );

		$this->assertTrue( $this->recovery->run() );

		$schedule = $this->schedules->find( $schedule_id );
		$this->assertSame( Schedule_Status::ACTIVE, $schedule->status );
		$this->assertNull( $schedule->paused_reason );
	}

	/**
	 * A run that died before its first chunk ever ran is the case every supervisor
	 * has missed: `stalled_before()` selects on `status = 'running'`, so a queued
	 * corpse stays invisible for ever while holding the lock.
	 */
	public function test_a_run_that_died_before_it_started_is_recovered_too(): void {
		$op_id = $this->queued_run();

		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $op_id )->status );
		$this->backdate_creation( $op_id );

		$this->assertTrue( $this->recovery->run() );
	}

	/**
	 * Nothing frozen is left, so there is nothing to hand on — the run is owed only
	 * its ending, and that is the watchdog's irreversible business, not this one's.
	 */
	public function test_a_quiet_run_with_no_work_left_is_not_restarted(): void {
		$op_id = $this->queued_run();
		$this->strand( $op_id );

		foreach ( $this->changes->pending_chunk( $op_id, 500 ) as $row ) {
			$this->changes->mark_applied( $row->id, '1.00', '9.99' );
		}

		$this->assertFalse( $this->recovery->run() );
	}

	/**
	 * The one that nearly cost every large edit. `queue()` holds the lock while the
	 * row still reads DRAFT and only moves it to QUEUED once the filter has resolved
	 * and every target is seeded — minutes on a real catalogue. Reading "not active"
	 * as "settled" freed the lock out from under a live request, and the chunk that
	 * followed captured an empty generation, failed its own fence on the first pulse,
	 * and abandoned the chain after a few dozen objects with no error anywhere.
	 */
	public function test_a_draft_still_being_prepared_keeps_its_lock(): void {
		$this->make_product( 50 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		// The state queue() is in while it freezes: lock taken, row still a draft.
		$this->lock->acquire( $op_id );
		$mine = $this->lock->generation();
		$this->assertSame( Operation_Status::DRAFT, $this->operations->find( $op_id )->status );

		$this->assertFalse( $this->recovery->run() );

		// Untouched: the lock is still held, under the same turn, and the flag still
		// points at it.
		$this->assertTrue( $this->lock->still_held( $mine ) );
		$this->assertSame( $op_id, $this->lock->watching() );
	}

	/**
	 * The flag outliving its run would otherwise cost a row read on every request
	 * for ever.
	 */
	public function test_a_stale_writer_flag_is_cleared(): void {
		$op_id = $this->queued_run();
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );

		$this->assertSame( $op_id, $this->lock->watching() );
		$this->assertFalse( $this->recovery->run() );
		$this->assertSame( 0, $this->lock->watching() );
	}

	/**
	 * A run already in flight when this release lands has no writer flag, because
	 * the flag is written when the lock is taken and that moment has passed. Left
	 * alone it would be invisible to recovery for the rest of its life — and an
	 * upgrade landing mid-run is exactly when a process is likely to be interrupted.
	 */
	public function test_a_run_already_in_flight_at_upgrade_time_is_adopted(): void {
		$op_id = $this->queued_run();
		$this->strand( $op_id );

		// The state an upgrade leaves behind: the lock is held, the flag never existed.
		delete_option( 'catalogops_writer_active' );
		$this->assertSame( $op_id, $this->lock->holder() );

		$this->assertTrue( $this->recovery->run() );
		$this->assertSame( $op_id, $this->lock->watching() );
	}

	/**
	 * An operation queued and holding the lock, with real frozen work behind it.
	 *
	 * @param int|null $schedule_id Schedule that spawned it, when there is one.
	 */
	private function queued_run( ?int $schedule_id = null ): int {
		foreach ( range( 1, 3 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			null === $schedule_id ? Operation_Source::UI : Operation_Source::SCHEDULE,
			1,
			$schedule_id
		);

		$this->service->queue( $op_id );

		return $op_id;
	}

	/**
	 * Leave a run looking exactly like one whose process died: running, still holding
	 * the lock, with a heartbeat older than recovery tolerates.
	 *
	 * @param int $op_id The operation to strand.
	 */
	private function strand( int $op_id ): void {
		global $wpdb;

		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		$wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => gmdate( 'Y-m-d H:i:s', time() - Recovery::COLD_AFTER - 60 ) ),
			array( 'id' => $op_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Push a queued run's creation time back, so it stands in for a heartbeat it
	 * never had.
	 *
	 * @param int $op_id The operation to backdate.
	 */
	private function backdate_creation( int $op_id ): void {
		global $wpdb;

		$stamp = gmdate( 'Y-m-d H:i:s', time() - Recovery::COLD_AFTER - 60 );

		$wpdb->update(
			$this->schema->operations_table(),
			array(
				'created_at'       => $stamp,
				'last_progress_at' => null,
			),
			array( 'id' => $op_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * A simple product the filter will match.
	 *
	 * @param float $price Its regular price.
	 */
	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}
}
