<?php
/**
 * Integration tests for the schedule supervisor.
 *
 * Drives the scheduling layer against real WooCommerce products and the real
 * operation pipeline (with a recording scheduler so no Action Scheduler runs):
 * a due schedule fires a fresh operation, a one-shot completes, a recurring one
 * advances, and the single-writer lock defers firing.
 *
 * It also pins the containment added in 0.7.2: a schedule whose stored template
 * can no longer be built pauses itself and the tick carries on to the schedules
 * behind it, instead of throwing out of the supervisor and starving them for ever.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
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
use CatalogOps\Operations\Actions\Adjust;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Schedule_Runner
 * @covers \CatalogOps\Operations\Schedules
 * @covers \CatalogOps\Operations\Schedule
 */
final class ScheduleRunnerTest extends Operations_Database_Case {

	private Schedules $schedules;
	private Operations $operations;
	private Changes $changes;
	private Chunk_Runner $chunks;
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
		$this->changes    = new Changes( $wpdb, $this->schema );
		$providers        = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$lock             = new Lock( $this->operations );
		$scheduler        = new Recording_Scheduler();

		$this->service = new Operation_Service( $engine, $this->operations, $this->changes, $providers, $lock, $scheduler );
		$this->chunks  = new Chunk_Runner( $this->operations, $this->changes, $providers, $scheduler, $lock );
		$this->runner  = new Schedule_Runner( $this->schedules, $this->service, $this->operations );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_due_one_shot_fires_an_operation_and_completes(): void {
		$this->make_product( 50 );
		$now = '2026-08-10 12:00:00';

		$id = $this->create_schedule( Recurrence::ONCE, '2026-08-10 11:59:00' );

		$this->assertSame( 1, $this->runner->run_due( $now ) );

		$schedule = $this->schedules->find( $id );
		$this->assertSame( Schedule_Status::COMPLETED, $schedule->status );
		$this->assertSame( $now, $schedule->last_run );
		$this->assertNotNull( $schedule->last_op_id );

		// The fire created a real operation, marked as schedule-sourced, that ran
		// through the normal freeze/queue path.
		$operation = $this->operations->find( (int) $schedule->last_op_id );
		$this->assertSame( Operation_Source::SCHEDULE, $operation->source );
		$this->assertSame( Operation_Status::QUEUED, $operation->status );
		$this->assertSame( 1, $operation->target_count );
	}

	public function test_recurring_schedule_advances_to_its_next_run(): void {
		$this->make_product( 50 );
		$now = '2026-08-10 12:00:00';

		$id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:59:00' );

		$this->runner->run_due( $now );

		$schedule = $this->schedules->find( $id );
		$this->assertSame( Schedule_Status::ACTIVE, $schedule->status );
		$this->assertSame( '2026-08-11 11:59:00', $schedule->next_run );
		$this->assertSame( $now, $schedule->last_run );
	}

	public function test_future_schedule_is_not_fired(): void {
		$this->make_product( 50 );

		$id = $this->create_schedule( Recurrence::DAILY, '2026-08-11 09:00:00' );

		$this->assertSame( 0, $this->runner->run_due( '2026-08-10 12:00:00' ) );
		$this->assertNull( $this->schedules->find( $id )->last_run );
	}

	public function test_active_operation_defers_firing(): void {
		$this->make_product( 50 );
		$now = '2026-08-10 12:00:00';

		// First due schedule fires and its operation stays QUEUED, holding the
		// single-writer lock.
		$this->create_schedule( Recurrence::DAILY, '2026-08-10 11:00:00' );
		$this->assertSame( 1, $this->runner->run_due( $now ) );

		// A second due schedule must not fire while an operation is active.
		$second = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:30:00' );
		$this->assertSame( 0, $this->runner->run_due( $now ) );
		$this->assertNull( $this->schedules->find( $second )->last_run );
	}

	public function test_run_one_fires_regardless_of_next_run(): void {
		$this->make_product( 50 );

		$id = $this->create_schedule( Recurrence::DAILY, '2026-08-31 09:00:00' );

		$this->assertTrue( $this->runner->run_one( $id, '2026-08-10 12:00:00' ) );
		$this->assertNotNull( $this->schedules->find( $id )->last_op_id );
	}

