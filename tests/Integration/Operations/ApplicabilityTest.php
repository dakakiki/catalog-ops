<?php
/**
 * Integration test for the applicability rules: what the preview promises, and
 * what the frozen target list actually contains.
 *
 * The point of these rules is that the two agree. Each test therefore checks the
 * predicted number and the queued number together — a preview nobody can trust is
 * worse than no preview at all.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Formula;
use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Lock;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Service;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Skip_Reason;
use CatalogOps\Operations\Write_Rules;
use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Meta_Fields;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Scope;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * @covers \CatalogOps\Operations\Write_Rules
 * @covers \CatalogOps\Query\Requirements\Sale_Price_Sticks
 * @covers \CatalogOps\Query\Requirements\Sale_Price_Cleared
 * @covers \CatalogOps\Query\Requirements\Stock_Status_Sticks
 * @covers \CatalogOps\Operations\Operation_Service
 */
final class ApplicabilityTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Operation_Service $service;

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
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );

		$this->service = new Operation_Service(
			new \CatalogOps\Query\Query_Engine( $wpdb ),
			$this->operations,
			$this->changes,
			new Field_Providers( new Core_Fields(), new Meta_Fields() ),
			new Lock( $this->operations ),
			new Recording_Scheduler(),
			null,
			new Write_Rules()
		);
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();

		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_sale_price_is_applicable_only_where_the_regular_price_leaves_room(): void {
		$this->make_product( 500 );
		$this->make_product( 100 );

		$filter  = $this->all_products();
		$actions = array( new Set_Value( 'sale_price', '150' ) );

		$preview = $this->service->preview( $filter, $actions );

		$this->assertSame( 2, $preview['matched'] );
		$this->assertSame( 1, $preview['applicable'] );
		$this->assertSame( 1, $preview['omitted'] );
		$this->assertSame(
			array( array( 'reason' => Skip_Reason::SALE_NOT_BELOW_REGULAR->value, 'count' => 1 ) ),
			$preview['omitted_by']
		);

		$this->assertSame( $preview['applicable'], $this->freeze( $filter, $actions ) );
	}

	public function test_clearing_a_sale_price_is_always_applicable(): void {
		// An empty sale price is not a value WooCommerce can refuse — it is the
		// value it falls back to — so "remove the sale" must never be narrowed.
		$this->make_product( 500 );
		$this->make_product( 100 );

		$preview = $this->service->preview( $this->all_products(), array( new Set_Value( 'sale_price', '' ) ) );

		$this->assertSame( 2, $preview['matched'] );
		$this->assertSame( 2, $preview['applicable'] );
		$this->assertSame( array(), $preview['omitted_by'] );
	}

	public function test_a_lower_regular_price_warns_about_the_sale_prices_it_will_clear(): void {
		// The regular-price edit applies to both products; on one of them it also
		// deletes a sale price, leaving no change row of its own for undo. That is a
		// warning, not an omission — the count must not come off the target list.
		$this->make_product( 500, '400' );
		$this->make_product( 500, '200' );

		$filter  = $this->all_products();
		$actions = array( new Set_Value( 'regular_price', '300' ) );

		$preview = $this->service->preview( $filter, $actions );

		$this->assertSame( 2, $preview['matched'] );
		$this->assertSame( 2, $preview['applicable'] );
		$this->assertSame( array(), $preview['omitted_by'] );
		$this->assertSame(
			array( array( 'code' => Write_Rules::SALE_PRICE_CLEARED, 'count' => 1 ) ),
			$preview['warnings']
		);

		$this->assertSame( 2, $this->freeze( $filter, $actions ) );
	}

	public function test_each_omitted_object_is_attributed_to_the_first_rule_it_fails(): void {
		// Four products across every combination of the two rules in play. The
		// counts must partition the omitted total exactly — an object failing both
		// rules is reported once, under the first, or the breakdown would add up to
		// more than the number of products omitted.
		$this->make_product( 500, '400', 5 );   // Passes both.
		$this->make_product( 500, '', 5 );      // No sale price to read.
		$this->make_product( 500, '400', 0 );   // Managed stock at zero.
		$this->make_product( 500, '', 0 );      // Fails both.

		$filter  = $this->all_products();
		$actions = array(
			Formula::from_source( 'sale_price', 'sale_price * 0.9' ),
			new Set_Value( 'stock_status', 'instock' ),
		);

		$preview = $this->service->preview( $filter, $actions );

		$this->assertSame( 4, $preview['matched'] );
		$this->assertSame( 1, $preview['applicable'] );
		$this->assertSame( 3, $preview['omitted'] );
		$this->assertSame(
			array(
				array( 'reason' => Skip_Reason::EMPTY_INPUT->value, 'count' => 2 ),
				array( 'reason' => Skip_Reason::STOCK_MANAGED->value, 'count' => 1 ),
			),
			$preview['omitted_by']
		);

		$this->assertSame(
			$preview['omitted'],
			array_sum( array_column( $preview['omitted_by'], 'count' ) )
		);

		$this->assertSame( 1, $this->freeze( $filter, $actions ) );
	}

	public function test_stock_status_is_applicable_where_stock_is_not_managed(): void {
		// Without manage_stock, validate_props() leaves the status alone, so the
		// write lands however low the quantity is.
		$this->make_unmanaged_product( 500 );

		$preview = $this->service->preview(
			$this->all_products(),
			array( new Set_Value( 'stock_status', 'outofstock' ) )
		);

		$this->assertSame( 1, $preview['matched'] );
		$this->assertSame( 1, $preview['applicable'] );
	}

	public function test_a_variation_inheriting_its_parents_stock_management_is_omitted(): void {
		// The variation's own manage_stock is off, so a naive check would call the
		// edit applicable — but WooCommerce reads the parent's zero quantity and
		// forces `outofstock` anyway.
		$this->make_variation_under_managed_parent( 0 );

		$filter = new Filter( array(), Filter::RELATION_AND, Query_Scope::VARIATION );

		$preview = $this->service->preview( $filter, array( new Set_Value( 'stock_status', 'instock' ) ) );

		$this->assertSame( 1, $preview['matched'] );
		$this->assertSame( 0, $preview['applicable'] );
		$this->assertSame(
			array( array( 'reason' => Skip_Reason::STOCK_MANAGED->value, 'count' => 1 ) ),
			$preview['omitted_by']
		);
	}

	public function test_a_variation_under_a_stocked_parent_is_applicable(): void {
		// Same inheritance, quantity above the threshold: the derived status is the
		// one being set, so the edit is honestly applicable.
		$this->make_variation_under_managed_parent( 9 );

		$filter = new Filter( array(), Filter::RELATION_AND, Query_Scope::VARIATION );

		$preview = $this->service->preview( $filter, array( new Set_Value( 'stock_status', 'instock' ) ) );

		$this->assertSame( 1, $preview['matched'] );
		$this->assertSame( 1, $preview['applicable'] );
	}

	/**
	 * A filter over every published product in the Products scope. The scope itself
	 * already requires a regular price, which the fixtures all carry.
	 */
	private function all_products(): Filter {
		return new Filter();
	}

	/**
	 * Queue an operation and return the number of objects it froze.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 */
	private function freeze( Filter $filter, array $actions ): int {
		$op_id = $this->service->create( $filter, $actions, Operation_Mode::SAFE, Operation_Source::UI, 1 );
		$this->service->queue( $op_id );

		return $this->operations->find( $op_id )->target_count;
	}

	/**
	 * A simple product with a regular price, an optional sale price, and managed
	 * stock at a given quantity.
	 *
	 * @param float  $regular  Regular price.
	 * @param string $sale     Sale price, or '' for none.
	 * @param int    $quantity Managed stock quantity.
	 * @return int Product id.
	 */
	private function make_product( float $regular, string $sale = '', int $quantity = 5 ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $regular );
		$product->set_sale_price( $sale );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $quantity );
		$product->set_backorders( 'no' );
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}

	/**
	 * A simple product that does not manage stock at all.
	 *
	 * @param float $regular Regular price.
	 * @return int Product id.
	 */
	private function make_unmanaged_product( float $regular ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $regular );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$id = $product->save();

		$this->created[] = $id;

		return $id;
	}

	/**
	 * A variation whose own manage_stock is off, under a parent that manages stock
	 * at the given quantity — the inheritance case.
	 *
	 * @param int $parent_quantity The parent's managed stock quantity.
	 * @return int Variation id.
	 */
	private function make_variation_under_managed_parent( int $parent_quantity ): int {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Applicability parent' );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( $parent_quantity );
		$parent->set_backorders( 'no' );
		$parent_id       = $parent->save();
		$this->created[] = $parent_id;

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_regular_price( '500' );
		$variation->set_manage_stock( false );
		$variation->set_stock_status( 'outofstock' );
		$variation_id    = $variation->save();
		$this->created[] = $variation_id;

		return $variation_id;
	}
}
