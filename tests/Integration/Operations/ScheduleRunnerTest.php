<?php
/**
 * Integration tests for the schedule supervisor.
 *
 * Drives the scheduling layer against real WooCommerce products and the real
 * operation pipeline (with a recording scheduler so no Action Scheduler runs):
 * a due schedule fires a fresh operation, a one-shot completes, a recurring one
 * advances, and the single-writer lock defers firing.
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
use CatalogOps\Operations\Changes;
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

		$this->service = new Operation_Service( $engine, $this->operations, $changes, $providers, $lock, $scheduler );
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
	 * Create a schedule that sets price on every product over 0.
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