	/**
	 * A schedule that cannot fire must not take the tick down with it: the ones
	 * queued behind it still run.
	 *
	 * On 0.7.1 `create()` sat outside the guard in `fire()`, so the throw escaped
	 * `run_due()` altogether. Worse, it never came back: the broken row never
	 * reached `record_run()`, so it kept its next_run, stayed the most overdue, and
	 * headed the `due()` list on every later tick — the good schedule behind it
	 * never fired again on this tick or any other.
	 */
	public function test_a_schedule_that_cannot_fire_does_not_starve_the_ones_behind_it(): void {
		$this->make_product( 50 );

		$broken = $this->create_broken_action_schedule( '2026-08-10 11:00:00' );
		$good   = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:30:00' );

		$this->assertSame( 1, $this->runner->run_due( '2026-08-10 12:00:00' ) );

		// The broken one is out of the way, and it never claims to have run.
		$paused = $this->schedules->find( $broken );
		$this->assertSame( Schedule_Status::PAUSED, $paused->status );
		$this->assertNull( $paused->last_run );

		$this->assertNotNull( $this->schedules->find( $good )->last_op_id );
	}

	/**
	 * Containing the failure must not cost the single-writer guarantee: a failed
	 * schedule is skipped, but the first one that actually fires still stops the
	 * tick. This is what catches a "fix" that catches inside `run_due()` and
	 * carries on past a lock refusal, which would start several operations at once.
	 */
	public function test_the_single_writer_lock_still_stops_the_tick_after_a_failed_schedule(): void {
		$this->make_product( 50 );

		$broken = $this->create_broken_action_schedule( '2026-08-10 11:00:00' );
		$first  = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:15:00' );
		$second = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:30:00' );

		// Exactly one: the broken schedule pauses without ever taking the lock, the
		// next one fires and holds it, and the loop breaks before the third.
		$this->assertSame( 1, $this->runner->run_due( '2026-08-10 12:00:00' ) );

		$this->assertSame( Schedule_Status::PAUSED, $this->schedules->find( $broken )->status );
		$this->assertNotNull( $this->schedules->find( $first )->last_op_id );
		$this->assertNull( $this->schedules->find( $second )->last_run );
	}

	/**
	 * The guard has to cover the *arguments* to `create()`, not just the call.
	 * `$schedule->filter()` rebuilds the stored template as an argument is
	 * evaluated, and a filter_json carrying an operator token `Filter::to_array()`
	 * cannot produce throws right there — so this fails against a fix that hoists
	 * the arguments into locals above the try.
	 */
	public function test_a_corrupt_operator_token_in_a_stored_filter_pauses_the_schedule(): void {
		global $wpdb;

		$id = $this->create_schedule( Recurrence::DAILY, '2026-08-10 11:00:00' );

		$corrupt = ( new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ) )->to_array();

		// Everything else is exactly the shape the serializer writes; only the token
		// is one no Operator case carries.
		$corrupt['conditions'][0]['operator'] = 'bogus';

