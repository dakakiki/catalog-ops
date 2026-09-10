<?php
/**
 * Integration tests for the query REST endpoint.
 *
 * Requires WooCommerce; skipped when it is not loaded in the test WordPress.
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
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
use CatalogOps\Rest\Query_Controller;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Rest\Query_Controller
 */
final class QueryControllerTest extends WP_UnitTestCase {

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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_query_returns_matching_products_with_columns(): void {
		$cheap = $this->make_product( 10 );
		$dear  = $this->make_product( 100 );

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 50 ) ) )
		);

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['items'] );

		$item = $data['items'][0];
		$this->assertSame( $dear, $item['id'] );
		$this->assertNotSame( $cheap, $item['id'] );
		$this->assertArrayHasKey( 'sku', $item );
		$this->assertArrayHasKey( 'price', $item );
		$this->assertArrayHasKey( 'stock_status', $item );
	}

	public function test_rows_carry_the_brand_and_tags_the_filter_matches_on(): void {
		// Both are filterable, so a table without them asks the user to filter on
		// something the results will not show.
		$id    = $this->make_product( 40 );
		$tag   = wp_insert_term( 'QC Clearance', 'product_tag' );
		$brand = wp_insert_term( 'QC Acme', 'product_brand' );

		wp_set_object_terms( $id, array( (int) $tag['term_id'] ), 'product_tag' );
		wp_set_object_terms( $id, array( (int) $brand['term_id'] ), 'product_brand' );

		$data = $this->dispatch( array() );

		$this->assertSame( 'QC Acme', $data['items'][0]['brand'] );
		$this->assertSame( array( 'QC Clearance' ), $data['items'][0]['tags'] );
	}

	public function test_rows_carry_the_category_the_filter_opens_with(): void {
		// Category is the filter's first control and the one most people reach for,
		// and it was the one column the results did not show — the same "filter on
		// what you cannot see" gap brand and tags were added to close. Both
		// taxonomies come back from one statement, so this also pins that reading
		// them together did not lose either.
		$id       = $this->make_product( 40 );
		$category = wp_insert_term( 'QC Seasonal Wear', 'product_cat' );
		$tag      = wp_insert_term( 'QC Markdown', 'product_tag' );

		wp_set_object_terms( $id, array( (int) $category['term_id'] ), 'product_cat' );
		wp_set_object_terms( $id, array( (int) $tag['term_id'] ), 'product_tag' );

		$item = $this->dispatch( array() )['items'][0];

		$this->assertContains( 'QC Seasonal Wear', $item['categories'] );
		$this->assertSame( array( 'QC Markdown' ), $item['tags'] );
		// The two must not bleed into each other: a category is not a tag.
		$this->assertNotContains( 'QC Markdown', $item['categories'] );
		$this->assertNotContains( 'QC Seasonal Wear', $item['tags'] );
	}

	public function test_a_term_name_with_an_ampersand_reads_as_written(): void {
		// WordPress stores term names entity-encoded, and this table reads wp_terms
		// straight through $wpdb, so nothing decodes them on the way out. React then
		// renders the value as text and escapes it again, so the row showed
		// "Home &amp; Kitchen" while the filter's own dropdown — which decodes —
		// said "Home & Kitchen" for the very same term.
		global $wpdb;

		$id       = $this->make_product( 40 );
		$category = wp_insert_term( 'QC Home and Kitchen', 'product_cat' );
		$term_id  = (int) $category['term_id'];

		// Written as the encoded form the real catalogue holds, rather than trusting
		// wp_insert_term to encode it, so the test pins the decode and not WordPress.
		$wpdb->update( $wpdb->terms, array( 'name' => 'QC Home &amp; Kitchen' ), array( 'term_id' => $term_id ), array( '%s' ), array( '%d' ) );
		clean_term_cache( $term_id, 'product_cat' );

		wp_set_object_terms( $id, array( $term_id ), 'product_cat' );

		$item = $this->dispatch( array() )['items'][0];

		$this->assertContains( 'QC Home & Kitchen', $item['categories'] );
		$this->assertNotContains( 'QC Home &amp; Kitchen', $item['categories'] );
	}

	public function test_a_variation_shows_its_parents_categories_and_tags(): void {
		// A variation carries no terms of its own; it inherits the parent's, which
		// is also how the filter matches them — for categories exactly as for tags.
		list( $parent, $variations ) = $this->make_variable_product();
		$category                    = wp_insert_term( 'QC Parent Cat', 'product_cat' );

		wp_set_object_terms( $parent, array( (int) $category['term_id'] ), 'product_cat' );

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params( array( 'scope' => 'variation', 'filter' => array() ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$rows = $response->get_data()['items'];
		$this->assertNotEmpty( $rows );
		$this->assertContains( $variations['Large'], array_column( $rows, 'id' ) );

		foreach ( $rows as $row ) {
			$this->assertContains( 'QC Parent Cat', $row['categories'] );
		}
	}

	public function test_a_variation_shows_its_parents_tags(): void {
		// A variation carries no terms of its own; it inherits the parent's, which
		// is also how the filter matches them.
		list( $parent, $variations ) = $this->make_variable_product();
		$tag = wp_insert_term( 'QC Seasonal', 'product_tag' );

		wp_set_object_terms( $parent, array( (int) $tag['term_id'] ), 'product_tag' );

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params( array( 'scope' => 'variation', 'filter' => array() ) );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$rows = $response->get_data()['items'];
		$this->assertNotEmpty( $rows );
		$this->assertContains( $variations['Large'], array_column( $rows, 'id' ) );

		foreach ( $rows as $row ) {
			$this->assertSame( array( 'QC Seasonal' ), $row['tags'] );
		}
	}

	public function test_a_product_without_a_brand_or_tags_says_so_plainly(): void {
		$this->make_product( 40 );

		$item = $this->dispatch( array() )['items'][0];

		$this->assertNull( $item['brand'] );
		$this->assertSame( array(), $item['tags'] );
	}

	public function test_empty_filter_counts_the_whole_catalog(): void {
		$this->make_product( 10 );
		$this->make_product( 20 );

		$data = $this->dispatch( array() );

		$this->assertSame( 2, $data['total'] );
		$this->assertCount( 2, $data['items'] );
	}

	public function test_pagination_slices_the_frozen_ordered_list(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->make_product( 10 + $i );
		}

		$data = $this->dispatch( array(), 1, 2 );

		$this->assertSame( 5, $data['total'] );
		$this->assertCount( 2, $data['items'] );
		$this->assertSame(
			array_slice( $ids, 0, 2 ),
			array_map( static fn( array $item ): int => $item['id'], $data['items'] )
		);
	}

	public function test_endpoint_requires_a_capability(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$response = rest_do_request( $request );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_variation_scope_returns_variations_with_parent_context(): void {
		list( $parent, $variations ) = $this->make_variable_product();

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'scope'    => 'variation',
				'filter'   => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) ),
				'per_page' => 25,
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( 'variation', $data['scope'] );
		$this->assertSame( 1, $data['total'] );

		$item = $data['items'][0];
		$this->assertSame( $variations['Large'], $item['id'] );
		$this->assertSame( $parent, $item['parent_id'] );
		// The label carries the parent title and the variation's attribute value.
		$this->assertStringContainsString( 'QC Variable', $item['name'] );
		$this->assertStringContainsString( 'large', $item['name'] );
	}

	public function test_an_empty_product_result_points_at_the_variations_that_match(): void {
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) )
		);

		// A variable parent keeps no price of its own, so the Products scope has
		// nothing to match here — what the user is looking for is one scope over,
		// and an empty table alone would never say so.
		$this->assertSame( 0, $data['total'] );
		$this->assertSame(
			array(
				'scope' => 'variation',
				'total' => 1,
			),
			$data['other_scope']
		);
	}

	public function test_an_empty_variation_result_points_back_at_products(): void {
		$this->make_product( 100 );

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'scope'  => 'variation',
				'filter' => array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 50 ) ) ),
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// The suggestion runs both ways: a catalogue of simple products has no
		// variations to find, and saying so beats an unexplained empty table.
		$this->assertSame( 0, $data['total'] );
		$this->assertSame(
			array(
				'scope' => 'product',
				'total' => 1,
			),
			$data['other_scope']
		);
	}

	public function test_a_result_with_matches_suggests_nothing(): void {
		$this->make_product( 100 );
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) )
		);

		// Variations match this too, but the user is looking at results — there is
		// nothing to point out, and the second count is never even run.
		$this->assertSame( 1, $data['total'] );
		$this->assertNull( $data['other_scope'] );
	}

	public function test_a_filter_that_matches_nowhere_suggests_nothing(): void {
		$this->make_product( 10 );
		$this->make_variable_product();

		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 500 ) ) )
		);

		// Too narrow, not pointed at the wrong scope. Offering a switch to an
		// equally empty table would be noise.
		$this->assertSame( 0, $data['total'] );
		$this->assertNull( $data['other_scope'] );
	}

	/**
	 * Pins that a condition naming a field the engine cannot answer comes back as a
	 * 400 whose message names the field, so the admin app can put an inline notice
	 * on the condition that needs fixing.
	 *
	 * 0.7.1 answered 200 and listed the whole catalogue instead: `clause_for()`
	 * returned an empty SQL fragment for an unknown field, `build_where()` skipped
	 * it, and the filter ran with one condition fewer — matching strictly more
	 * products than were asked for, in preview and in the run alike. The code is
	 * asserted as well as the status because a 500-shaped failure can present as a
	 * status in some setups, and this endpoint had no try/catch of its own, so a
	 * refusal here surfaced as a PHP fatal.
	 */
	public function test_a_filter_naming_an_unknown_field_answers_400_not_a_fatal(): void {
		// Present so a dropped condition would be visible as a 200 listing it.
		$this->make_product( 10 );

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter' => array(
					'conditions' => array( array( 'field' => 'acf:clearance', 'operator' => '=', 'value' => 'yes' ) ),
				),
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalogops_invalid_request', $data['code'] );
		$this->assertStringContainsString( 'acf:clearance', $data['message'] );
	}

	/**
	 * Pins that a mistyped operator token is a 400 naming the token.
	 *
	 * 0.7.1 built the operator with `Operator::from()`, which raises \ValueError —
	 * an \Error, not an \Exception — so every `catch ( InvalidArgumentException )`
	 * at the REST boundary missed it and a typo in the filter JSON was an uncaught
	 * fatal rather than a message the user could act on.
	 */
	public function test_an_unknown_operator_token_answers_400_not_a_fatal(): void {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter' => array(
					'conditions' => array( array( 'field' => 'price', 'operator' => 'roughly', 'value' => 50 ) ),
				),
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'catalogops_invalid_request', $data['code'] );
		$this->assertStringContainsString( 'roughly', $data['message'] );
	}

	/**
	 * A guard for behaviour that must not change: a well-formed filter still gets a
	 * 200 and still gets the empty-result hint.
	 *
	 * `query()` was restructured so that `Filter::from_array()`, `resolve()` and
	 * `other_scope()` share one try — the hint re-asks the same filter, and it only
	 * asks when the first call found nothing, which is exactly the state a filter
	 * naming a vanished field reaches. This pins that the shared try neither
	 * changed the happy path nor swallowed the second scope's count.
	 */
	public function test_the_empty_result_hint_still_works_after_the_guard(): void {
		$this->make_product( 10 );
		$this->make_variable_product();

		// dispatch() asserts the 200 itself; the hint has to ride along with it.
		$data = $this->dispatch(
			array( 'conditions' => array( array( 'field' => 'price', 'operator' => '>', 'value' => 30 ) ) )
		);

		$this->assertSame( 0, $data['total'] );
		$this->assertSame(
			array(
				'scope' => 'variation',
				'total' => 1,
			),
			$data['other_scope']
		);
	}

	/**
	 * Create a variable product with a Small and a Large variation.
	 *
	 * @return array{0: int, 1: array<string, int>}
	 */
	private function make_variable_product(): array {
		$size_tax = wc_attribute_taxonomy_name( 'size' );
		if ( ! wc_attribute_taxonomy_id_by_name( 'size' ) ) {
			wc_create_attribute( array( 'name' => 'Size', 'slug' => 'size', 'type' => 'select' ) );
		}
		if ( ! taxonomy_exists( $size_tax ) ) {
			register_taxonomy( $size_tax, array( 'product' ), array( 'hierarchical' => false, 'query_var' => true ) );
		}

		$small = (int) wp_insert_term( 'Small', $size_tax )['term_id'];
		$large = (int) wp_insert_term( 'Large', $size_tax )['term_id'];

		$parent = new WC_Product_Variable();
		$parent->set_name( 'QC Variable' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'size' ) );
		$attribute->set_name( $size_tax );
		$attribute->set_options( array( $small, $large ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent_id = $parent->save();

		$variations = array();
		foreach ( array( 'Small' => 10, 'Large' => 90 ) as $name => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( array( $size_tax => sanitize_title( $name ) ) );
			$variation->set_regular_price( (string) $price );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 5 );
			$variation->set_stock_status( 'instock' );
			$variations[ $name ] = $variation->save();
		}

		return array( $parent_id, $variations );
	}

	/**
	 * Dispatch a query request and assert a 200, returning the payload.
	 *
	 * @param array<string, mixed> $filter   Filter array.
	 * @param int                  $page     Page number.
	 * @param int                  $per_page Page size.
	 * @return array<string, mixed>
	 */
	/**
	 * The empty-result hint asks the same filter in the OTHER scope, and a module
	 * field that applies to one scope only turns that question into a refusal.
	 * Reported live: Products was selected, an ACF condition narrowed the result to
	 * nothing, and the table answered "The field acf:field_cops_supplier does not
	 * apply to variations." — a sentence about variations, to somebody who had
	 * asked about products and never mentioned variations. The empty result they
	 * had actually asked for was nowhere on screen, and the count from the previous
	 * run was still above the message contradicting it.
	 *
	 * Every ACF group has this shape: ACF registers a group per post type, so
	 * nearly every field a shop adds is products-only. The hint has to stay silent
	 * rather than escalate.
	 */
	public function test_a_field_that_means_nothing_in_the_other_scope_is_not_an_error(): void {
		// Present, so a filter that wrongly matched everything would be visible.
		$this->make_product( 10 );

		$controller = $this->controller_with_a_products_only_field();

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter' => array(
					'scope'      => 'product',
					'conditions' => array(
						array(
							'field'    => 'demo:supplier',
							'operator' => '=',
							'value'    => 'nobody supplies this',
						),
					),
				),
			)
		);

		$response = $controller->query( $request );

		$this->assertSame( 200, $response->get_status(), 'An empty result is an answer, not a bad request.' );

		$data = $response->get_data();

		$this->assertSame( 0, $data['total'] );
		$this->assertSame( array(), $data['items'] );
		$this->assertNull(
			$data['other_scope'],
			'There is nothing to suggest: the field does not exist in the other scope.'
		);
	}

	/**
	 * And the refusal must still reach the user when it is about the scope they
	 * ARE looking at — that one is a real problem with a real fix, and swallowing
	 * it would leave a condition nobody could see was broken.
	 */
	public function test_the_same_field_still_refuses_in_the_scope_it_does_not_apply_to(): void {
		$this->make_product( 10 );

		$controller = $this->controller_with_a_products_only_field();

		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter' => array(
					'scope'      => 'variation',
					'conditions' => array(
						array(
							'field'    => 'demo:supplier',
							'operator' => '=',
							'value'    => 'anyone',
						),
					),
				),
			)
		);

		$response = $controller->query( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertStringContainsString( 'demo:supplier', $response->get_error_message() );
	}

	/**
	 * A controller whose registry holds one field on products only — the shape of
	 * every ACF field group, since ACF registers a group against a post type.
	 */
	private function controller_with_a_products_only_field(): Query_Controller {
		global $wpdb;

		$provider = new class() implements Filter_Provider {

			public function module(): string {
				return '';
			}

			public function label(): string {
				return 'Demo';
			}

			public function filter_fields( ?string $language = null ): array {
				unset( $language );

				return array(
					new Filter_Field(
						'demo:supplier',
						'Supplier',
						Filter_Control::TEXT,
						array( Operator::EQUALS ),
						array( Query_Scope::PRODUCT )
					),
				);
			}

			public function handles_filter( string $key ): bool {
				return str_starts_with( $key, 'demo:' );
			}

			public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
				unset( $key, $scope );

				return Field_Storage::post_meta( 'demo_supplier', Value_Kind::TEXT );
			}
		};

		$registry = new Filter_Providers( new License( true, true ), $provider );

		return new Query_Controller( new Query_Engine( $wpdb, $registry ), $wpdb );
	}

	private function dispatch( array $filter, int $page = 1, int $per_page = 25 ): array {
		$request = new WP_REST_Request( 'POST', '/catalogops/v1/products/query' );
		$request->set_body_params(
			array(
				'filter'   => $filter,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	private function make_product( float $price ): int {
		$product = new WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->set_stock_status( 'instock' );

		return $product->save();
	}
}
