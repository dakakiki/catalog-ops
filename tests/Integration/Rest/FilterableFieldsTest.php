<?php
/**
 * The route that makes a module's fields reachable.
 *
 * Until this existed, `Filter_Providers::all_fields()` had no production caller
 * at all: a module could ship a field with perfect SQL behind it and no user
 * could ever select it, because the filter's control list is written by hand in
 * the React bundle.
 *
 * @package CatalogOps\Tests\Integration\Rest
 */

namespace CatalogOps\Tests\Integration\Rest;

use CatalogOps\Licensing\License;
use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Filter_Provider;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use CatalogOps\Rest\Fields_Controller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Fields_Controller
 * @covers \CatalogOps\Query\Fields\Filter_Providers
 */
final class FilterableFieldsTest extends WP_UnitTestCase {

	public function test_a_registered_field_is_described_for_the_client(): void {
		$fields = $this->fields( License::unlimited() );

		$this->assertCount( 1, $fields );

		$field = $fields[0];

		$this->assertSame( 'demo:supplier', $field['key'] );
		$this->assertSame( 'Supplier', $field['label'] );
		$this->assertSame( 'text', $field['control'] );
		$this->assertSame( 'Supplier', $field['column_label'] );
		$this->assertSame( 'acf', $field['module'] );
		// The heading the client groups this field under. Served, never derived: a
		// client that turned the slug 'acf' into "ACF fields" itself would have to be
		// taught every future module's name, which is the coupling `options_route`
		// exists to avoid. Without this the section renders with no heading at all,
		// which is what it looked like before there was a group.
		$this->assertSame( 'Test module', $field['module_label'] );
		$this->assertTrue( $field['available'] );
	}

	/**
	 * Operators and scopes travel as the tokens filter_json persists, not as
	 * objects. The client posts them straight back, and a shape that has to be
	 * translated in both directions can be translated wrongly in one of them.
	 */
	public function test_operators_and_scopes_travel_as_persisted_tokens(): void {
		$field = $this->fields( License::unlimited() )[0];

		$this->assertSame( array( '=', '!=', 'in' ), $field['operators'] );
		$this->assertSame( array( 'product' ), $field['scopes'] );
	}

	/**
	 * A field the licence does not cover is LISTED and flagged, never dropped.
	 *
	 * Dropping it would leave a saved filter that already names the field opening
	 * against a control list without it — a condition the user cannot see, cannot
	 * correct, and which the engine goes on refusing.
	 */
	public function test_an_unlicensed_field_is_listed_and_flagged_rather_than_dropped(): void {
		$fields = $this->fields( new License( true, false ) );

		$this->assertCount( 1, $fields, 'Still listed.' );
		$this->assertFalse( $fields[0]['available'] );
		$this->assertSame( 'demo:supplier', $fields[0]['key'] );
	}

	/**
	 * With no registry the route answers an empty list rather than failing, which
	 * is what every installation looks like until a module registers.
	 */
	public function test_no_registry_is_an_empty_list_not_an_error(): void {
		global $wpdb;

		$response = ( new Fields_Controller( $wpdb ) )->filterable( new WP_REST_Request( 'GET', '/catalogops/v1/fields/filterable' ) );

		$this->assertSame( array( 'fields' => array() ), $response->get_data() );
	}

	public function test_the_route_is_registered_and_needs_the_capability(): void {
		do_action( 'rest_api_init' );

		global $wpdb;
		( new Fields_Controller( $wpdb ) )->register_routes();

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/catalogops/v1/fields/filterable', $routes );

		wp_set_current_user( 0 );
		$this->assertFalse( ( new Fields_Controller( $wpdb ) )->can_manage() );
	}

	/**
	 * Call the route and return its field list.
	 *
	 * @param License $license The licence to resolve module availability against.
	 * @return array<int, array<string, mixed>>
	 */
	private function fields( License $license ): array {
		global $wpdb;

		$controller = new Fields_Controller(
			$wpdb,
			new Filter_Providers( $license, $this->provider() )
		);

		$data = $controller->filterable( new WP_REST_Request( 'GET', '/catalogops/v1/fields/filterable' ) )->get_data();

		return $data['fields'];
	}

	/**
	 * A provider offering one licensed text field on products.
	 */
	private function provider(): Filter_Provider {
		return new class() implements Filter_Provider {

			public function module(): string {
				return 'acf';
			}

			public function label(): string {
				return 'Test module';
			}

			public function filter_fields( ?string $language = null ): array {
				unset( $language );

				return array(
					new Filter_Field(
						'demo:supplier',
						'Supplier',
						Filter_Control::TEXT,
						array( Operator::EQUALS, Operator::NOT_EQUALS, Operator::IN ),
						array( Query_Scope::PRODUCT ),
						'',
						'Supplier'
					),
				);
			}

			public function handles_filter( string $key ): bool {
				return 'demo:supplier' === $key;
			}

			public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
				return Field_Storage::post_meta( 'demo_supplier', Value_Kind::TEXT );
			}
		};
	}
}
