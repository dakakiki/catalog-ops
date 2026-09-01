<?php
/**
 * End-to-end integration test for the M2 write engine.
 *
 * Drives the full pipeline — create → queue (freeze + seed) → run chunks —
 * against real WooCommerce products, with a recording scheduler double standing
 * in for Action Scheduler so the chain runs synchronously.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Change_Status;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Blocked;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Scheduler;
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
 * @covers \CatalogOps\Operations\Lock
 */
final class WriteEngineTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Operation_Service $service;
	private Chunk_Runner $runner;
	private Recording_Scheduler $scheduler;

	/**
	 * Product ids created during a test, deleted in tear_down (the schema DDL in
	 * set_up commits the per-test transaction, so posts do not roll back).
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
		$this->runner  = new Chunk_Runner( $this->operations, $this->changes, $providers, $this->scheduler, $lock );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_price_change_runs_end_to_end_and_refreshes_the_lookup(): void {
		$cheap    = $this->make_product( 10 );
		$mid      = $this->make_product( 25 );
		$dear     = $this->make_product( 30 );
		$cheapest = $this->make_product( 5 );
		$premium  = $this->make_product( 100 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->service->queue( $op_id );

		// Three products match (25, 30, 100); freezing seeds three change rows.
		$this->assertSame( 3, $this->operations->find( $op_id )->target_count );
		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $op_id )->status );

		$this->drive( $op_id );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( Operation_Status::COMPLETED, $operation->status );
		$this->assertSame( 3, $operation->processed );
		$this->assertSame( 0, $operation->failed );
		$this->assertNotNull( $operation->completed_at );

		// Matching products were rewritten; non-matching were left alone.
		$this->assertSame( '9.99', wc_get_product( $mid )->get_regular_price() );
		$this->assertSame( '9.99', wc_get_product( $dear )->get_regular_price() );
		$this->assertSame( '9.99', wc_get_product( $premium )->get_regular_price() );
		$this->assertSame( '10', wc_get_product( $cheap )->get_regular_price() );
		$this->assertSame( '5', wc_get_product( $cheapest )->get_regular_price() );

		// The WooCommerce lookup table was refreshed by the safe-mode save.
		global $wpdb;
		$lookup_price = $wpdb->get_var(
			$wpdb->prepare( "SELECT min_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id = %d", $mid )
		);
		// The lookup column is DECIMAL(_, 4), so it reads back as '9.9900'.
		$this->assertSame( 9.99, (float) $lookup_price );

		// Every change is applied and carries the delta.
		$counts = $this->changes->counts( $op_id );
		$this->assertSame( 3, $counts['applied'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	public function test_rerunning_a_completed_operation_is_a_no_op(): void {
		$product = $this->make_product( 50 );

		$op_id = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '1.00' );
		$this->drive( $op_id );

		$this->assertSame( '1.00', wc_get_product( $product )->get_regular_price() );

		// A stray extra chunk (e.g. a duplicate Action Scheduler run) changes
		// nothing: no pending rows remain to claim.
		$this->runner->run( $op_id, 100 );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( 1, $operation->processed );
		$this->assertSame( '1.00', wc_get_product( $product )->get_regular_price() );
	}

	public function test_multiple_chunks_grow_the_batch_when_fast(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->make_product( 50 );
		}

		$op_id = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '2.00' );

		// Force small chunks so the run spans several batches.
		$this->runner->run( $op_id, 2 );

		// A fast chunk grows the next batch size (2 → 3).
		$this->assertSame( 3, $this->operations->find( $op_id )->batch_size );
		$this->assertGreaterThan( 0, $this->scheduler->count() );

		$this->drive( $op_id );
		$this->assertSame( 6, $this->operations->find( $op_id )->processed );
	}

	public function test_second_operation_is_blocked_while_one_is_active(): void {
		$this->make_product( 50 );

		$first = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '3.00' );
		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $first )->status );

		$second = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) ),
			array( new Set_Value( 'regular_price', '4.00' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->expectException( Operation_Blocked::class );
		$this->service->queue( $second );
	}

	public function test_lock_frees_after_completion_so_the_next_can_run(): void {
		$product = $this->make_product( 50 );

		// The first op sets a value that still matches the filter, so the second
		// op has a target and we are genuinely testing the freed lock (not an
		// empty match).
		$first = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '30.00' );
		$this->drive( $first );

		// With the first finished, a second queues and runs without conflict.
		$second = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '6.00' );
		$this->drive( $second );

		$this->assertSame( Operation_Status::COMPLETED, $this->operations->find( $second )->status );
		$this->assertSame( '6.00', wc_get_product( $product )->get_regular_price() );
	}

	public function test_empty_match_completes_immediately(): void {
		$this->make_product( 10 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 100000 ) ) ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->service->queue( $op_id );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( Operation_Status::COMPLETED, $operation->status );
		$this->assertSame( 0, $operation->target_count );
		// No chunk was ever scheduled.
		$this->assertSame( 0, $this->scheduler->count() );
	}

	public function test_unsupported_field_is_rejected_at_create(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->service->create(
			new Filter(),
			array( new Set_Value( 'not_a_real_field', 'x' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
	}

	public function test_meta_change_applies_and_records_delta(): void {
		$product = $this->make_product( 50 );
		$p       = wc_get_product( $product );
		$p->update_meta_data( '_catalogops_brand', 'Acme' );
		$p->save();

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) ),
			array( new Set_Value( 'meta:_catalogops_brand', 'Globex' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$this->assertSame( 'Globex', wc_get_product( $product )->get_meta( '_catalogops_brand', true ) );

		$chunk = $this->changes->pending_chunk( $op_id, 10 );
		$this->assertSame( array(), $chunk );
		$this->assertSame( 1, $this->changes->counts( $op_id )['applied'] );
	}

	public function test_stock_status_override_by_woocommerce_is_recorded_as_skipped(): void {
		// A stock-managed product at zero quantity: WooCommerce forces `outofstock`
		// on save, so a bulk "set in stock" cannot actually take effect.
		$product = $this->make_out_of_stock_product( 50 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) ),
			array( new Set_Value( 'stock_status', 'instock' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		// WooCommerce kept it out of stock, so the runner reads the value back and
		// records a skip, not a false apply — the object was processed but nothing
		// changed.
		$this->assertSame( 'outofstock', wc_get_product( $product )->get_stock_status() );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( Operation_Status::COMPLETED, $operation->status );
		$this->assertSame( 1, $operation->processed );

		$counts = $this->changes->counts( $op_id );
		$this->assertSame( 0, $counts['applied'] );
		$this->assertSame( 1, $counts['skipped'] );
	}

	public function test_sale_price_rejected_by_woocommerce_is_recorded_as_skipped(): void {
		// A sale price at or above the regular price is rejected by WooCommerce,
		// which drops it — so setting one on a product with no prior sale changes
		// nothing, and must not be reported as applied.
		$product = $this->make_product( 100 );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) ),
			array( new Set_Value( 'sale_price', '150' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$this->assertSame( '', wc_get_product( $product )->get_sale_price() );

		$counts = $this->changes->counts( $op_id );
		$this->assertSame( 0, $counts['applied'] );
		$this->assertSame( 1, $counts['skipped'] );
	}

	public function test_price_change_records_the_value_woocommerce_persisted(): void {
		// The normal path still records an apply — the read-back value equals the
		// intended one — so honest recording does not regress a real change.
		$product = $this->make_product( 50 );

		$op_id = $this->queue_price_change( 'price', Operator::GREATER_THAN, 20, '9.99' );
		$this->drive( $op_id );

		$this->assertSame( '9.99', wc_get_product( $product )->get_regular_price() );

		$counts = $this->changes->counts( $op_id );
		$this->assertSame( 1, $counts['applied'] );
		$this->assertSame( 0, $counts['skipped'] );
	}

	/**
	 * Queue a price Set_Value operation and return its id.
	 *
	 * @param string   $field    Filter field.
	 * @param Operator $operator Filter operator.
	 * @param mixed    $value    Filter value.
	 * @param string   $price    New price to set.
	 */
	private function queue_price_change( string $field, Operator $operator, mixed $value, string $price ): int {
		$op_id = $this->service->create(
			new Filter( array( new Condition( $field, $operator, $value ) ) ),
			array( new Set_Value( 'regular_price', $price ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->service->queue( $op_id );

		return $op_id;
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

	/**
	 * Create a stock-managed product at zero quantity, which WooCommerce keeps
	 * `outofstock` regardless of an attempted status change.
	 *
	 * @param float $price Regular price (so the product is in the Products scope).
	 * @return int Product id.
	 */
	private function make_out_of_stock_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->set_stock_status( 'outofstock' );
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}
}
