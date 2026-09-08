<?php
/**
 * Integration tests for the seam between a live schedule and a user rejecting one
 * of its runs — by undoing it, or by stopping it part-way.
 *
 * Two subsystems that could not see each other. A schedule fired an operation and
 * forgot it — `schedules.last_op_id` is overwritten on the next fire, so of X runs
 * only the newest was traceable — and neither undo nor stop knew schedules
 * existed, so both left the thing that made the run active with its clock
 * untouched.
 *
 * After an undo the next tick rebuilt the same operation from the same stored
 * template and wrote the reverted values straight back; because the notifier mails
 * only on a scheduled source, the sole message in the sequence announced the run
 * that overwrote the user's revert. After a stop it was quieter and was seen live:
 * a run stopped at 20,500 of 21,366 left its rows frozen, a paused operation does
 * not count as active, and the schedule began a second full pass thirty-seven
 * minutes later.
 *
 * These tests pin both halves of the fix: an operation records the schedule that
 * spawned it, and rejecting such a run pauses that schedule. They pin the refusals
 * just as hard — the cases where a schedule must be left exactly as it is —
 * because a pause that fires too eagerly takes a control away from the user as
 * surely as one that never fires at all.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Conflict_Policy;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedule_Status;
use CatalogOps\Operations\Schedules;
use CatalogOps\Operations\Watchdog;
use CatalogOps\Query\Condition;
use InvalidArgumentException;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Operation_Service
 * @covers \CatalogOps\Operations\Schedule_Runner
 * @covers \CatalogOps\Operations\Operations
 */
final class ScheduleRejectionTest extends Operations_Database_Case {

	private Schedules $schedules;
	private Operations $operations;
	private Operation_Service $service;
	private Schedule_Runner $runner;

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
		$this->schedules  = new Schedules( $wpdb, $this->schema );
		$changes          = new Changes( $wpdb, $this->schema );
		$providers        = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$lock             = new Lock( $this->operations );
		$scheduler        = new Recording_Scheduler();

		// Wired as the container wires it, schedules included — without them the
		// service silently cannot pause anything, which is the whole subject here.
		$this->service = new Operation_Service(
			$engine,
			$this->operations,
			$changes,
			$providers,
			$lock,
			$scheduler,
			null,
			null,
			$this->schedules
		);
		$this->runner = new Schedule_Runner( $this->schedules, $this->service, $this->operations );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_a_scheduled_run_records_which_schedule_made_it(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( Operation_Source::SCHEDULE, $operation->source );
		$this->assertSame( $schedule_id, $operation->schedule_id );
	}

	public function test_an_operation_started_by_hand_records_no_schedule(): void {
		$op_id = $this->ui_operation();

		$this->assertNull( $this->operations->find( $op_id )->schedule_id );
	}

	public function test_undoing_a_scheduled_run_pauses_its_schedule(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$schedule = $this->schedules->find( $schedule_id );
		$this->assertSame( Schedule_Status::PAUSED, $schedule->status );
		$this->assertNotNull( $schedule->paused_reason );
		$this->assertStringContainsString( 'undid', $schedule->paused_reason );
	}

	public function test_stopping_a_scheduled_run_pauses_its_schedule(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );

		// Straight from the fire: a queued run is active, which is the only state
		// cancel() acts on.
		$this->service->cancel( $op_id );

		$this->assertSame( Operation_Status::PAUSED, $this->operations->find( $op_id )->status );

