<?php
/**
 * End-to-end test: editing and undoing variations through the pipeline (M4).
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Chunk_Runner;
use CatalogOps\Operations\Conflict_Policy;
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
use CatalogOps\Query\Query_Scope;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * @covers \CatalogOps\Operations\Operation_Service
 * @covers \CatalogOps\Operations\Chunk_Runner
 */
final class VariationWriteTest extends Operations_Database_Case {

	private Operations $operations;
	private Changes $changes;
	private Operation_Service $service;
	private Chunk_Runner $runner;
	private Recording_Scheduler $scheduler;

	private string $size_tax;
	private int $small;
	private int $medium;
	private int $large;

	/**
	 * Post ids created during a test, deleted in tear_down.
	 *
	 * @var int[]
	 */
	private array $created = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		if ( taxonomy_exists( 'pa_size' ) ) {
			unregister_taxonomy( 'pa_size' );
		}
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );

		global $wpdb;
		$engine           = new Query_Engine( $wpdb );
		$this->operations = new Operations( $wpdb, $this->schema );
		$this->changes    = new Changes( $wpdb, $this->schema );
		$providers        = new Field_Providers( new Core_Fields(), new Meta_Fields() );
		$lock             = new Lock( $this->operations );
		$this->scheduler  = new Recording_Scheduler();

		$this->service = new Operation_Service( $engine, $this->operations, $this->changes, $providers, $lock, $this->scheduler );
		$this->runner  = new Chunk_Runner( $this->operations, $this->changes, $providers, $this->scheduler, $lock );

		$this->size_tax = $this->ensure_size_attribute();
		$this->small    = $this->ensure_term( 'Small', $this->size_tax );
		$this->medium   = $this->ensure_term( 'Medium', $this->size_tax );
		$this->large    = $this->ensure_term( 'Large', $this->size_tax );
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}
		$this->created = array();
		delete_option( 'catalogops_active_operation' );

		parent::tear_down();
	}

	public function test_edit_variations_only_leaving_the_parent_untouched(): void {
		list( $parent, $variations ) = $this->make_variable_product( array( 'Small' => 10, 'Medium' => 50, 'Large' => 90 ) );

		$op_id = $this->service->create(
			new Filter(
				array( new Condition( 'price', Operator::GREATER_THAN, 40 ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			),
			array( new Set_Value( 'regular_price', '7.77' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );

		// Two variations match (Medium 50, Large 90); the parent (a variable
		// product with no own price) does not.
		$this->assertSame( 2, $this->operations->find( $op_id )->target_count );

		$this->drive( $op_id );

		$this->assertSame( Operation_Status::COMPLETED, $this->operations->find( $op_id )->status );
		$this->assertSame( '7.77', wc_get_product( $variations['Medium'] )->get_regular_price() );
		$this->assertSame( '7.77', wc_get_product( $variations['Large'] )->get_regular_price() );
		// The Small variation was below the threshold and left alone.
		$this->assertSame( '10', wc_get_product( $variations['Small'] )->get_regular_price() );

		// The deltas are recorded against the variation object type.
		global $wpdb;
		$types = $wpdb->get_col(
			$wpdb->prepare( "SELECT DISTINCT object_type FROM {$this->schema->changes_table()} WHERE operation_id = %d", $op_id )
		);
		$this->assertSame( array( 'variation' ), $types );
	}

	public function test_undo_of_a_variation_operation_restores_variation_prices(): void {
		list( , $variations ) = $this->make_variable_product( array( 'Small' => 10, 'Medium' => 50 ) );

		$op_id = $this->service->create(
			new Filter(
				array( new Condition( 'price', Operator::GREATER_THAN, 5 ) ),
				Filter::RELATION_AND,
				Query_Scope::VARIATION
			),
			array( new Set_Value( 'regular_price', '1.00' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
		$this->service->queue( $op_id );
		$this->drive( $op_id );

		$this->assertSame( '1.00', wc_get_product( $variations['Small'] )->get_regular_price() );
		$this->assertSame( '1.00', wc_get_product( $variations['Medium'] )->get_regular_price() );

		$undo_id = $this->service->undo( $op_id, Conflict_Policy::SKIP, 1 );
		$this->service->queue( $undo_id );
		$this->drive( $undo_id );

		$this->assertSame( '10', wc_get_product( $variations['Small'] )->get_regular_price() );
		$this->assertSame( '50', wc_get_product( $variations['Medium'] )->get_regular_price() );
		$this->assertSame( Operation_Status::REVERTED, $this->operations->find( $op_id )->status );
	}

	/**
	 * Create a variable product with one variation per named size.
	 *
	 * @param array<string, float> $sizes size name => price.
	 * @return array{0: int, 1: array<string, int>}
	 */
	private function make_variable_product( array $sizes ): array {
		$term_id_for = array(
			'Small'  => $this->small,
			'Medium' => $this->medium,
			'Large'  => $this->large,
		);

		$parent = new WC_Product_Variable();
		$parent->set_name( 'VW Variable' );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$attribute->set_name( $this->size_tax );
		$attribute->set_options( array_values( array_intersect_key( $term_id_for, $sizes ) ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent_id       = $parent->save();
		$this->created[] = $parent_id;

		$variations = array();
		foreach ( $sizes as $name => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $this->size_tax => get_term( $term_id_for[ $name ] )->slug ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 7 );
			$variation->set_stock_status( 'instock' );
			$vid                 = $variation->save();
			$variations[ $name ] = $vid;
			$this->created[]     = $vid;
		}

		return array( $parent_id, $variations );
	}

	private function ensure_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing ) {
			return (int) $existing->term_id;
		}
		$created = wp_insert_term( $name, $taxonomy );

		return (int) $created['term_id'];
	}

	private function ensure_size_attribute(): string {
		$taxonomy = wc_attribute_taxonomy_name( 'size' );

		if ( ! wc_attribute_taxonomy_id_by_name( 'size' ) ) {
			wc_create_attribute( array( 'name' => 'Size', 'slug' => 'size', 'type' => 'select' ) );
		}
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, array( 'product' ), array( 'hierarchical' => false, 'query_var' => true ) );
		}

		return $taxonomy;
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
}
