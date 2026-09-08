<?php
/**
 * Integration tests for the write lock's generation, and the fence it makes possible.
 *
 * The lock has always named its holder. Naming was enough while the only way to
 * lose it was to finish, but recovery changed that: a run whose process appears to
 * have died is handed back to a new worker under the *same operation id*, on the
 * strength of a heartbeat that has gone cold. A heartbeat is evidence, never proof
 * — a process can be blocked rather than dead — and such a process would wake,
 * read the holder, recognise its own id, and go on writing beside its replacement.
 *
 * Two writers over one catalogue is bad anywhere. Here it is specific: an Adjust
 * reads the field it writes, so "add 15" applied by both is not a repeated change
 * but a wrong number, and no later reading of the audit log would explain it.
 *
 * The generation is what turns "my id" into "my turn". These tests pin that a
 * grant always mints one, that the previous holder can tell it has lost it, and
 * that a worker which has lost it stops writing and — just as important — does not
 * re-enter the chain or settle the operation on its way out.
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
use CatalogOps\Operations\Operation_Blocked;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Lock
 * @covers \CatalogOps\Operations\Chunk_Runner
 */
final class LockGenerationTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Lock $lock;
	private Operation_Service $service;
	private Chunk_Runner $runner;
	private Recording_Scheduler $scheduler;

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
		$providers        = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$this->lock       = new Lock( $this->operations );
		$this->scheduler  = new Recording_Scheduler();

		$this->service = new Operation_Service( $engine, $this->operations, $this->changes, $providers, $this->lock, $this->scheduler );
		$this->runner  = new Chunk_Runner( $this->operations, $this->changes, $providers, $this->scheduler, $this->lock );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_the_holder_is_still_readable_as_a_plain_id(): void {
		$this->assertTrue( $this->lock->acquire( 41 ) );

		// Every reader that predates the generation asks this question and must keep
		// getting the same answer.
		$this->assertSame( 41, $this->lock->holder() );
		$this->assertStringStartsWith( '41:', $this->lock->generation() );
	}

	public function test_each_grant_mints_a_new_generation(): void {
		$this->lock->acquire( 41 );
		$first = $this->lock->generation();

		// The same operation asking again is recovery handing its run to a new
		// worker, not a formality — so it gets a new turn, not the old one back.
		$this->lock->acquire( 41 );

		$this->assertNotSame( $first, $this->lock->generation() );
		$this->assertSame( 41, $this->lock->holder() );
	}

	public function test_the_previous_holder_can_tell_it_has_lost_its_turn(): void {
		$this->lock->acquire( 41 );
		$mine = $this->lock->generation();

		$this->assertTrue( $this->lock->still_held( $mine ) );

		$this->lock->acquire( 41 );

		$this->assertFalse( $this->lock->still_held( $mine ) );
	}

	public function test_a_released_lock_is_held_by_nobody(): void {
		$this->lock->acquire( 41 );
		$mine = $this->lock->generation();

		$this->lock->release( 41 );

		$this->assertSame( 0, $this->lock->holder() );
		$this->assertFalse( $this->lock->still_held( $mine ) );
	}

	/**
	 * The grant time rides in the lock value as a third field, and neither reader
	 * that predates it may notice.
	 */
	public function test_a_grant_records_when_it_happened_and_stays_a_plain_id(): void {
		$before = time();
		$this->lock->acquire( 41 );

		$this->assertGreaterThanOrEqual( $before, $this->lock->granted_at() );
		$this->assertLessThanOrEqual( time(), $this->lock->granted_at() );

		$this->assertSame( 41, $this->lock->holder() );
		$this->assertStringStartsWith( '41:', $this->lock->generation() );
	}

	/**
	 * A hold written by the release before this one has no third field. It must read
	 * as granted long ago rather than as granted now — "absent" means the hold is old
	 * enough that nobody is waiting on it, which is true of every such hold but the
	 * one in flight at upgrade time. Reading it as fresh would protect every
	 * pre-upgrade hold for ever and leave a dead flag costing a row read per request.
	 */
	public function test_a_hold_from_the_previous_release_reads_as_granted_long_ago(): void {
		update_option( 'catalogops_active_operation', '41:' . uniqid( '', true ), false );

		$this->assertSame( 0, $this->lock->granted_at() );
		$this->assertSame( 41, $this->lock->holder() );
	}

	/**
	 * The credential half. A caller that arrives to release on the strength of
	 * something it read a moment ago must not free a hold granted after it looked —
	 * which is recovery's housekeeping against a resume that has just taken the lock.
	 */
	public function test_a_release_that_names_a_hold_it_no_longer_owns_frees_nothing(): void {
		$this->lock->acquire( 41 );
		$stale = $this->lock->generation();

		// Recovery re-granting the same operation to a new worker, or a resume taking
		// its lock back: same id, new turn.
		$this->lock->acquire( 41 );
		$current = $this->lock->generation();

		$this->lock->release( 41, $stale );

		$this->assertSame( 41, $this->lock->holder() );
		$this->assertTrue( $this->lock->still_held( $current ) );
	}

	/**
	 * Most callers are not giving back a hold they took — they are taking a run down,
	 * and freeing whatever it holds is the act being asked for. `cancel()`,
	 * `take_over()`, `delete()` and the watchdog all rely on the id-only match.
	 */
	public function test_a_release_that_names_no_hold_still_frees_the_operation(): void {
		$this->lock->acquire( 41 );

		$this->lock->release( 41 );

		$this->assertSame( 0, $this->lock->holder() );
	}

	/**
	 * The fence itself, end to end: a run is taken from its worker mid-chunk, and the
	 * worker stops rather than writing beside its replacement. It must also leave the
	 * chain alone — enqueueing would put it back in a sequence it has been removed
	 * from, and finalizing would settle an operation another process is still writing.
	 */
	public function test_a_worker_that_loses_its_turn_stops_and_leaves_the_chain(): void {
		foreach ( range( 1, 6 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '4.44' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		$pending_before = $this->changes->pending_count( $op_id );
		$this->assertGreaterThan( 0, $pending_before );

		// The steal has to land *while* the worker is inside its loop — it captures
		// its generation on entry, so taking the lock beforehand would simply hand it
		// the new one and prove nothing. Riding a per-object WooCommerce hook puts the
		// hand-over exactly where a real one would fall: between two saves.
		$lock  = $this->lock;
		$taken = false;

		$steal = static function () use ( $lock, $op_id, &$taken ): void {
			if ( $taken ) {
				return;
			}

			$taken = true;
			$lock->acquire( $op_id );
		};

		// Beat on every object, so the check is reached without a five-second test.
		$pulse_every_object = static fn(): float => 0.0;

		add_action( 'woocommerce_update_product', $steal );
		add_filter( 'catalogops_pulse_seconds', $pulse_every_object );

		try {
			$this->runner->run( $op_id, 100 );
		} finally {
			remove_action( 'woocommerce_update_product', $steal );
			remove_filter( 'catalogops_pulse_seconds', $pulse_every_object );
		}

		$this->assertTrue( $taken, 'the hand-over never happened, so nothing was tested' );

		// It did not settle the operation, and it did not put itself back in the queue.
		$operation = $this->operations->find( $op_id );
		$this->assertNotSame( Operation_Status::COMPLETED, $operation->status );
		$this->assertGreaterThan( 0, $this->changes->pending_count( $op_id ) );
	}

	/**
	 * A worker that never had an identity has none to lose. `still_held('')` is false
	 * by construction, so fencing on an empty generation would break the chain of a
	 * run nothing was contending — and silently, since a surrendering worker neither
	 * enqueues nor errors. It must run normally instead.
	 */
	public function test_a_worker_with_no_generation_is_not_fenced_out(): void {
		foreach ( range( 1, 4 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '4.44' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		// The lock gone before the chunk starts: nothing to identify this worker by.
		delete_option( 'catalogops_active_operation' );
		$this->assertSame( '', $this->lock->generation() );

		$pulse_every_object = static fn(): float => 0.0;
		add_filter( 'catalogops_pulse_seconds', $pulse_every_object );

		try {
			$this->runner->run( $op_id, 100 );
		} finally {
			remove_filter( 'catalogops_pulse_seconds', $pulse_every_object );
		}

		// It did the work rather than abandoning the run after one object.
		$this->assertSame( 0, $this->changes->pending_count( $op_id ) );
	}

	/**
	 * A preparation must not hand work to the scheduler on a lock it no longer owns.
	 *
	 * {@see Lock::acquire()} refuses only a holder whose row reads *active*, and a
	 * draft is not active — so for the whole of `queue()`'s freeze, which is minutes
	 * on a real catalogue, any second operation asking for the lock is simply given
	 * it. Before this, the first preparation carried on regardless: it published its
	 * row, enqueued a chunk and started writing beside the thief, and
	 * {@see \CatalogOps\Operations\Actions\Adjust} reads the field it writes.
	 *
	 * So the preparer captures the hold it was granted and checks it is still its own
	 * before it publishes. Losing it is the same answer the caller would have had if
	 * the lock had been busy a moment earlier — refused, with the draft discarded and
	 * nothing queued.
	 */
	public function test_a_preparation_that_loses_the_lock_while_freezing_does_not_publish(): void {
		foreach ( range( 1, 4 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '4.44' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		// A second operation asking for the lock mid-freeze, which acquire() grants
		// because this row still reads `draft`. Riding the seeding INSERT puts the
		// steal inside the freeze, where a real one would fall.
		$lock   = $this->lock;
		$table  = $this->schema->changes_table();
		$thief  = 4242;
		$stolen = false;

		$steal = static function ( $query ) use ( $lock, $table, $thief, &$stolen ) {
			if ( $stolen || ! str_contains( $query, 'INSERT' ) || ! str_contains( $query, $table ) ) {
				return $query;
			}

			$stolen = true;
			$lock->acquire( $thief );

			return $query;
		};

		add_filter( 'query', $steal );

		try {
			$this->service->queue( $op_id );
			$this->fail( 'Expected queueing to be refused after the lock was taken.' );
		} catch ( Operation_Blocked $e ) {
			$this->assertStringContainsString( 'already writing', $e->getMessage() );
		} finally {
			remove_filter( 'query', $steal );
		}

		$this->assertTrue( $stolen, 'the lock was never taken, so nothing was tested' );

		// Nothing was handed to the queue, and the draft is gone rather than left in
		// the history as an operation the user was told had been refused.
		$this->assertSame( 0, $this->scheduler->count() );
		$this->assertNull( $this->operations->find( $op_id ) );

		// And the thief keeps what it took.
		$this->assertSame( $thief, $this->lock->holder() );
	}

	/**
	 * The same rule on the restart path. `resume()` has no freeze, so its window is
	 * statements rather than minutes — but the row reads `failed` for all of it, and
	 * that is all {@see Lock::acquire()} looks at.
	 *
	 * A refused restart must leave the run exactly as it found it: still failed, with
	 * its frozen list intact, so the user can try again once the catalogue is free.
	 */
	public function test_a_restart_that_loses_the_lock_before_publishing_does_not_run(): void {
		foreach ( range( 1, 4 ) as $i ) {
			$this->make_product( 10 * $i );
		}

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( new Set_Value( 'regular_price', '4.44' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->operations->set_status( $op_id, Operation_Status::FAILED );

		$pending = $this->changes->pending_count( $op_id );
		$this->assertGreaterThan( 0, $pending );

		$enqueued = $this->scheduler->count();

		// The steal rides the heartbeat write, which under this ordering is the first
		// statement after the lock is taken and before the row goes active.
		$lock   = $this->lock;
		$table  = $this->schema->operations_table();
		$thief  = 4242;
		$stolen = false;

		$steal = static function ( $query ) use ( $lock, $table, $thief, &$stolen ) {
			if ( $stolen || ! str_contains( $query, 'UPDATE' ) || ! str_contains( $query, $table ) ) {
				return $query;
			}

			if ( ! str_contains( $query, 'last_progress_at' ) ) {
				return $query;
			}

			$stolen = true;
			$lock->acquire( $thief );

			return $query;
		};

		add_filter( 'query', $steal );

		try {
			$this->service->resume( $op_id );
			$this->fail( 'Expected the restart to be refused after the lock was taken.' );
		} catch ( Operation_Blocked $e ) {
			$this->assertStringContainsString( 'already writing', $e->getMessage() );
		} finally {
			remove_filter( 'query', $steal );
		}

		$this->assertTrue( $stolen, 'the lock was never taken, so nothing was tested' );

		// Left exactly as it was found: still failed, nothing queued, list intact.
		$this->assertSame( Operation_Status::FAILED, $this->operations->find( $op_id )->status );
		$this->assertSame( $enqueued, $this->scheduler->count() );
		$this->assertSame( $pending, $this->changes->pending_count( $op_id ) );

		$this->assertSame( $thief, $this->lock->holder() );
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