		$schedule = $this->schedules->find( $schedule_id );
		$this->assertSame( Schedule_Status::PAUSED, $schedule->status );
		$this->assertNotNull( $schedule->paused_reason );
		$this->assertStringContainsString( 'stopped', $schedule->paused_reason );
	}

	public function test_stopping_a_run_started_by_hand_leaves_a_schedule_alone(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		$this->service->cancel( $op_id );

		$this->assertSame( Operation_Status::PAUSED, $this->operations->find( $op_id )->status );
		$this->assertSame( Schedule_Status::ACTIVE, $this->schedules->find( $schedule_id )->status );
	}

	/**
	 * cancel() returns early on anything that is not still consuming its target
	 * list. A finished run is not a rejection, and stopping it again must not become
	 * a way to pause a schedule after the fact.
	 */
	public function test_stopping_a_run_that_has_already_finished_changes_nothing(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$this->service->cancel( $op_id );

		$this->assertSame( Operation_Status::COMPLETED, $this->operations->find( $op_id )->status );
		$this->assertSame( Schedule_Status::ACTIVE, $this->schedules->find( $schedule_id )->status );
	}

	public function test_a_spent_one_shot_is_not_revived_by_stopping_its_run(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::ONCE, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->assertSame( Schedule_Status::COMPLETED, $this->schedules->find( $schedule_id )->status );

		$this->service->cancel( $op_id );

		$this->assertSame( Schedule_Status::COMPLETED, $this->schedules->find( $schedule_id )->status );
	}

	/**
	 * The pair that holds the whole rule. Same run, same mechanical outcome — paused,
	 * lock freed, frozen list intact — and opposite answers for the schedule, because
	 * one is a decision about the change and the other is a machine that dropped it.
	 * An hourly schedule left paused by a server restart means somebody has to get up
	 * in the night, which is the opposite of what scheduling is for.
	 */
	public function test_taking_over_a_dead_run_leaves_its_schedule_running(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->strand( $op_id );

		$this->service->take_over( $op_id );

		$this->assertSame( Operation_Status::PAUSED, $this->operations->find( $op_id )->status );
		$this->assertSame( Schedule_Status::ACTIVE, $this->schedules->find( $schedule_id )->status );
		$this->assertNull( $this->schedules->find( $schedule_id )->paused_reason );
	}

	/**
	 * The other half of the pair: the same run, stopped by hand, does pause it.
	 */
	public function test_stopping_that_same_run_does_pause_its_schedule(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->strand( $op_id );

		$this->service->cancel( $op_id );

		$this->assertSame( Schedule_Status::PAUSED, $this->schedules->find( $schedule_id )->status );
	}

	/**
	 * The client asks for a take-over on the strength of a poll that may be seconds
	 * old. A run that has drawn breath since must not be taken from a live writer.
	 */
	public function test_taking_over_is_refused_while_a_run_is_still_reporting(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );

		$this->operations->set_status( $op_id, Operation_Status::RUNNING );
		$this->operations->touch( $op_id );

		$this->expectException( InvalidArgumentException::class );
		$this->service->take_over( $op_id );
	}

	public function test_taking_over_frees_the_write_lock(): void {
		$this->make_product( 50 );

		$op_id = $this->fire( $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' ) );
		$this->strand( $op_id );

		$this->assertNotNull( $this->operations->active_excluding( 0 ) );

		$this->service->take_over( $op_id );

		// Nothing is writing any more, so the next operation can start.
		$this->assertNull( $this->operations->active_excluding( 0 ) );
	}

	public function test_undoing_a_run_started_by_hand_leaves_a_schedule_alone(): void {
		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->ui_operation();
		$this->complete( $op_id );

		$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$this->assertSame( Schedule_Status::ACTIVE, $this->schedules->find( $schedule_id )->status );
	}

	/**
	 * A spent one-shot is already stopped, and moving it to paused would hand back
	 * a Run now it had used up — `Schedule_Runner::run_one()` refuses COMPLETED and
	 * nothing else does.
	 */
	public function test_a_spent_one_shot_is_not_revived_by_undoing_its_run(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::ONCE, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->assertSame( Schedule_Status::COMPLETED, $this->schedules->find( $schedule_id )->status );

		$this->complete( $op_id );
		$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$this->assertSame( Schedule_Status::COMPLETED, $this->schedules->find( $schedule_id )->status );
	}

	/**
	 * A schedule the supervisor already stopped is not going to fire, so there is
	 * nothing to prevent — and its recorded reason is a diagnosis worth more than
	 * restating something the history already shows.
	 */
	public function test_an_already_paused_schedule_keeps_the_reason_it_had(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$this->schedules->set_status( $schedule_id, Schedule_Status::PAUSED, 'No provider handles the field "colour".' );

		$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$schedule = $this->schedules->find( $schedule_id );
		$this->assertSame( Schedule_Status::PAUSED, $schedule->status );
		$this->assertSame( 'No provider handles the field "colour".', $schedule->paused_reason );
	}

	/**
	 * The run outlives the schedule that made it — the id stays on the operation
	 * rather than cascading — so the undo has to cope with a schedule that is no
	 * longer there. It is the user's actual request; a dangling reference must not
	 * cost them it.
	 */
	public function test_an_undo_survives_the_schedule_having_been_deleted(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$this->schedules->delete( $schedule_id );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$this->assertGreaterThan( 0, $undo_id );
		$this->assertTrue( $this->operations->find( $undo_id )->is_undo() );
	}

	public function test_the_undo_preview_names_a_live_schedule(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$preview = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP );

		$this->assertNotNull( $preview['schedule'] );
		$this->assertSame( $schedule_id, $preview['schedule']['id'] );
		$this->assertSame( 'Nightly cut', $preview['schedule']['name'] );
	}

	/**
	 * The sentence this feeds promises that something is about to be stopped, so a
	 * schedule that cannot fire must not appear in it — the panel would raise a
	 * worry the user then has to go and dismiss.
	 */
	public function test_the_undo_preview_says_nothing_about_a_schedule_that_cannot_fire(): void {
		$this->make_product( 50 );

		$schedule_id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );
		$op_id       = $this->fire( $schedule_id );
		$this->complete( $op_id );

		$this->schedules->set_status( $schedule_id, Schedule_Status::PAUSED, 'Stopped earlier.' );

		$this->assertNull( $this->service->preview_undo( $op_id, Conflict_Policy::SKIP )['schedule'] );
	}

	public function test_the_undo_preview_says_nothing_when_no_schedule_was_involved(): void {
		$op_id = $this->ui_operation();
		$this->complete( $op_id );

		$this->assertNull( $this->service->preview_undo( $op_id, Conflict_Policy::SKIP )['schedule'] );
	}

	/**
	 * Fire a due schedule and return the operation it spawned.
	 *
	 * @param int $schedule_id The schedule to fire.
	 */
	private function fire( int $schedule_id ): int {
		$this->assertSame( 1, $this->runner->run_due( '2026-08-10 12:00:00' ) );

		$op_id = $this->schedules->find( $schedule_id )->last_op_id;
		$this->assertNotNull( $op_id );

		return (int) $op_id;
	}

	/**
	 * Leave a run looking exactly like one whose process died: still `running`, still
	 * holding the lock, with a heartbeat older than the supervisor tolerates. Written
	 * straight to the row because there is no honest way to ask a live process to
	 * stop breathing.
	 *
	 * @param int $op_id The operation to strand.
	 */
	private function strand( int $op_id ): void {
		global $wpdb;

		$this->operations->set_status( $op_id, Operation_Status::RUNNING );

		$wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => gmdate( 'Y-m-d H:i:s', time() - Watchdog::STALL_THRESHOLD - 60 ) ),
			array( 'id' => $op_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Settle an operation so it can be undone, and release the write lock the fire
	 * took — the runner would normally do both when the last chunk lands.
	 *
	 * @param int $op_id The operation to finish.
	 */
	private function complete( int $op_id ): void {
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
		delete_option( 'catalogops_active_operation' );
	}

	/**
	 * An operation of the kind the admin screen starts: no schedule behind it.
	 */
	private function ui_operation(): int {
		return $this->operations->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
	}

	/**
	 * Store a schedule whose template is answerable, so firing it produces a real
	 * operation.
	 *
	 * @param Recurrence $recurrence How often it fires.
	 * @param string     $next_run   First fire time (GMT MySQL datetime).
	 */
	private function create_schedule( Recurrence $recurrence, string $next_run ): int {
		return $this->schedules->create(
			'Nightly cut',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			$recurrence,
			$next_run,
			'',
			1
		);
	}

	/**
	 * A simple product the schedule's filter will match.
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
