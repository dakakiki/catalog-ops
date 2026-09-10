<?php
/**
 * The ACF module against real `acf-field` posts.
 *
 * The unit tests pin the type → storage decision, which is pure. This pins the
 * half that is not: reading ACF's own posts, walking a sub-field up to the group
 * that owns it, and turning what is found into descriptors. Nothing here calls
 * ACF, and neither does the code under test — that is the property being
 * protected.
 *
 * @package CatalogOps\Tests\Integration\Modules\Acf
 */

namespace CatalogOps\Tests\Integration\Modules\Acf;

use CatalogOps\Modules\Acf\Acf_Fields;
use CatalogOps\Modules\Acf\Acf_Filter_Provider;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Storage_Kind;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Modules\Acf\Acf_Filter_Provider
 * @covers \CatalogOps\Modules\Acf\Acf_Fields
 */
final class AcfFilterProviderTest extends WP_UnitTestCase {

	/**
	 * The provider under test.
	 *
	 * @var Acf_Filter_Provider
	 */
	private Acf_Filter_Provider $provider;

	/**
	 * Build the provider over the live handle.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->provider = new Acf_Filter_Provider( $wpdb, new Acf_Fields( $wpdb ) );
	}

	/**
	 * Create a field group the way ACF's admin does.
	 *
	 * @param string $post_type The post type its location rule names.
	 * @return int The group post id.
	 */
	private function group( string $post_type = 'product' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'acf-field-group',
				'post_status'  => 'publish',
				'post_title'   => 'Test group',
				'post_name'    => 'group_test',
				'post_content' => serialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirroring exactly how ACF writes this column.
					array(
						'location' => array(
							array(
								array(
									'param'    => 'post_type',
									'operator' => '==',
									'value'    => $post_type,
								),
							),
						),
					)
				),
			)
		);
	}

	/**
	 * Create a field the way ACF's admin does.
	 *
	 * @param int                  $parent   Group id, or a parent field id for a sub-field.
	 * @param string               $key      ACF field key.
	 * @param string               $name     Meta key it writes to.
	 * @param string               $label    Human label.
	 * @param array<string, mixed> $settings Type and any extra settings.
	 * @return int The field post id.
	 */
	private function field( int $parent, string $key, string $name, string $label, array $settings ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => 'acf-field',
				'post_status'  => 'publish',
				'post_parent'  => $parent,
				'post_name'    => $key,
				'post_excerpt' => $name,
				'post_title'   => $label,
				'post_content' => serialize( $settings ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mirroring exactly how ACF writes this column.
			)
		);
	}

	/**
	 * The descriptor for a key, or null.
	 *
	 * @param string $key The full filter key.
	 * @return \CatalogOps\Query\Fields\Filter_Field|null
	 */
	private function described( string $key ) {
		foreach ( $this->provider->filter_fields() as $field ) {
			if ( $key === $field->key ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * A plain text field is one postmeta row, offered on products.
	 */
	public function test_a_text_field_is_described_and_stored_as_post_meta(): void {
		$group = $this->group();
		$this->field( $group, 'field_supplier', 'supplier', 'Supplier', array( 'type' => 'text' ) );

		$field = $this->described( 'acf:field_supplier' );

		$this->assertNotNull( $field );
		$this->assertSame( 'Supplier', $field->label );
		$this->assertSame( Filter_Control::TEXT, $field->control );
		$this->assertSame( array( Query_Scope::PRODUCT ), $field->scopes );

		$storage = $this->provider->storage_for( 'acf:field_supplier', Query_Scope::PRODUCT );

		$this->assertSame( Storage_Kind::POST_META, $storage->kind );
		$this->assertSame( Value_Kind::TEXT, $storage->value_kind );
		$this->assertSame( 'supplier', $storage->key_prefix );
	}

	/**
	 * **Presence is offered on a set field and on nothing else**, and that split is
	 * a decision rather than an oversight.
	 *
	 * Every value kind in the engine answers `EXISTS` / `NOT_EXISTS`, so declaring
	 * them everywhere was the vocabulary's default rather than an answer to "would a
	 * shop owner ask this" — and "Supplier is filled in" is not a set of products
	 * anyone reprices. On a set field it is the one question no choice can express.
	 * This test fails in either direction: if presence creeps back onto a text,
	 * number, date or toggle field, or if it is dropped from a set field and takes
	 * the "Without a value" entry down with it.
	 */
	public function test_no_value_field_offers_a_presence_operator(): void {
		$group = $this->group();
		$this->field( $group, 'field_supplier', 'supplier', 'Supplier', array( 'type' => 'text' ) );
		$this->field( $group, 'field_qty', 'min_qty', 'Minimum quantity', array( 'type' => 'number' ) );
		$this->field( $group, 'field_gone', 'discontinued', 'Discontinued', array( 'type' => 'true_false' ) );
		$this->field( $group, 'field_when', 'available_from', 'Available from', array( 'type' => 'date_picker' ) );
		$this->field(
			$group,
			'field_badges',
			'badges',
			'Badges',
			array(
				'type'     => 'select',
				'multiple' => 1,
				'choices'  => array( 'sale' => 'On sale' ),
			)
		);

		$fields = $this->provider->filter_fields();

		$this->assertNotEmpty( $fields );

		foreach ( $fields as $field ) {
			if ( Filter_Control::VALUE_SET === $field->control ) {
				// The one place it belongs, and the reason is in the docblock on
				// Acf_Filter_Provider::OPERATORS: "carries no badge at all" cannot
				// be said any other way, because `is not sale` keeps exactly those
				// products. Offered as a "Without a value" entry in the list, the
				// way the tag row already offers "Without tag" — never as an
				// operator beside the control.
				$this->assertContains( Operator::NOT_EXISTS, $field->operators );

				continue;
			}

			$this->assertNotContains(
				Operator::EXISTS,
				$field->operators,
				sprintf( '%s must not offer "has any value".', $field->key )
			);
			$this->assertNotContains(
				Operator::NOT_EXISTS,
				$field->operators,
				sprintf( '%s must not offer "has no value".', $field->key )
			);
		}
	}

	/**
	 * A number is the only field that can be asked an ordered question, which is
	 * the whole reason it is worth supporting.
	 */
	public function test_a_number_field_offers_the_ordered_operators(): void {
		$group = $this->group();
		$this->field( $group, 'field_qty', 'min_qty', 'Minimum quantity', array( 'type' => 'number' ) );

		$field = $this->described( 'acf:field_qty' );

		$this->assertNotNull( $field );
		$this->assertContains( Operator::GREATER_THAN, $field->operators );
		$this->assertContains( Operator::BETWEEN, $field->operators );
		$this->assertSame(
			Value_Kind::NUMERIC_TEXT,
			$this->provider->storage_for( 'acf:field_qty', Query_Scope::PRODUCT )->value_kind
		);
	}

	/**
	 * The `multiple` setting decides the storage, not the type — and getting it
	 * wrong is silent: a serialised list compared as text matches nothing.
	 */
	public function test_a_multiple_select_is_a_serialised_list_and_a_single_one_is_not(): void {
		$group = $this->group();
		$this->field(
			$group,
			'field_one',
			'origin',
			'Origin',
			array(
				'type'     => 'select',
				'multiple' => 0,
				'choices'  => array( 'rs' => 'Serbia' ),
			)
		);
		$this->field(
			$group,
			'field_many',
			'badges',
			'Badges',
			array(
				'type'     => 'select',
				'multiple' => 1,
				'choices'  => array( 'sale' => 'On sale' ),
			)
		);

		$this->assertSame(
			Value_Kind::TEXT,
			$this->provider->storage_for( 'acf:field_one', Query_Scope::PRODUCT )->value_kind
		);
		$this->assertSame(
			Value_Kind::SERIALIZED_LIST,
			$this->provider->storage_for( 'acf:field_many', Query_Scope::PRODUCT )->value_kind
		);
	}

	/**
	 * A checkbox is an array whatever `multiple` says — ACF has no single-value
	 * checkbox — while a radio is a single string, whatever a reader assumes from
	 * the round boxes.
	 */
	public function test_a_checkbox_is_a_list_and_a_radio_is_not(): void {
		$group = $this->group();
		$this->field( $group, 'field_cb', 'features', 'Features', array( 'type' => 'checkbox' ) );
		$this->field( $group, 'field_radio', 'grade', 'Grade', array( 'type' => 'radio' ) );

		$this->assertSame(
			Value_Kind::SERIALIZED_LIST,
			$this->provider->storage_for( 'acf:field_cb', Query_Scope::PRODUCT )->value_kind
		);
		$this->assertSame(
			Value_Kind::TEXT,
			$this->provider->storage_for( 'acf:field_radio', Query_Scope::PRODUCT )->value_kind
		);
	}

	/**
	 * A date declares the format it is STORED in, so the client can send what the
	 * column holds rather than what a date input produces.
	 */
	public function test_a_date_field_declares_its_stored_format(): void {
		$group = $this->group();
		$this->field( $group, 'field_d', 'available_from', 'Available from', array( 'type' => 'date_picker' ) );
		$this->field( $group, 'field_dt', 'restock_at', 'Restock at', array( 'type' => 'date_time_picker' ) );

		$this->assertSame( 'Ymd', $this->described( 'acf:field_d' )->value_format );
		$this->assertSame( 'Y-m-d H:i:s', $this->described( 'acf:field_dt' )->value_format );
		$this->assertSame( Filter_Control::DATE, $this->described( 'acf:field_d' )->control );
	}

	/**
	 * A legacy little-endian date is refused rather than ordered wrongly, and it is
	 * not offered in the list either — the list and the clause path must agree, or
	 * the UI offers a field that always fails.
	 */
	public function test_a_legacy_date_format_is_neither_offered_nor_answered(): void {
		$group = $this->group();
		$this->field(
			$group,
			'field_old',
			'legacy_date',
			'Legacy date',
			array(
				'type'        => 'date_picker',
				'save_format' => 'd/m/Y',
			)
		);

		$this->assertNull( $this->described( 'acf:field_old' ) );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->provider->storage_for( 'acf:field_old', Query_Scope::PRODUCT );
	}

	/**
	 * A repeater's sub-field becomes the whole family of indexed rows, which is the
	 * only thing the generic `meta:` path could never ask: it can be pointed at
	 * `co_specs_0_value` and no further.
	 */
	public function test_a_repeater_sub_field_becomes_a_row_family(): void {
		$group    = $this->group();
		$repeater = $this->field( $group, 'field_specs', 'specs', 'Specs', array( 'type' => 'repeater' ) );
		$this->field( $repeater, 'field_specs_value', 'value', 'Value', array( 'type' => 'text' ) );

		$field = $this->described( 'acf:field_specs_value' );

		$this->assertNotNull( $field );
		$this->assertSame( 'Specs › Value', $field->label, 'A sub-field is named by its ancestors, or twenty fields read "Value".' );

		$storage = $this->provider->storage_for( 'acf:field_specs_value', Query_Scope::PRODUCT );

		$this->assertSame( Storage_Kind::POST_META_ROWS, $storage->kind );
		$this->assertSame( 'specs_', $storage->key_prefix );
		$this->assertSame( '_value', $storage->key_suffix );
	}

	/**
	 * The repeater itself holds a row count, never a value.
	 */
	public function test_the_repeater_itself_is_not_offered(): void {
		$group = $this->group();
		$this->field( $group, 'field_specs', 'specs', 'Specs', array( 'type' => 'repeater' ) );

		$this->assertNull( $this->described( 'acf:field_specs' ) );
	}

	/**
	 * Types whose storage cannot be decided from the definition are absent from the
	 * list, not merely refused when asked. Offering a field that always fails is
	 * worse than not offering it.
	 *
	 * @dataProvider refused_types
	 *
	 * @param string $type ACF field type.
	 */
	public function test_a_type_that_cannot_be_expressed_is_not_offered( string $type ): void {
		$group = $this->group();
		$this->field( $group, 'field_x', 'x', 'X', array( 'type' => $type ) );

		$this->assertNull( $this->described( 'acf:field_x' ), sprintf( '"%s" must not be offered.', $type ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refused_types(): array {
		return array(
			'taxonomy'    => array( 'taxonomy' ),
			'post_object' => array( 'post_object' ),
			'image'       => array( 'image' ),
			'gallery'     => array( 'gallery' ),
			'google_map'  => array( 'google_map' ),
			'wysiwyg'     => array( 'wysiwyg' ),
			'flexible'    => array( 'flexible_content' ),
		);
	}

	/**
	 * A group that is not on products has nothing to say about them.
	 */
	public function test_a_group_on_another_post_type_is_not_offered(): void {
		$group = $this->group( 'page' );
		$this->field( $group, 'field_page', 'subtitle', 'Subtitle', array( 'type' => 'text' ) );

		$this->assertNull( $this->described( 'acf:field_page' ) );
	}

	/**
	 * A field ACF has deleted is a refusal naming the key, not an empty clause. An
	 * empty fragment would leave the filter WIDER than the user wrote, which is the
	 * one direction nothing downstream can notice.
	 */
	public function test_a_missing_field_is_refused_by_name(): void {
		$this->expectException( Filter_Field_Unavailable::class );

		$this->provider->storage_for( 'acf:field_never_existed', Query_Scope::PRODUCT );
	}

	/**
	 * Cheap and pure, because it is asked once per condition per rendered
	 * statement and one preview renders its filter 2+N+W times.
	 */
	public function test_it_claims_its_namespace_and_nothing_else(): void {
		$this->assertTrue( $this->provider->handles_filter( 'acf:field_anything' ) );
		$this->assertFalse( $this->provider->handles_filter( 'meta:acf_thing' ) );
		$this->assertFalse( $this->provider->handles_filter( 'price' ) );
	}

	/**
	 * ACFML registers every ACF field label with WPML String Translation, under the
	 * group it belongs to and keyed `field-{key}-label-{md5 of the label}`. A shop
	 * that has translated its field names should see them in the filter.
	 *
	 * The listener below stands exactly where WPML stands, and it asserts the
	 * arguments it is given rather than only the answer: the context and the name
	 * are ACFML's convention, and getting either wrong would miss silently and hand
	 * back the English label — which looks identical to a shop that simply has not
	 * translated anything.
	 */
	public function test_a_label_is_offered_in_the_language_that_was_asked_for(): void {
		$group = $this->group();
		$this->field( $group, 'field_season', 'season', 'Season', array( 'type' => 'text' ) );

		$seen = array();

		add_filter(
			'wpml_translate_single_string',
			static function ( $value, $context, $name, $language ) use ( &$seen ) {
				$seen[] = compact( 'value', 'context', 'name', 'language' );

				return ( 'Season' === $value && 'sr' === $language ) ? 'Sezona' : $value;
			},
			10,
			4
		);

		$fields = $this->provider->filter_fields( 'sr' );
		$labels = array();

		foreach ( $fields as $field ) {
			$labels[ $field->key ] = $field->label;
		}

		$this->assertSame( 'Sezona', $labels['acf:field_season'] ?? null );

		$this->assertSame( 'acf-field-group-group_test', $seen[0]['context'] );
		$this->assertSame( 'field-field_season-label-' . md5( 'Season' ), $seen[0]['name'] );
		$this->assertSame( 'sr', $seen[0]['language'] );
	}

	/**
	 * The column label travels with the field into the results table, so it has to
	 * be the translated one too — a header in one language above a filter row in
	 * another is worse than both being English.
	 */
	public function test_the_column_label_is_translated_with_the_field_label(): void {
		$group = $this->group();
		$this->field( $group, 'field_season', 'season', 'Season', array( 'type' => 'text' ) );

		add_filter( 'wpml_translate_single_string', static fn( $value ) => 'Season' === $value ? 'Sezona' : $value, 10, 4 );

		foreach ( $this->provider->filter_fields( 'sr' ) as $field ) {
			if ( 'acf:field_season' === $field->key ) {
				$this->assertSame( 'Sezona', $field->column_label );

				return;
			}
		}

		$this->fail( 'the field was not offered at all' );
	}

	/**
	 * No language asked for is the overwhelmingly common case — every site without
	 * WPML, and every caller that existed before this — and it must not so much as
	 * ask. A provider that consulted WPML anyway would translate into whatever
	 * language the request resolved as, which on a REST call is the site default.
	 */
	public function test_no_language_asks_for_no_translation_at_all(): void {
		$group = $this->group();
		$this->field( $group, 'field_season', 'season', 'Season', array( 'type' => 'text' ) );

		$asked = false;

		add_filter(
			'wpml_translate_single_string',
			static function ( $value ) use ( &$asked ) {
				$asked = true;

				return 'Sezona';
			},
			10,
			4
		);

		$labels = array();

		foreach ( $this->provider->filter_fields() as $field ) {
			$labels[ $field->key ] = $field->label;
		}

		$this->assertFalse( $asked, 'nothing should have been asked of WPML' );
		$this->assertSame( 'Season', $labels['acf:field_season'] ?? null );
	}

	/**
	 * A label nobody has translated comes back as it is, and the field stays in the
	 * list. Dropping it would hide a field a saved filter may already name.
	 */
	public function test_an_untranslated_label_is_left_alone_and_the_field_stays(): void {
		$group = $this->group();
		$this->field( $group, 'field_season', 'season', 'Season', array( 'type' => 'text' ) );

		// WPML's own behaviour when it holds no translation: it returns the original.
		add_filter( 'wpml_translate_single_string', static fn( $value ) => $value, 10, 4 );

		$labels = array();

		foreach ( $this->provider->filter_fields( 'sr' ) as $field ) {
			$labels[ $field->key ] = $field->label;
		}

		$this->assertSame( 'Season', $labels['acf:field_season'] ?? null );
	}
}
