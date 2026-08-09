<?php
/**
 * Integration tests for the fields discovery endpoint.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use WC_Product_Simple;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Fields_Controller
 */
final class FieldsControllerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) ) {
			$this->markTestSkipped( 'WooCommerce is not available in the test environment.' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_meta_keys_lists_custom_fields_and_hides_internal_ones(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->update_meta_data( '_catalogops_brand', 'Acme' );
		$product->update_meta_data( '_zzz_custom_note', 'hello' );
		$id = $product->save();
		// An internal bookkeeping key that must be filtered out.
		update_post_meta( $id, '_edit_lock', '123:1' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/fields/meta-keys' ) );
		$this->assertSame( 200, $response->get_status() );

		$keys = $response->get_data()['keys'];
		$this->assertContains( '_catalogops_brand', $keys );
		$this->assertContains( '_zzz_custom_note', $keys );

		// Core field-backed and WP/WC internal keys are excluded.
		$this->assertNotContains( '_edit_lock', $keys );
		$this->assertNotContains( '_price', $keys );
		$this->assertNotContains( '_sku', $keys );
	}

	public function test_variation_attribute_keys_are_hidden(): void {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$id = $product->save();
		// Variation attribute plumbing must never appear as a custom field.
		update_post_meta( $id, 'attribute_pa_size', 'large' );
		update_post_meta( $id, 'attribute_pa_color', 'red' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/fields/meta-keys' ) );
		$keys     = $response->get_data()['keys'];

		$this->assertNotContains( 'attribute_pa_size', $keys );
		$this->assertNotContains( 'attribute_pa_color', $keys );
	}

	public function test_endpoint_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/catalogops/v1/fields/meta-keys' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
