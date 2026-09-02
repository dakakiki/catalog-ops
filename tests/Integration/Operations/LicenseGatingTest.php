<?php
/**
 * Integration tests for plan gating in the operation pipeline.
 *
 * Proves the {@see License} is wired into {@see Operation_Service}: a free-tier
 * license blocks undo and formulas outright and caps an operation's object count,
 * while an unlimited license (the default in tests) does not (CONTEXT §5).
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Operations\Actions\Formula;
use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Conflict_Policy;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operation_Status;
use CatalogOps\Operations\Operations;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Engine;

/**
 * @covers \CatalogOps\Operations\Operation_Service
 * @covers \CatalogOps\Licensing\License
 */
final class LicenseGatingTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Lock $lock;
	private Query_Engine $engine;
	private Field_Providers $providers;
	private Recording_Scheduler $scheduler;

	/**
	 * Product ids created during a test, deleted in tear_down.
	 *
	 * @var int[]
	 */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->engine     = new Query_Engine( $wpdb );
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
		$this->providers  = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$this->lock       = new Lock( $this->operations );
		$this->scheduler  = new Recording_Scheduler();
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_free_plan_blocks_undo(): void {
		$this->expectException( License_Limited::class );

		// The gate fires before the parent lookup, so any id reaches it.
		$this->service_for( License::free() )->undo( 123, Conflict_Policy::SKIP, 1 );
	}

	public function test_free_plan_blocks_undo_preview(): void {
		$this->expectException( License_Limited::class );

		$this->service_for( License::free() )->preview_undo( 123, Conflict_Policy::SKIP );
	}

	public function test_free_plan_blocks_creating_a_formula_operation(): void {
		$this->expectException( License_Limited::class );

		$this->service_for( License::free() )->create(
			new Filter(),
			array( Formula::from_source( 'regular_price', 'regular_price * 0.9' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
	}

	public function test_paid_plan_allows_a_formula_operation(): void {
		$op_id = $this->service_for( License::unlimited() )->create(
			new Filter(),
			array( Formula::from_source( 'regular_price', 'regular_price * 0.9' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$this->assertGreaterThan( 0, $op_id );
	}

	public function test_free_plan_rejects_an_operation_over_the_object_cap(): void {
		$this->seed_products( License::FREE_MAX_OBJECTS + 1 );

		$service = $this->service_for( License::free() );

		$op_id = $service->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		try {
			$service->queue( $op_id );
			$this->fail( 'Expected License_Limited for an over-cap operation.' );
		} catch ( License_Limited $e ) {
			$this->assertStringContainsString( '200', $e->getMessage() );
		}

		// The cap fires before seeding, so nothing was staged and the write-lock is
		// released rather than leaked. The draft goes with it: an operation that
		// was refused never ran, and leaving its row behind put a permanent
		// "draft" line in the history for something the user was told was refused.
		$this->assertNull( $this->operations->find( $op_id ) );
		$this->assertSame( 0, array_sum( $this->changes->counts( $op_id ) ) );
		$this->assertFalse( (bool) get_option( 'catalogops_active_operation' ) );
	}

	public function test_free_plan_allows_an_operation_at_the_object_cap(): void {
		$this->seed_products( 3 );

		$service = $this->service_for( License::free() );

		$op_id = $service->create(
			new Filter(),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);

		$service->queue( $op_id );

		$this->assertSame( Operation_Status::QUEUED, $this->operations->find( $op_id )->status );
	}

	/**
	 * Build a service bound to a specific license, sharing the per-test doubles.
	 *
	 * @param License $license The plan to gate on.
	 */
	private function service_for( License $license ): Operation_Service {
		return new Operation_Service(
			$this->engine,
			$this->operations,
			$this->changes,
			$this->providers,
			$this->lock,
			$this->scheduler,
			$license
		);
	}

	/**
	 * Create $count minimal published products the query engine will resolve: a
	 * product post plus its wc_product_meta_lookup row (which the resolver joins).
	 * Cheaper than full WooCommerce saves when only the count matters. WooCommerce
	 * already seeds a lookup row on insert, so REPLACE guarantees one without a
	 * duplicate-key error whether or not that hook fired.
	 *
	 * @param int $count How many products to create.
	 */
	private function seed_products( int $count ): void {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';

		for ( $i = 0; $i < $count; $i++ ) {
			$id = wp_insert_post(
				array(
					'post_title'  => 'cap-' . $i,
					'post_type'   => 'product',
					'post_status' => 'publish',
				)
			);

			$this->created[] = (int) $id;
			$wpdb->replace( $lookup, array( 'product_id' => (int) $id ), array( '%d' ) );
			// A regular price so the product-scope query (which excludes unpriced,
			// variable-style parents) still resolves these seeded products.
			update_post_meta( (int) $id, '_regular_price', '9.99' );
		}
	}
}
