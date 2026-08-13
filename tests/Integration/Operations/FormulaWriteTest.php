<?php
/**
 * End-to-end integration test for M5 formula actions through the write engine.
 *
 * Drives the full pipeline — create → queue (freeze + seed) → run chunks —
 * with a {@see Formula} action against real WooCommerce products, proving the
 * CONTEXT §4 definition of done: `roundto(cost * 1.35, 0.99)` computes and
 * writes, and a product whose input field is empty is skipped and logged, never
 * set to zero (CONTEXT §3).
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Formula;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
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
 * @covers \CatalogOps\Operations\Actions\Formula
 * @covers \CatalogOps\Operations\Apply_Plan
 * @covers \CatalogOps\Operations\Formula\Parser
 * @covers \CatalogOps\Operations\Formula\Expression
 */
final class FormulaWriteTest extends Operations_Database_Case {

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

	public function test_roundto_formula_computes_and_writes_price_from_cost(): void {
		// 10 * 1.35 = 13.5; round(13.5 / 0.99) = 14; 14 * 0.99 = 13.86.
		$ten = $this->make_product( 100, '10' );
		// 20 * 1.35 = 27; round(27 / 0.99) = 27; 27 * 0.99 = 26.73.
		$twenty = $this->make_product( 200, '20' );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( Formula::from_source( 'regular_price', 'roundto( cost * 1.35, 0.99 )' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$operation = $this->operations->find( $op_id );
		$this->assertSame( Operation_Status::COMPLETED, $operation->status );
		$this->assertSame( 2, $operation->processed );

		$this->assertSame( '13.86', wc_get_product( $ten )->get_regular_price() );
		$this->assertSame( '26.73', wc_get_product( $twenty )->get_regular_price() );

		// The delta was recorded from the real old price to the computed new one.
		$row = $this->applied_row( $op_id, $ten );
		$this->assertSame( '100', $row->old_value );
		$this->assertSame( '13.86', $row->new_value );
	}

	public function test_a_product_missing_a_read_field_is_excluded_not_targeted(): void {
		$priced   = $this->make_product( 100, '10' );  // cost present -> updated.
		$costless = $this->make_product( 300, null );  // cost empty   -> excluded.

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( Formula::from_source( 'regular_price', 'roundto( cost * 1.35, 0.99 )' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		// The one with a cost was rewritten; the one without is left out of the
		// operation entirely. The formula reads `cost`, so the costless product
		// cannot produce a value — it is never targeted (CONTEXT §3: an empty input
		// is never coerced to zero) rather than targeted and then skipped, so the
		// operation's counted target equals what it applies.
		$this->assertSame( '13.86', wc_get_product( $priced )->get_regular_price() );
		$this->assertSame( '300', wc_get_product( $costless )->get_regular_price() );

		$counts = $this->changes->counts( $op_id );
		$this->assertSame( 1, $counts['applied'] );
		$this->assertSame( 0, $counts['skipped'] );
		$this->assertSame( 1, $this->operations->find( $op_id )->target_count );
	}

	public function test_preview_counts_only_applicable_products_for_a_formula(): void {
		$this->make_product( 100, '10' );   // cost present -> applicable.
		$this->make_product( 200, '20' );   // cost present -> applicable.
		$this->make_product( 300, null );   // cost empty   -> omitted.

		$preview = $this->service->preview(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( Formula::from_source( 'regular_price', 'roundto( cost * 1.35, 0.99 )' ) )
		);

		// All three match the filter, but the formula reads `cost`, so only the two
		// products that carry a cost are applicable; the third is omitted, not
		// counted as a target it would silently skip.
		$this->assertSame( 3, $preview['matched'] );
		$this->assertSame( 2, $preview['applicable'] );
		$this->assertSame( 1, $preview['omitted'] );
	}

	public function test_percentage_price_cut_formula_applies(): void {
		// The CONTEXT §3 scenario: shave 10% off a filtered set via a formula.
		$product = $this->make_product( 50, '10' );

		$op_id = $this->service->create(
			new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 0 ) ) ),
			array( Formula::from_source( 'regular_price', 'regular_price * 0.9' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$this->assertSame( '45', wc_get_product( $product )->get_regular_price() );
	}

	/**
	 * The single applied change row for an object.
	 *
	 * @param int $op_id     Operation id.
	 * @param int $object_id Object id.
	 */
	private function applied_row( int $op_id, int $object_id ): object {
		return $this->change_row( $op_id, $object_id, 1 );
	}

	/**
	 * The single skipped change row for an object.
	 *
	 * @param int $op_id     Operation id.
	 * @param int $object_id Object id.
	 */
	private function skipped_row( int $op_id, int $object_id ): object {
		return $this->change_row( $op_id, $object_id, 3 );
	}

	/**
	 * Fetch one change row by operation, object, and status directly.
	 *
	 * @param int $op_id     Operation id.
	 * @param int $object_id Object id.
	 * @param int $status    Change status code (1 applied, 3 skipped).
	 */
	private function change_row( int $op_id, int $object_id, int $status ): object {
		global $wpdb;
		$table = $this->schema->changes_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE operation_id = %d AND object_id = %d AND status = %d",
				$op_id,
				$object_id,
				$status
			)
		);
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
	 * Create a simple, in-stock product at a given price, optionally with a cost.
	 *
	 * @param float       $price Regular price.
	 * @param string|null $cost  Cost meta value, or null for none.
	 * @return int Product id.
	 */
	private function make_product( float $price, ?string $cost ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		if ( null !== $cost ) {
			$product->update_meta_data( '_catalogops_cost', $cost );
		}

		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}
}
