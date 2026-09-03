<?php
/**
 * End-to-end integration test for M3 undo, drift detection, and conflict policy.
 *
 * Runs a real price-change operation to completion, then drives an undo through
 * the same pipeline — with a recording scheduler standing in for Action Scheduler
 * so the chain runs synchronously — and asserts values are restored, drifted
 * objects are correctly skipped, and the parent is marked reverted (CONTEXT §3).
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Conflict_Policy;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Blocked;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use InvalidArgumentException;
use WC_Product_Simple;

/**
 * @covers \CatalogOps\Operations\Operation_Service
 * @covers \CatalogOps\Operations\Chunk_Runner
 * @covers \CatalogOps\Operations\Revert_Plan
 * @covers \CatalogOps\Operations\Apply_Plan
 */
final class UndoTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
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
		$lock             = new Lock( $this->operations );
		$this->scheduler  = new Recording_Scheduler();

		$this->service = new Operation_Service(
			$engine,
			$this->operations,
			$this->changes,
			$providers,
			$lock,
			$this->scheduler
		);
		$this->runner = new Chunk_Runner( $this->operations, $this->changes, $providers, $this->scheduler, $lock );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_undo_restores_values_skips_drift_and_reverts_the_parent(): void {
		// Three products; all match the filter and get their price set to 9.99.
		$a = $this->make_product( 20 );
		$b = $this->make_product( 30 );
		$c = $this->make_product( 40 );

		$op_id = $this->run_price_change( '9.99' );

		$this->assertSame( '9.99', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( '9.99', wc_get_product( $b )->get_regular_price() );
		$this->assertSame( '9.99', wc_get_product( $c )->get_regular_price() );

		// Drift: product B is changed by hand after the operation. Undo must not
		// clobber it under the default skip policy.
		$this->set_price( $b, '5.55' );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->assertTrue( $this->operations->find( $undo_id )->is_undo() );

		$this->service->queue( $undo_id );
		// The undo froze one revert row per applied delta (three).
		$this->assertSame( 3, $this->operations->find( $undo_id )->target_count );

		$this->drive( $undo_id );

		// A and C revert to their originals; B keeps its drifted value.
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( '40', wc_get_product( $c )->get_regular_price() );
		$this->assertSame( '5.55', wc_get_product( $b )->get_regular_price() );

		// The undo completed; the parent is now reverted (CONTEXT §3).
		$undo = $this->operations->find( $undo_id );
		$this->assertSame( Operation_Status::COMPLETED, $undo->status );
		$this->assertSame( Operation_Status::REVERTED, $this->operations->find( $op_id )->status );

		// Two reverted, one skipped as drift.
		$counts = $this->changes->counts( $undo_id );
		$this->assertSame( 2, $counts['applied'] );
		$this->assertSame( 1, $counts['skipped'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	public function test_force_policy_reverts_even_drifted_objects(): void {
		$a = $this->make_product( 20 );
		$b = $this->make_product( 30 );

		$op_id = $this->run_price_change( '9.99' );
		$this->set_price( $b, '5.55' );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::FORCE, 1 );
		$this->service->queue( $undo_id );
		$this->drive( $undo_id );

		// Force writes the old value back regardless of drift.
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( '30', wc_get_product( $b )->get_regular_price() );
		$this->assertSame( 2, $this->changes->counts( $undo_id )['applied'] );
		$this->assertSame( 0, $this->changes->counts( $undo_id )['skipped'] );
	}

	public function test_numeric_formatting_difference_is_not_drift(): void {
		$a = $this->make_product( 20 );

		// The operation records new_value "9.99"; a re-save can normalize the
		// stored price. Simulate an equivalent-but-differently-formatted current
		// value and confirm undo does not treat it as drift.
		$op_id = $this->run_price_change( '9.99' );
		$this->set_price( $a, '9.990' );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );
		$this->drive( $undo_id );

		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( 1, $this->changes->counts( $undo_id )['applied'] );
		$this->assertSame( 0, $this->changes->counts( $undo_id )['skipped'] );
	}

	/**
	 * Undo is one-way and it ends there.
	 *
	 * This used to be a redo: undoing the undo reverted its own deltas and put the
	 * original change back. It is refused now, deliberately — undo that can be
	 * ridden in both directions is a toggle, not a safety net, and the round trip
	 * left the first operation reading `reverted` while its change was in force
	 * again, a status with no second move. Once a run has been given back, what is
	 * left is to look at what it did or delete it.
	 */
	public function test_an_undo_cannot_itself_be_undone(): void {
		$a = $this->make_product( 20 );

		$op_id = $this->run_price_change( '9.99' );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );
		$this->drive( $undo_id );
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );

		$before = $this->operations->count_all();

		try {
			$this->service->undo( $undo_id, Conflict_Policy::SKIP, 1 );
			$this->fail( 'Expected undoing an undo to be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'cannot itself be undone', $e->getMessage() );
		}

		// The price stays where the undo left it, and nothing was recorded.
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( $before, $this->operations->count_all() );
	}

	/**
	 * An operation that has already been given back cannot be given back again.
	 *
	 * Nothing stopped it before: the guard only asked whether the operation was
	 * still running. Undoing a reverted operation reads every object as drift —
	 * its current value is the one the undo restored, not the one the operation
	 * wrote — so the safe policy skips all of them, and what the user gets for a
	 * full pass over the target list, with the write lock held, is an empty
	 * operation in the history. The route to putting the change back is undoing
	 * the undo, which {@see test_undo_of_undo_re_applies_the_operation} covers.
	 */
	public function test_cannot_undo_an_operation_that_is_already_reverted(): void {
		$this->make_product( 20 );

		$op_id   = $this->run_price_change( '9.99' );
		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );

		$this->service->queue( $undo_id );
		$this->drive( $undo_id );

		$this->assertSame( Operation_Status::REVERTED, $this->operations->find( $op_id )->status );

		$before = $this->operations->count_all();

		try {
			$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
			$this->fail( 'Expected undoing an already-reverted operation to be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'already been undone', $e->getMessage() );
		}

		$after = $this->operations->count_all();

		// Refused before anything was recorded: no empty operation left behind.
		$this->assertSame( $before, $after );
	}

	public function test_preview_undo_reports_total_and_flags_drift_in_the_sample(): void {
		$a = $this->make_product( 20 );
		$b = $this->make_product( 30 );

		$op_id = $this->run_price_change( '9.99' );
		$this->set_price( $b, '5.55' );

		$preview = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 20 );

		$this->assertSame( 2, $preview['total'] );
		$this->assertSame( 2, $preview['matched'] );
		$this->assertSame( 'skip', $preview['conflict_policy'] );
		$this->assertCount( 2, $preview['items'] );

		$by_id = array();
		foreach ( $preview['items'] as $entry ) {
			$by_id[ $entry['id'] ] = $entry;
		}

		$this->assertFalse( $by_id[ $a ]['drift'] );
		$this->assertSame( 'revert', $by_id[ $a ]['action'] );
		$this->assertSame( '20', $by_id[ $a ]['restore_to'] );

		$this->assertTrue( $by_id[ $b ]['drift'] );
		$this->assertSame( 'skip', $by_id[ $b ]['action'] );
	}

	/**
	 * The preview pages and searches, and neither narrows the undo itself.
	 *
	 * It used to return a fixed sample of the first rows with no way to look
	 * further, so on a catalogue of any size the question an undo is actually
	 * agreed on — "will the one I care about be reverted or skipped?" — had no
	 * answer. `total` has to stay the whole job while `matched` follows the
	 * search, or narrowing the view would read as narrowing the undo.
	 */
	public function test_the_preview_pages_and_searches_without_narrowing_the_undo(): void {
		$this->make_product( 20, 'UNDO-AAA-1' );
		$this->make_product( 30, 'UNDO-AAA-2' );
		$this->make_product( 40, 'UNDO-BBB-1' );

		$op_id = $this->run_price_change( '9.99' );

		$first = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 2, 1 );

		$this->assertSame( 3, $first['total'] );
		$this->assertSame( 3, $first['matched'] );
		$this->assertCount( 2, $first['items'] );
		$this->assertSame( 1, $first['page'] );

		$second = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 2, 2 );

		$this->assertCount( 1, $second['items'] );
		$this->assertSame( 2, $second['page'] );

		// The two pages together are the whole list, exactly once.
		$paged = array_merge(
			array_column( $first['items'], 'id' ),
			array_column( $second['items'], 'id' )
		);
		$this->assertCount( 3, array_unique( $paged ) );

		// The SKU reaches the row, so the column is readable and the search means
		// something to the person typing it.
		$this->assertContains( 'UNDO-AAA-1', array_column( $first['items'], 'sku' ) );

		$found = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 10, 1, 'UNDO-AAA' );

		$this->assertSame( 2, $found['matched'] );
		$this->assertCount( 2, $found['items'] );
		// Unchanged by the search: the undo still covers all three.
		$this->assertSame( 3, $found['total'] );

		$none = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 10, 1, 'UNDO-ZZZ' );

		$this->assertSame( 0, $none['matched'] );
		$this->assertSame( array(), $none['items'] );
		$this->assertSame( 3, $none['total'] );
	}

	/**
	 * An undo of a two-field operation finishes at 100%, not at half.
	 *
	 * The two kinds of operation count in different units, each matching what its
	 * own preview promised: an edit targets *products* ("N products will change"),
	 * an undo targets the parent's applied *rows* ("N changes will be reverted").
	 * The runner counted objects for both, so an undo of an operation that touched
	 * two fields set a target of four, reported two, and stopped mid-bar on a run
	 * that had finished — on the screen someone is watching to decide whether it is
	 * safe to walk away.
	 *
	 * Unreachable from the admin app, which sends one action; reachable the moment
	 * a provider offers more, which is what M7 is.
	 */
	public function test_an_undo_of_a_two_field_operation_reaches_the_end(): void {
		$a = $this->make_product( 20 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array(
				new Set_Value( 'regular_price', '9.99' ),
				new Set_Value( 'meta:_undo_two', 'after' ),
			),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		// One product, two fields: the parent applied two rows.
		$this->assertSame( 2, $this->changes->counts( $op_id )['applied'] );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );

		$undo = $this->operations->find( $undo_id );
		$this->assertSame( 2, $undo->target_count, 'An undo targets the rows it reverts.' );

		$this->drive( $undo_id );

		$undo = $this->operations->find( $undo_id );

		$this->assertSame( Operation_Status::COMPLETED, $undo->status );
		$this->assertSame( 2, $undo->processed, 'The bar must reach its own target.' );
		$this->assertSame( 100, $undo->percent() );

		// And it actually reverted both fields, not just the one it counted.
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( '', (string) wc_get_product( $a )->get_meta( '_undo_two', true ) );
	}

	public function test_undo_with_nothing_applied_settles_immediately(): void {
		// A completed operation that matched nothing has no applied deltas.
		$this->make_product( 10 );
		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 100000 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );

		$undo = $this->operations->find( $undo_id );
		$this->assertSame( Operation_Status::COMPLETED, $undo->status );
		$this->assertSame( 0, $undo->target_count );
		$this->assertSame( 0, $this->scheduler->count() );
	}

	public function test_cannot_undo_a_running_operation(): void {
		$this->make_product( 20 );
		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		// Still queued/active — undo must refuse.

		$this->expectException( \InvalidArgumentException::class );
		$this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
	}

	/**
	 * Run a price Set_Value over every product above price 10 and drive it done.
	 *
	 * @param string $price New price.
	 * @return int Operation id.
	 */
	/**
	 * A run the watchdog failed part-way finishes the list it froze, not a new one.
	 *
	 * This is the hole resume fills. An operation stops for reasons unrelated to
	 * what it was asked to do — the host restarts, the queue's chain breaks — and
	 * the watchdog marks it `failed` after ten minutes so the write lock is not
	 * wedged. Before this the user's only moves were undoing the fraction that
	 * landed or running the whole filter again, and running it again resolves the
	 * filter against a catalogue that has since moved on. Resuming carries on down
	 * the frozen list, which is the set that was approved.
	 */
	public function test_a_failed_operation_resumes_the_targets_it_froze(): void {
		$a = $this->make_product( 20 );
		$b = $this->make_product( 30 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array( new Set_Value( 'regular_price', '7.77' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		// One object through, then the run dies and the watchdog fails it.
		$this->runner->run( $op_id, 1 );
		$this->operations->set_status( $op_id, Operation_Status::FAILED );

		$partial = $this->operations->find( $op_id );
		$this->assertSame( Operation_Status::FAILED, $partial->status );
		$this->assertSame( 1, $this->changes->pending_count( $op_id ) );

		// A product added after the freeze must NOT be swept in — that is exactly
		// the difference between resuming and running the filter again.
		$late = $this->make_product( 40 );

		$this->service->resume( $op_id );
		$this->drive( $op_id );

		$this->assertSame( 0, $this->changes->pending_count( $op_id ) );
		$this->assertSame( '7.77', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( '7.77', wc_get_product( $b )->get_regular_price() );
		$this->assertSame( '40', wc_get_product( $late )->get_regular_price() );
	}

	public function test_resuming_is_refused_while_another_operation_holds_the_lock(): void {
		$this->make_product( 20 );

		$stalled = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array( new Set_Value( 'regular_price', '7.77' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $stalled );
		$this->operations->set_status( $stalled, Operation_Status::FAILED );

		// Someone else starts writing in the meantime.
		$other = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array( new Set_Value( 'regular_price', '5.55' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $other );

		try {
			$this->service->resume( $stalled );
			$this->fail( 'Expected resuming to be refused while another operation writes.' );
		} catch ( Operation_Blocked $e ) {
			$this->assertStringContainsString( 'already writing', $e->getMessage() );
		}

		// Refused without disturbing either side: the stalled run keeps its state,
		// and the live one keeps the lock it holds.
		$this->assertSame( Operation_Status::FAILED, $this->operations->find( $stalled )->status );
		$this->assertTrue( $this->operations->find( $other )->status->is_active() );
	}

	public function test_resuming_an_operation_with_nothing_left_is_refused(): void {
		$this->make_product( 20 );

		$op_id = $this->run_price_change( '9.99' );

		$this->assertSame( 0, $this->changes->pending_count( $op_id ) );

		try {
			$this->service->resume( $op_id );
			$this->fail( 'Expected resuming a finished operation to be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'nothing left to do', $e->getMessage() );
		}
	}

	private function run_price_change( string $price ): int {
		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 10 ) ) ),
			array( new Set_Value( 'regular_price', $price ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		return $op_id;
	}

	/**
	 * Set a product's price directly (a "by hand" edit, outside the pipeline).
	 *
	 * @param int    $id    Product id.
	 * @param string $price New price.
	 */
	private function set_price( int $id, string $price ): void {
		$product = wc_get_product( $id );
		$product->set_regular_price( $price );
		$product->save();
	}

	/**
	 * Drive an operation to completion, one small chunk at a time.
	 *
	 * @param int $op_id Operation id.
	 */
	private function drive( int $op_id ): void {
		$safety = 0;

		while ( $this->operations->find( $op_id )->status->is_active() && $safety++ < 200 ) {
			$this->runner->run( $op_id, 2 );
		}
	}

	/**
	 * Create a simple, in-stock product at a given price.
	 *
	 * @param float $price Regular price.
	 * @return int Product id.
	 */
	private function make_product( float $price, string $sku = '' ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}
}
