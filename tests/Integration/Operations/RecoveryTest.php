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
use CatalogOps\Operations\Watchdog;
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
	 * for ever. The housekeeping is patient now, not abandoned.
	 */
	public function test_a_stale_writer_flag_is_cleared_once_the_hold_is_old_enough(): void {
		$op_id = $this->queued_run();
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
		$this->age_the_hold();

		$this->assertSame( $op_id, $this->lock->watching() );
		$this->assertFalse( $this->recovery->run() );
		$this->assertSame( 0, $this->lock->watching() );
	}

	/**
	 * The other half of the same rule, and the deliberate sibling of
	 * `test_a_draft_still_being_prepared_keeps_its_lock`: a hold granted a moment ago
	 * over a row that is not active is not evidence of a corpse. It is what
	 * {@see Operation_Service::resume()} looks like between taking the lock and
	 * writing the status, and freeing it there hands a live catalogue to a second
	 * writer.
	 */
	public function test_a_settled_run_that_has_only_just_stopped_keeps_its_flag(): void {
		$op_id = $this->queued_run();
		$this->operations->set_status( $op_id, Operation_Status::FAILED );

		// A fresh hold over a settled row: exactly the state resume() publishes.
		$this->lock->acquire( $op_id );
		$mine = $this->lock->generation();

		$this->assertFalse( $this->recovery->run() );

		$this->assertTrue( $this->lock->still_held( $mine ) );
		$this->assertSame( $op_id, $this->lock->watching() );
	}

	/**
	 * W1 as an interleaving rather than a state, driven through the real service at
	 * the only instant it exists.
	 *
	 * {@see Lock::acquire()} fires this hook after both option writes and before
	 * `resume()` has written anything to the row, which is precisely what another
	 * request sees. The run must come out of it holding the lock it was granted, and
	 * — the assertion that catches a recovery which handed the run on rather than
	 * freeing it — with exactly one chunk enqueued.
	 */
	public function test_a_resume_in_flight_does_not_lose_its_lock_to_recovery(): void {
		$op_id = $this->failed_run_with_work_left();

		$recovery  = $this->recovery;
		$intruded  = false;
		$recovered = 0;
		$before    = $this->scheduler->count();

		// Recovery writes options of its own, so this must not re-enter.
		$intrude = static function () use ( $recovery, &$intruded ): void {
			if ( $intruded ) {
				return;
			}

			$intruded = true;
			$recovery->run();
		};

		$count = static function () use ( &$recovered ): void {
			++$recovered;
		};

		add_action( 'update_option_catalogops_writer_active', $intrude );
		add_action( 'add_option_catalogops_writer_active', $intrude );
		add_action( 'catalogops_operation_recovered', $count );

		try {
			$this->service->resume( $op_id );
		} finally {
			remove_action( 'update_option_catalogops_writer_active', $intrude );
			remove_action( 'add_option_catalogops_writer_active', $intrude );
			remove_action( 'catalogops_operation_recovered', $count );
		}

		$this->assertTrue( $intruded, 'recovery never ran inside the window, so nothing was tested' );

		$this->assertSame( $op_id, $this->lock->holder() );
		$this->assertSame( $op_id, $this->lock->watching() );
		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $op_id )->status );

		// One chunk, not two: recovery neither freed the lock nor handed the run on.
		$this->assertSame( $before + 1, $this->scheduler->count() );
		$this->assertSame( 0, $recovered );
	}

	/**
	 * W2, the window on the other side of the lock: between the status write and the
	 * heartbeat, a resumed run reads active and — because ten minutes of silence is
	 * what failed it — cold, which is the full hand-off path, not merely a freed
	 * flag. Touched first, there is no such instant.
	 *
	 * Observed through core's `query` filter because {@see Operations::set_status()}
	 * fires no hooks of its own: catch the UPDATE that writes `queued` for this
	 * operation and read the heartbeat as it stood at that moment.
	 */
	public function test_a_resumed_run_is_warm_before_it_is_active(): void {
		global $wpdb;

		$op_id = $this->failed_run_with_work_left();
		$this->chill( $op_id );

		$table  = $this->schema->operations_table();
		$caught = null;
		$busy   = false;

		$watch = function ( $query ) use ( $table, $op_id, &$caught, &$busy ) {
			if ( $busy || null !== $caught ) {
				return $query;
			}

			if ( ! str_contains( $query, 'UPDATE' ) || ! str_contains( $query, $table ) ) {
				return $query;
			}

			if ( ! str_contains( $query, "'queued'" ) || ! str_contains( $query, (string) $op_id ) ) {
				return $query;
			}

			// The nested read goes through this same filter.
			$busy   = true;
			$caught = $this->operations->find( $op_id )->last_progress_at;
			$busy   = false;

			return $query;
		};

		add_filter( 'query', $watch );

		try {
			$this->service->resume( $op_id );
		} finally {
			remove_filter( 'query', $watch );
		}

		$this->assertNotNull( $caught, 'the status write was never seen, so nothing was tested' );

		// Warm at the instant the row went active — not the ten-minute-old stamp the
		// watchdog failed it on, which is what recovery would have read as cold.
		$this->assertGreaterThan(
			gmdate( 'Y-m-d H:i:s', time() - Recovery::COLD_AFTER ),
			$caught
		);
	}

	/**
	 * The `queue()` half of W2. A draft has no heartbeat, so coldness falls back to
	 * `created_at` — and after a freeze that took minutes the row is already past
	 * {@see Recovery::COLD_AFTER} at the instant it goes active. A run that has never
	 * executed a chunk must not be handed to a second worker on those grounds.
	 */
	public function test_a_run_queued_after_a_long_freeze_is_not_handed_on_immediately(): void {
		foreach ( range( 1, 3 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		// What a freeze of minutes leaves behind: a draft older than COLD_AFTER, and
		// no heartbeat of its own to be judged by.
		$this->backdate_creation( $op_id );

		$recovered = 0;
		$count     = static function () use ( &$recovered ): void {
			++$recovered;
		};
		add_action( 'catalogops_operation_recovered', $count );

		$intruded = false;
		$intruder = $this->recovery_between_the_two_writes( $op_id, $intruded );
		add_filter( 'query', $intruder );

		try {
			$this->service->queue( $op_id );
		} finally {
			remove_filter( 'query', $intruder );
			remove_action( 'catalogops_operation_recovered', $count );
		}

		$this->assertTrue( $intruded, 'recovery never ran inside the window, so nothing was tested' );

		// One chunk from queue() itself, and none from a recovery that mistook a run
		// which had never executed anything for one that had died.
		$this->assertSame( 1, $this->scheduler->count() );
		$this->assertSame( 0, $recovered );
		$this->assertSame( $op_id, $this->lock->holder() );
	}

	/**
	 * A hold written by the release before this one carries no grant time. It must
	 * read as old — the flag is tidied exactly as it is today — rather than as fresh,
	 * which would protect every pre-upgrade hold for ever.
	 */
	public function test_a_hold_granted_before_this_release_is_not_treated_as_fresh(): void {
		$op_id = $this->queued_run();
		$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );

		// The two-field shape the previous release wrote.
		update_option( 'catalogops_active_operation', $op_id . ':' . uniqid( '', true ), false );

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
	 * A `query` filter that runs recovery once, in the gap between the two writes
	 * that publish a run — the status and the heartbeat.
	 *
	 * {@see Operations::set_status()} and {@see Operations::touch()} fire no hooks,
	 * and the gap is one statement wide, so the SQL is the only seam that lands
	 * inside it. The filter runs *before* its query executes, so riding the heartbeat
	 * write puts recovery exactly where the other write has already landed under the
	 * old order and has not yet under the new one — which is the whole difference
	 * being tested.
	 *
	 * Under the old order recovery finds the row active and cold and takes the
	 * hand-off path. Under the new one it finds a row that has not gone active yet —
	 * and which branch then turns it away depends on the caller, which is worth being
	 * exact about: from `queue()` the row is still DRAFT, so the draft clause stops
	 * it; from `resume()` the row reads `failed` over a hold seconds old, so
	 * {@see Recovery::FRESH_HOLD_GRACE} does. Two windows, two guards, one ordering.
	 *
	 * @param int  $op_id    The operation whose heartbeat write to ride.
	 * @param bool $intruded Set to true when recovery actually ran, so a caller can
	 *                       assert the window was reached rather than passing because
	 *                       it never was.
	 * @return callable The filter, to be added and removed by the caller.
	 */
	private function recovery_between_the_two_writes( int $op_id, bool &$intruded ): callable {
		$recovery = $this->recovery;
		$table    = $this->schema->operations_table();
		$done     = false;
		$busy     = false;

		return static function ( $query ) use ( $recovery, $table, $op_id, &$done, &$busy, &$intruded ) {
			if ( $done || $busy ) {
				return $query;
			}

			if ( ! str_contains( $query, 'UPDATE' ) || ! str_contains( $query, $table ) ) {
				return $query;
			}

			if ( ! str_contains( $query, 'last_progress_at' ) || ! str_contains( $query, (string) $op_id ) ) {
				return $query;
			}

			// Recovery reads and writes through this same filter.
			$done     = true;
			$intruded = true;
			$busy     = true;
			$recovery->run();
			$busy = false;

			return $query;
		};
	}

	/**
	 * A run the watchdog stopped part-way, with frozen work still behind it — what
	 * Resume is for, and the state resume() is always entered from.
	 */
	private function failed_run_with_work_left(): int {
		$op_id = $this->queued_run();

		$this->operations->set_status( $op_id, Operation_Status::FAILED );
		$this->lock->release( $op_id );

		$this->assertGreaterThan( 0, $this->changes->pending_count( $op_id ) );

		return $op_id;
	}

	/**
	 * Push the current hold's grant time back, so recovery stops treating it as a
	 * hand-off that might still be in progress. The id and the generation are kept —
	 * only the third field moves — so the value keeps the shape the code writes.
	 */
	private function age_the_hold(): void {
		$parts    = explode( ':', $this->lock->generation() );
		$parts[2] = (string) ( time() - Recovery::FRESH_HOLD_GRACE - 60 );

		update_option( 'catalogops_active_operation', implode( ':', $parts ), false );
	}

	/**
	 * Age a run's heartbeat to the ten-minute silence the watchdog failed it on,
	 * without touching its status.
	 *
	 * @param int $op_id The operation to chill.
	 */
	private function chill( int $op_id ): void {
		global $wpdb;

		$wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => gmdate( 'Y-m-d H:i:s', time() - Watchdog::STALL_THRESHOLD - 60 ) ),
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
