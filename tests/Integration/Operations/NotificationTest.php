<?php
/**
 * Integration test for scheduled-operation completion reports (M5, CONTEXT §4).
 *
 * Drives a scheduled operation to completion through the real pipeline and
 * asserts the notifier emails a report; a UI operation, watched live, does not.
 * Outgoing mail is intercepted with the `pre_wp_mail` short-circuit so nothing
 * actually sends.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Notifier;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedules;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Notifier
 * @covers \CatalogOps\Operations\Chunk_Runner
 */
final class NotificationTest extends Operations_Database_Case {

	private Operations $operations;
	private Schedules $schedules;
	private Operation_Service $service;
	private Chunk_Runner $chunk_runner;
	private Schedule_Runner $schedule_runner;

	/**
	 * Captured outgoing mails (each the wp_mail $atts array).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

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

		$this->service         = new Operation_Service( $engine, $this->operations, $changes, $providers, $lock, $scheduler );
		$this->chunk_runner    = new Chunk_Runner( $this->operations, $changes, $providers, $scheduler, $lock );
		$this->schedule_runner = new Schedule_Runner( $this->schedules, $this->service, $this->operations );

		// The plugin is booted in the test WordPress, so its own notifier is already
		// on this hook; clear it so exactly one notifier (ours, over these repos)
		// fires and the mail count is deterministic.
		remove_all_actions( 'catalogops_operation_completed' );
		$notifier = new Notifier( $this->operations, $changes, $this->schedules );
		add_action( 'catalogops_operation_completed', array( $notifier, 'notify' ) );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_filter( 'pre_option_admin_email', array( $this, 'admin_email' ) );
		remove_all_actions( 'catalogops_operation_completed' );

		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
		$this->mails   = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	/**
	 * Short-circuit wp_mail and record what would have been sent.
	 *
	 * @param mixed                $short Short-circuit value (null to proceed).
	 * @param array<string, mixed> $atts  The wp_mail arguments.
	 * @return bool Always true, so wp_mail reports success without sending.
	 */
	public function capture_mail( $short, $atts ): bool {
		$this->mails[] = $atts;

		return true;
	}

	/**
	 * Filter the admin email option without an update, so no admin-email-change
	 * notification is sent (which would count as an extra mail).
	 */
	public function admin_email(): string {
		return 'boss@example.com';
	}

	public function test_scheduled_operation_emails_a_report(): void {
		$this->make_product( 50 );

		$id = $this->schedules->create(
			'Nightly cut',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::ONCE,
			'2026-08-10 11:59:00',
			'ops@example.com',
			1
		);

		$this->schedule_runner->run_due( '2026-08-10 12:00:00' );
		$op_id = (int) $this->schedules->find( $id )->last_op_id;
		$this->drive( $op_id );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Nightly cut', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Changed:  1', $this->mails[0]['message'] );
	}

	public function test_scheduled_report_falls_back_to_admin_email_when_unset(): void {
		add_filter( 'pre_option_admin_email', array( $this, 'admin_email' ) );
		$this->make_product( 50 );

		$id = $this->schedules->create(
			'',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::ONCE,
			'2026-08-10 11:59:00',
			'',
			1
		);

		$this->schedule_runner->run_due( '2026-08-10 12:00:00' );
		$this->drive( (int) $this->schedules->find( $id )->last_op_id );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'boss@example.com', $this->mails[0]['to'] );
	}

	public function test_ui_operation_does_not_notify(): void {
		$this->make_product( 50 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * Drive an operation to completion, one small chunk at a time.
	 *
	 * @param int $op_id Operation id.
	 */
	private function drive( int $op_id ): void {
		$safety = 0;

		while ( $this->operations->find( $op_id )->status->is_active() && $safety++ < 200 ) {
			$this->chunk_runner->run( $op_id, 2 );
		}
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
