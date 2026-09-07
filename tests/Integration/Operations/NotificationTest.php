<?php
/**
 * Integration test for scheduled-operation completion reports (M5, CONTEXT §4).
 *
 * Drives an operation to completion through the real pipeline and asserts the
 * notifier emails a report — for every source and whether or not anything was
 * skipped, since silence on a clean run was withdrawn (see {@see
 * \CatalogOps\Operations\Notifier}). Outgoing mail is intercepted with the
 * `pre_wp_mail` short-circuit so nothing actually sends.
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
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Status;
use CatalogOps\Operations\Watchdog;
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
	private Changes $changes;
	private Lock $lock;
	private Notifier $notifier;
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
		remove_all_actions( 'catalogops_operation_failed' );
		remove_all_actions( 'catalogops_schedule_paused' );

		$this->changes  = $changes;
		$this->lock     = $lock;
		$this->notifier = new Notifier( $this->operations, $changes, $this->schedules );

		add_action( 'catalogops_operation_completed', array( $this->notifier, 'notify' ) );
		add_action( 'catalogops_operation_failed', array( $this->notifier, 'notify_failed' ) );
		add_action( 'catalogops_schedule_paused', array( $this->notifier, 'notify_schedule_paused' ), 10, 2 );

		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_filter( 'pre_option_admin_email', array( $this, 'admin_email' ) );
		// A test that silences a source must not leave the next one deaf, and an
		// assertion failing part way through would skip its own removal.
		remove_all_filters( 'catalogops_send_notifications' );
		remove_all_actions( 'catalogops_operation_completed' );
		remove_all_actions( 'catalogops_operation_failed' );
		remove_all_actions( 'catalogops_schedule_paused' );
		delete_option( 'catalogops_active_operation' );
		delete_option( 'catalogops_writer_active' );

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

	/**
	 * A captured mail's body as readable text: tags removed and runs of whitespace
	 * collapsed, which is what the figures look like once a mail client has laid the
	 * table out. Asserting on the markup instead would pin the styling, and the
	 * styling is the part most likely to be adjusted.
	 *
	 * @param int $index Which captured mail.
	 */
	private function body( int $index = 0 ): string {
		// Each tag becomes a space, rather than nothing. Stripping them outright
		// glues a label to its figure — "Changed:1" — which is not what any reader
		// sees, since every one of these tags is a cell or block boundary.
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $this->mails[ $index ]['message'] );
		$text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * A captured mail's headers, as one string.
	 *
	 * @param int $index Which captured mail.
	 */
	private function headers( int $index = 0 ): string {
		$headers = $this->mails[ $index ]['headers'] ?? array();

		return is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;
	}

	/**
	 * A run with something to report says so. The second product already holds the
	 * target price, so it is written, comes back unchanged, and is recorded as
	 * skipped — which is the kind of thing the reader cannot see from the outside
	 * and is the reason the report exists at all.
	 */
	public function test_a_scheduled_run_with_skips_emails_a_report(): void {
		$this->make_product( 50 );
		$this->make_product( 9.99 );

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
		$this->assertStringContainsString( 'Changed: 1', $this->body() );
		$this->assertStringContainsString( 'Skipped: 1', $this->body() );

		// The report is HTML, and the figures that earned a colour carry one: a
		// non-zero skip count is the amber the admin screens use, and the applied
		// count is green. This is the whole reason the message is not plain text.
		$this->assertStringContainsString( 'text/html', $this->headers() );
		$this->assertStringContainsString( '#d97706', (string) $this->mails[0]['message'] );
		$this->assertStringContainsString( '#15803d', (string) $this->mails[0]['message'] );

		// And the breakdown under the skip count survives, because a bare figure
		// leaves the reader guessing at the moment they cannot come and look.
		$this->assertStringContainsString( 'the value was already set', $this->body() );
	}

	/**
	 * And a run that did everything it promised reports too.
	 *
	 * This used to assert the opposite, on the argument that an hourly schedule
	 * sending twenty-four cheerful reports a day teaches its reader to delete the
	 * twenty-fifth unopened. What outweighed it is that silence cannot be read: a
	 * clean run, a schedule that never fired, cron not reaching the site and a host
	 * dropping outgoing mail all produce exactly no mail, and telling them apart
	 * meant opening a screen this class exists to spare the reader. A report that
	 * always arrives is the only one whose absence means something.
	 */
	public function test_a_scheduled_run_with_nothing_to_report_still_says_so(): void {
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
		$this->drive( (int) $this->schedules->find( $id )->last_op_id );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Nightly cut', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Changed: 1', $this->body() );
		// The two lines that carry the whole point of a clean report: nothing was
		// held back, and the reader does not have to go and check.
		$this->assertStringContainsString( 'Skipped: 0', $this->body() );
		$this->assertStringContainsString( 'Failed: 0', $this->body() );

		// A nought is printed in the body colour. Colouring it would spend the alarm
		// on the case that needs none, and teach the eye to skip past the colour on
		// the day it means something.
		$this->assertStringNotContainsString( '#d97706', (string) $this->mails[0]['message'] );
		$this->assertStringNotContainsString( '#b91c1c', (string) $this->mails[0]['message'] );
	}

	public function test_scheduled_report_falls_back_to_admin_email_when_unset(): void {
		add_filter( 'pre_option_admin_email', array( $this, 'admin_email' ) );
		$this->make_product( 50 );
		$this->make_product( 9.99 );

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

	/**
	 * The message the plugin most needed and did not have. A run that died announced
	 * nothing at all, so the person who set the schedule up found out by opening a
	 * screen they had no reason to open.
	 */
	public function test_a_run_that_is_given_up_on_says_so(): void {
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

		// Left running with a heartbeat older than the watchdog tolerates: a process
		// that went away and could not be carried on.
		global $wpdb;
		$this->operations->set_status( $op_id, Operation_Status::RUNNING );
		$wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => gmdate( 'Y-m-d H:i:s', time() - Watchdog::STALL_THRESHOLD - 60 ) ),
			array( 'id' => $op_id ),
			array( '%s' ),
			array( '%d' )
		);

		( new Watchdog( $this->operations, $this->lock ) )->run();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'stopped', $this->mails[0]['subject'] );
		// And it says the work is recoverable, which is the part the reader can act on.
		$this->assertStringContainsString( 'Resume', $this->body() );
	}

	/**
	 * A schedule that stops itself is worse than a failed run: there is no row in the
	 * history to notice, it simply never happens again. The hook that announces it
	 * existed all along with nobody listening.
	 */
	public function test_a_schedule_that_stops_itself_says_so(): void {
		$id = $this->schedules->create(
			'Nightly cut',
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::HOURLY,
			'2026-08-10 11:59:00',
			'ops@example.com',
			1
		);

		$this->schedules->set_status( $id, Schedule_Status::PAUSED, 'No provider handles the field "colour".' );
		do_action( 'catalogops_schedule_paused', $id, new \RuntimeException( 'No provider handles the field "colour".' ) );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Nightly cut', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'colour', $this->body() );
	}

	/**
	 * An interactive run reports as well, to the site admin, and says plainly that it
	 * was not a scheduled one — the subject is what a reader sorts on, so a manual
	 * edit must not arrive looking like a schedule fired.
	 */
	public function test_a_ui_operation_reports_to_the_site_admin(): void {
		add_filter( 'pre_option_admin_email', array( $this, 'admin_email' ) );
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

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'boss@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( 'Operation completed', $this->mails[0]['subject'] );
		$this->assertStringNotContainsString( 'Scheduled', $this->mails[0]['subject'] );
	}

	/**
	 * The volume the always-report rule creates is the honest cost of it, so the
	 * opt-out has to be able to name one source and leave the rest audible. It is
	 * handed the operation for exactly that.
	 */
	public function test_the_filter_can_silence_one_source_and_keep_the_rest(): void {
		$quiet_ui = static function ( bool $send, $operation ): bool {
			return ( null !== $operation && Operation_Source::UI === $operation->source ) ? false : $send;
		};

		add_filter( 'catalogops_send_notifications', $quiet_ui, 10, 2 );

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

		$this->assertSame( array(), $this->mails, 'A silenced source must send nothing.' );

		remove_filter( 'catalogops_send_notifications', $quiet_ui, 10 );

		// And the same site still hears about scheduled work.
		$id = $this->schedules->create(
			'Nightly cut',
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '4.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::ONCE,
			'2026-08-10 11:59:00',
			'ops@example.com',
			1
		);

		$this->schedule_runner->run_due( '2026-08-10 12:00:00' );
		$this->drive( (int) $this->schedules->find( $id )->last_op_id );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ops@example.com', $this->mails[0]['to'] );
	}

	/**
	 * The shell: a ruled header carrying the mark and the shop, and a ruled footer
	 * saying who to talk to. Both are asserted loosely — the presence of the rules
	 * and the contact line, not the pixel values — because the styling is the part
	 * most likely to be adjusted and the structure is the part that must not be.
	 *
	 * The listener count is the real assertion here. A multipart body needs
	 * `phpmailer_init`, and a listener left registered would staple this run's
	 * report onto the next mail any plugin on the site sends.
	 */
	public function test_the_report_is_wrapped_and_leaves_no_listener_behind(): void {
		$before = $this->phpmailer_listeners();

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
		$this->drive( (int) $this->schedules->find( $id )->last_op_id );

		$this->assertCount( 1, $this->mails );
		$html = (string) $this->mails[0]['message'];

		$this->assertStringContainsString( 'CatalogOps', $html, 'The header carries the mark.' );
		$this->assertStringContainsString( 'border-bottom', $html, 'The header is ruled off from the content.' );
		$this->assertStringContainsString( 'border-top', $html, 'The footer is ruled off from the content.' );
		$this->assertStringContainsString( 'Sent automatically by CatalogOps', $this->body() );

		// No logo is emitted by default: the bundled mark is an SVG, which the large
		// mail clients strip, so the wordmark stands in until a site supplies a raster.
		$this->assertStringNotContainsString( '<img', $html );

		$this->assertSame(
			$before,
			$this->phpmailer_listeners(),
			'The plain-text listener must be removed after the send.'
		);
	}

	/**
	 * How many callbacks are registered on `phpmailer_init` right now.
	 */
	private function phpmailer_listeners(): int {
		global $wp_filter;

		if ( ! isset( $wp_filter['phpmailer_init'] ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $wp_filter['phpmailer_init']->callbacks as $callbacks ) {
			$count += count( $callbacks );
		}

		return $count;
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
