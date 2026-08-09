<?php
/**
 * Integration tests for the field providers against real WooCommerce products.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Fields\Core_Fields;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Operations\Fields\Field_Type;
use CatalogOps\Operations\Fields\Meta_Fields;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Operations\Fields\Core_Fields
 * @covers \CatalogOps\Operations\Fields\Meta_Fields
 * @covers \CatalogOps\Operations\Fields\Field_Providers
 */
final class FieldProvidersTest extends WP_UnitTestCase {

	private Field_Providers $providers;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		$this->providers = new Field_Providers( new Core_Fields(), new Meta_Fields() );
	}

	public function test_registry_routes_keys_to_the_owning_provider(): void {
		$this->assertInstanceOf( Core_Fields::class, $this->providers->for( 'regular_price' ) );
		$this->assertInstanceOf( Meta_Fields::class, $this->providers->for( 'meta:_catalogops_brand' ) );
		$this->assertNull( $this->providers->for( 'nonexistent_field' ) );
		$this->assertNull( $this->providers->for( 'meta:' ) );
	}

	public function test_core_provider_reads_and_stages_price(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '19.90' );
		$id = $product->save();

		$loaded   = wc_get_product( $id );
		$provider = $this->providers->for( 'regular_price' );

		$this->assertSame( '19.90', $provider->read( $loaded, 'regular_price' ) );
		$this->assertSame( Field_Type::POST_FIELD, $provider->field_type( 'regular_price' ) );
		$this->assertSame( 'regular_price', $provider->storage_key( 'regular_price' ) );

		$provider->stage( $loaded, 'regular_price', '9.99' );
		$loaded->save();

		$this->assertSame( '9.99', wc_get_product( $id )->get_regular_price() );
	}

	public function test_meta_provider_reads_and_stages_meta(): void {
		$product = new WC_Product_Simple();
		$product->update_meta_data( '_catalogops_brand', 'Acme' );
		$id = $product->save();

		$loaded   = wc_get_product( $id );
		$provider = $this->providers->for( 'meta:_catalogops_brand' );

		$this->assertSame( 'Acme', $provider->read( $loaded, 'meta:_catalogops_brand' ) );
		$this->assertSame( Field_Type::META, $provider->field_type( 'meta:_catalogops_brand' ) );
		$this->assertSame( '_catalogops_brand', $provider->storage_key( 'meta:_catalogops_brand' ) );

		$provider->stage( $loaded, 'meta:_catalogops_brand', 'Globex' );
		$loaded->save();

		$this->assertSame( 'Globex', wc_get_product( $id )->get_meta( '_catalogops_brand', true ) );
	}

	public function test_for_storage_routes_a_delta_back_to_its_provider(): void {
		$core = $this->providers->for_storage( Field_Type::POST_FIELD, 'regular_price' );
		$this->assertNotNull( $core );
		$this->assertInstanceOf( Core_Fields::class, $core['provider'] );
		$this->assertSame( 'regular_price', $core['key'] );

		$meta = $this->providers->for_storage( Field_Type::META, '_catalogops_brand' );
		$this->assertNotNull( $meta );
		$this->assertInstanceOf( Meta_Fields::class, $meta['provider'] );
		$this->assertSame( 'meta:_catalogops_brand', $meta['key'] );
	}

	public function test_for_storage_returns_null_for_unowned_storage(): void {
		// A post-field key the core provider does not know.
		$this->assertNull( $this->providers->for_storage( Field_Type::POST_FIELD, 'not_a_field' ) );
		// An empty meta key.
		$this->assertNull( $this->providers->for_storage( Field_Type::META, '' ) );
	}

	public function test_ui_key_round_trips_through_storage_key(): void {
		$resolved = $this->providers->for_storage( Field_Type::META, '_cost' );
		$this->assertNotNull( $resolved );

		// The reverse-resolved key normalizes back to the same storage key.
		$this->assertSame( '_cost', $resolved['provider']->storage_key( $resolved['key'] ) );
	}

	public function test_all_fields_lists_core_fields(): void {
		$keys = array_column( $this->providers->all_fields(), 'key' );

		$this->assertContains( 'regular_price', $keys );
		$this->assertContains( 'stock_status', $keys );
	}
}
