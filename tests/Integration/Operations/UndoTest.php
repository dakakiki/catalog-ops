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

	public function test_undo_of_undo_re_applies_the_operation(): void {
		$a = $this->make_product( 20 );

		$op_id = $this->run_price_change( '9.99' );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );
		$this->drive( $undo_id );
		$this->assertSame( '20', wc_get_product( $a )->get_regular_price() );

		// Undo the undo: reverts the undo's own deltas, re-applying 9.99.
		$redo_id = $this->service->undo( $undo_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $redo_id );
		$this->drive( $redo_id );

		$this->assertSame( '9.99', wc_get_product( $a )->get_regular_price() );
		$this->assertSame( Operation_Status::REVERTED, $this->operations->find( $undo_id )->status );
	}

	public function test_preview_undo_reports_total_and_flags_drift_in_the_sample(): void {
		$a = $this->make_product( 20 );
		$b = $this->make_product( 30 );

		$op_id = $this->run_price_change( '9.99' );
		$this->set_price( $b, '5.55' );

		$preview = $this->service->preview_undo( $op_id, Conflict_Policy::SKIP, 20 );

		$this->assertSame( 2, $preview['total'] );
		$this->assertSame( 'skip', $preview['conflict_policy'] );
		$this->assertCount( 2, $preview['sample'] );

		$by_id = array();
		foreach ( $preview['sample'] as $entry ) {
			$by_id[ $entry['id'] ] = $entry;
		}

		$this->assertFalse( $by_id[ $a ]['drift'] );
		$this->assertSame( 'revert', $by_id[ $a ]['action'] );
		$this->assertSame( '20', $by_id[ $a ]['restore_to'] );

		$this->assertTrue( $by_id[ $b ]['drift'] );
		$this->assertSame( 'skip', $by_id[ $b ]['action'] );
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