		$wpdb->update(
			$this->schema->schedules_table(),
			array( 'filter_json' => (string) wp_json_encode( $corrupt ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame( 0, $this->runner->run_due( '2026-08-10 12:00:00' ) );
		$this->assertSame( Schedule_Status::PAUSED, $this->schedules->find( $id )->status );
	}

	/**
	 * A contained failure must still be accounted for. Before the guard existed the
	 * throw reached Action Scheduler, whose failed-action log was the only record of
	 * it anywhere; silencing that without replacing it would have been a downgrade.
	 * So the row says what stopped it and a hook is fired for a site that would
	 * rather be told. Neither existed on 0.7.1.
	 */
	public function test_a_paused_schedule_records_what_stopped_it(): void {
		$id = $this->create_broken_action_schedule( '2026-08-10 11:00:00' );

		$this->runner->run_due( '2026-08-10 12:00:00' );

		$schedule = $this->schedules->find( $id );
		$this->assertSame( Schedule_Status::PAUSED, $schedule->status );
		$this->assertStringContainsString( 'no_such_field', (string) $schedule->paused_reason );
		$this->assertGreaterThan( 0, did_action( 'catalogops_schedule_paused' ) );
	}

	/**
	 * Moving `create()` inside the guard would otherwise introduce a defect of its
	 * own: a draft row for an operation that never ran and never will, left as a
	 * permanent "draft" line in the history every time the schedule ticks. Also
	 * pins that the cleanup copes with an id of 0, which is what a throw from
	 * `create()` leaves behind.
	 */
	public function test_a_failed_fire_leaves_no_draft_operation_behind(): void {
		$this->create_broken_action_schedule( '2026-08-10 11:00:00' );

		$this->assertSame( 0, $this->runner->run_due( '2026-08-10 12:00:00' ) );
		$this->assertSame( 0, $this->operations->count_all() );
	}

	/**
	 * The column describes the pause a schedule is currently in, so resuming clears
	 * it — a schedule that is running again must never show a stale explanation of
	 * why it stopped.
	 */
	public function test_resuming_a_paused_schedule_clears_the_reason(): void {
		$id = $this->create_broken_action_schedule( '2026-08-10 11:00:00' );

		$this->runner->run_due( '2026-08-10 12:00:00' );
		$this->assertNotNull( $this->schedules->find( $id )->paused_reason );

		$this->schedules->set_status( $id, Schedule_Status::ACTIVE );

		$resumed = $this->schedules->find( $id );
		$this->assertSame( Schedule_Status::ACTIVE, $resumed->status );
		$this->assertNull( $resumed->paused_reason );
	}

	/**
	 * "Run now" travels the same guarded path: it answers false rather than
	 * throwing. On 0.7.1 the throw went straight through `run_one()` and out of the
	 * REST controller, which has no try/catch of its own, as an uncaught fatal.
	 */
	public function test_run_now_on_an_unfireable_schedule_returns_false_and_pauses_it(): void {
		$id = $this->create_broken_action_schedule( '2026-08-31 09:00:00' );

		$this->assertFalse( $this->runner->run_one( $id, '2026-08-10 12:00:00' ) );

		$schedule = $this->schedules->find( $id );
		$this->assertSame( Schedule_Status::PAUSED, $schedule->status );
		$this->assertNotNull( $schedule->paused_reason );
	}

	/**
	 * Create a schedule that sets price on every product over 0.
	 *
	 * @param Recurrence $recurrence How often it fires.
	 * @param string     $next_run   First fire time (GMT MySQL datetime).
	 */
	/**
	 * A repeat is for what has since entered the segment — not for doing the same
	 * thing again to what this schedule has already changed.
	 *
	 * The action here is deliberately *relative*, because that is the case where
	 * getting this wrong is not merely redundant but ruinous: it reads the value it
	 * is about to replace, so a second pass compounds against the first. Reported
	 * live on 2026-09-07 with an hourly `regular_price * 0.95`, where one product
	 * went 430.14 → 408.63 → 388.20 over three ticks.
	 */
	public function test_a_repeat_changes_only_what_has_since_entered_the_segment(): void {
		$first  = $this->make_product( 100 );
		$second = $this->make_product( 200 );

		$schedule_id = $this->schedules->create(
			'Hourly rise',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Adjust( 'regular_price', 10 ) ),
			Operation_Mode::SAFE,
			Recurrence::HOURLY,
			'2026-08-10 10:00:00',
			'',
			1
		);

		$this->assertSame( 1, $this->runner->run_due( '2026-08-10 10:00:00' ) );
		$this->drive_last_run_of( $schedule_id );

		$this->assertSame( '110', wc_get_product( $first )->get_regular_price() );
		$this->assertSame( '210', wc_get_product( $second )->get_regular_price() );

		// A product that appears between the two ticks — the only thing a repeat is
		// supposed to catch.
		$late = $this->make_product( 50 );

		$this->assertSame( 1, $this->runner->run_due( '2026-08-10 11:00:00' ) );
		$op_id = $this->operations->find( $this->schedules->find( $schedule_id )->last_op_id )->id;

		// Only the newcomer was frozen: the two it had already changed are not its
		// business a second time.
		$this->assertSame( 1, $this->operations->find( $op_id )->target_count );

		$this->drive_last_run_of( $schedule_id );

		$this->assertSame( '60', wc_get_product( $late )->get_regular_price() );

		// And the first two are untouched by the second tick — 110, not 120.
		$this->assertSame( '110', wc_get_product( $first )->get_regular_price() );
		$this->assertSame( '210', wc_get_product( $second )->get_regular_price() );
	}

	/**
	 * Two schedules overlapping on one product, which the owner ruled is the user's
	 * own arrangement to make: each keeps its own history, so each changes the
	 * product once — all of them on its first tick, and only newcomers after that.
	 *
	 * The consequence is that the effects stack, once per schedule, and that is the
	 * intended reading rather than an oversight: a product in two schedules is in
	 * two schedules. What must not happen is either of them applying twice.
	 */
	public function test_each_schedule_keeps_its_own_history_when_they_overlap(): void {
		$product = $this->make_product( 100 );

		$small = $this->overlapping_schedule( 'Adds ten', 10 );
		$large = $this->overlapping_schedule( 'Adds a hundred', 100 );

		// First tick: both are due, and both apply once. The lock lets one run at a
		// time, so this fires and drains them in turn.
		$this->assertSame( 2, $this->fire_and_drive_all_due( '2026-08-10 10:00:00' ) );

		$this->assertSame( '210', wc_get_product( $product )->get_regular_price() );

		// Second tick, with nothing new in the catalogue: neither has anything left
		// to do, and the price must not move again.
		$this->fire_and_drive_all_due( '2026-08-10 11:00:00' );

		$this->assertSame( '210', wc_get_product( $product )->get_regular_price() );

		// Each froze its one object on the first tick and nothing on the second.
		foreach ( array( $small, $large ) as $schedule_id ) {
			$targets = array_map(
				static fn( $row ): int => (int) $row,
				$this->targets_of( $schedule_id )
			);

			$this->assertSame( array( 1, 0 ), $targets );
		}
	}

	/**
	 * An hourly schedule matching everything priced, adding a fixed amount.
	 *
	 * @param string $name   Schedule name.
	 * @param float  $amount What to add to the regular price.
	 */
	private function overlapping_schedule( string $name, float $amount ): int {
		return $this->schedules->create(
			$name,
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Adjust( 'regular_price', $amount ) ),
			Operation_Mode::SAFE,
			Recurrence::HOURLY,
			'2026-08-10 10:00:00',
			'',
			1
		);
	}

	/**
	 * Every run a schedule has spawned, oldest first, as frozen target counts.
	 *
	 * @param int $schedule_id The schedule to report on.
	 * @return string[] Target counts in run order.
	 */
	private function targets_of( int $schedule_id ): array {
		global $wpdb;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT target_count FROM {$this->schema->operations_table()}
				 WHERE schedule_id = %d ORDER BY id ASC",
				$schedule_id
			)
		);
	}

	/**
	 * Fire everything due at a moment and run each operation to completion.
	 *
	 * The single-writer lock allows one run at a time, so a tick with two due
	 * schedules needs draining between fires — which is also what the live queue
	 * does, one chunk chain after another.
	 *
	 * @param string $now The moment to fire at (GMT MySQL datetime).
	 * @return int How many schedules fired.
	 */
	private function fire_and_drive_all_due( string $now ): int {
		$fired  = 0;
		$safety = 0;

		while ( $this->runner->run_due( $now ) > 0 && $safety++ < 10 ) {
			++$fired;

			$guard = 0;
			while ( null !== ( $active = $this->operations->active_excluding( 0 ) ) && $guard++ < 200 ) {
				$this->chunks->run( $active->id, 50 );
			}
		}

		return $fired;
	}

	/**
	 * Run the chunks of whatever operation a schedule last spawned, to completion.
	 *
	 * @param int $schedule_id The schedule whose newest run to drive.
	 */
	private function drive_last_run_of( int $schedule_id ): void {
		$op_id  = (int) $this->schedules->find( $schedule_id )->last_op_id;
		$safety = 0;

		while ( $this->operations->find( $op_id )->status->is_active() && $safety++ < 200 ) {
			$this->chunks->run( $op_id, 50 );
		}
	}

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
	 * Create a schedule whose stored template cannot be turned into an operation:
	 * its action names a field no provider handles.
	 *
	 * Nothing rejects it on the way in — `Set_Value` does not judge its field and
	 * `Schedules::create()` serializes it unexamined — so no hand-written SQL is
	 * needed. The refusal happens later, at the moment the supervisor fires it,
	 * inside `Operation_Service::create()`. Its filter is deliberately answerable,
	 * so the throw comes from the action check rather than the filter one.
	 *
	 * @param string $next_run First fire time (GMT MySQL datetime).
	 */
	private function create_broken_action_schedule( string $next_run ): int {
		return $this->schedules->create(
			'Broken template',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'no_such_field', '1' ) ),
			Operation_Mode::SAFE,
			Recurrence::DAILY,
			$next_run,
			'',
			1
		);
	}

	/**
	 * Create a simple, in-stock product at a given price.
	 *
	 * @param float $price Regular price.
	 * @return int Product id.
	 */
	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}
}
