<?php
/**
 * Unit tests for the ACF type → storage decision.
 *
 * @package CatalogOps\Tests\Unit\Modules\Acf
 */

namespace CatalogOps\Tests\Unit\Modules\Acf;

use CatalogOps\Modules\Acf\Acf_Fields;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter_Field_Unavailable;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * The decision table, pinned.
 *
 * `value_kind()` is the whole module in one method: it is where a wrong answer
 * becomes a clause that returns a plausible, non-empty, wrong set with preview and
 * run agreeing on it perfectly. It is also pure — it takes a definition array and
 * touches no database — so it can be pinned exhaustively here rather than against a
 * fixture catalogue.
 *
 * @covers \CatalogOps\Modules\Acf\Acf_Fields
 */
final class AcfFieldsTest extends TestCase {

	/**
	 * The reader under test. No query runs in any test here.
	 */
	private Acf_Fields $fields;

	/**
	 * Build the reader over a stub handle.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->fields = new Acf_Fields( $this->createStub( wpdb::class ) );
	}

	/**
	 * A definition as ACF stores one, with the given overrides.
	 *
	 * @param string               $type      ACF field type.
	 * @param array<string, mixed> $overrides Extra settings.
	 * @return array<string, mixed>
	 */
	private function definition( string $type, array $overrides = array() ): array {
		return array_merge(
			array(
				'key'    => 'field_test',
				'name'   => 'test_field',
				'label'  => 'Test field',
				'type'   => $type,
				'parent' => 0,
			),
			$overrides
		);
	}

	/**
	 * Text-ish types compare as text and collect free entry.
	 *
	 * @dataProvider text_types
	 *
	 * @param string $type ACF field type.
	 */
	public function test_text_types_compare_as_text( string $type ): void {
		list( $kind, $control ) = $this->fields->value_kind( $this->definition( $type ) );

		$this->assertSame( Value_Kind::TEXT, $kind );
		$this->assertSame( Filter_Control::TEXT, $control );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function text_types(): array {
		return array(
			'text'     => array( 'text' ),
			'textarea' => array( 'textarea' ),
			'email'    => array( 'email' ),
			'url'      => array( 'url' ),
			'wysiwyg'  => array( 'wysiwyg' ),
		);
	}

	/**
	 * An ACF number is a string in the column, so it must be cast rather than
	 * compared as text — otherwise `'9' > '10'` is true and a price floor filter
	 * quietly returns the wrong products.
	 *
	 * @dataProvider number_types
	 *
	 * @param string $type ACF field type.
	 */
	public function test_numbers_are_cast_not_compared_as_text( string $type ): void {
		list( $kind, $control ) = $this->fields->value_kind( $this->definition( $type ) );

		$this->assertSame( Value_Kind::NUMERIC_TEXT, $kind );
		$this->assertSame( Filter_Control::NUMBER, $control );
		$this->assertFalse( $kind->is_indexed(), 'The cast defeats the meta_key index, and the UI is entitled to say so.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function number_types(): array {
		return array(
			'number' => array( 'number' ),
			'range'  => array( 'range' ),
		);
	}

	/**
	 * ACF writes `'1'` and `'0'` for a true_false, which are strings. Declaring
	 * INTEGER would bind `%d` against a text column for no gain.
	 */
	public function test_a_true_false_is_a_text_toggle(): void {
		list( $kind, $control ) = $this->fields->value_kind( $this->definition( 'true_false' ) );

		$this->assertSame( Value_Kind::TEXT, $kind );
		$this->assertSame( Filter_Control::TOGGLE, $control );
	}

	/**
	 * The `multiple` setting, not the type, decides how a select is stored — one
	 * plain string, or a serialised list.
	 */
	public function test_a_single_select_is_text_and_a_multiple_one_is_a_serialised_list(): void {
		list( $single ) = $this->fields->value_kind(
			$this->definition( 'select', array( 'multiple' => 0 ) )
		);
		list( $multi ) = $this->fields->value_kind(
			$this->definition( 'select', array( 'multiple' => 1 ) )
		);

		$this->assertSame( Value_Kind::TEXT, $single );
		$this->assertSame( Value_Kind::SERIALIZED_LIST, $multi );
	}

	/**
	 * A checkbox is always an array, whatever `multiple` says — ACF has no
	 * single-value checkbox — so it must not depend on the setting being present.
	 */
	public function test_a_checkbox_is_always_a_serialised_list(): void {
		list( $kind, $control ) = $this->fields->value_kind( $this->definition( 'checkbox' ) );

		$this->assertSame( Value_Kind::SERIALIZED_LIST, $kind );
		$this->assertSame( Filter_Control::VALUE_SET, $control );
	}

	/**
	 * Modern ACF dates are zero-padded and big-endian, so a lexical range orders
	 * them correctly.
	 *
	 * @dataProvider date_types
	 *
	 * @param string $type ACF field type.
	 */
	public function test_modern_dates_range_as_text( string $type ): void {
		list( $kind, $control ) = $this->fields->value_kind( $this->definition( $type ) );

		$this->assertSame( Value_Kind::DATE_TEXT, $kind );
		$this->assertSame( Filter_Control::DATE, $control );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function date_types(): array {
		return array(
			'date_picker'      => array( 'date_picker' ),
			'date_time_picker' => array( 'date_time_picker' ),
		);
	}

	/**
	 * The trap this test exists for: ACF 4's `save_format` survives on migrated
	 * fields and offers `d/m/Y`, which a lexical BETWEEN answers plausibly and
	 * wrongly — no error, no empty result, just the wrong products. A refusal is
	 * the only honest answer.
	 */
	public function test_a_legacy_little_endian_date_is_refused(): void {
		$this->expectException( Filter_Field_Unavailable::class );

		$this->fields->value_kind(
			$this->definition( 'date_picker', array( 'save_format' => 'd/m/Y' ) )
		);
	}

	/**
	 * A legacy format that is still big-endian orders correctly and is kept.
	 */
	public function test_a_legacy_big_endian_date_is_still_usable(): void {
		list( $kind ) = $this->fields->value_kind(
			$this->definition( 'date_picker', array( 'save_format' => 'Ymd' ) )
		);

		$this->assertSame( Value_Kind::DATE_TEXT, $kind );
	}

	/**
	 * Every type whose storage cannot be decided from the definition alone. Each of
	 * these would otherwise compile to a clause that is confidently wrong, which is
	 * strictly worse than a field the filter does not offer.
	 *
	 * @dataProvider refused_types
	 *
	 * @param string $type ACF field type.
	 */
	public function test_types_whose_storage_cannot_be_known_are_refused( string $type ): void {
		$this->assertFalse(
			$this->fields->is_expressible( $this->definition( $type ) ),
			sprintf( 'A "%s" field must not be offered.', $type )
		);

		$this->expectException( Filter_Field_Unavailable::class );
		$this->fields->value_kind( $this->definition( $type ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refused_types(): array {
		return array(
			// Its two flags invert the source of truth independently, and either can
			// be switched after products are saved.
			'taxonomy'         => array( 'taxonomy' ),

			// Stored as a bare id or as a serialised list, decided per value at save
			// time — so no single clause is right for the whole catalogue.
			'post_object'      => array( 'post_object' ),
			'relationship'     => array( 'relationship' ),
			'user'             => array( 'user' ),
			'file'             => array( 'file' ),
			'image'            => array( 'image' ),
			'gallery'          => array( 'gallery' ),
			'page_link'        => array( 'page_link' ),

			// Layout names live apart from the values they belong to.
			'flexible_content' => array( 'flexible_content' ),

			// Containers: the key holds a row count or a layout list, never a value.
			'repeater'         => array( 'repeater' ),
			'group'            => array( 'group' ),
			'clone'            => array( 'clone' ),

			'google_map'       => array( 'google_map' ),
			'link'             => array( 'link' ),
			'oembed'           => array( 'oembed' ),
			'message'          => array( 'message' ),
			'tab'              => array( 'tab' ),
		);
	}

	/**
	 * A type ACF gained after this module shipped is refused rather than guessed
	 * at. The default has to be "I do not know", because the alternative default is
	 * a clause built on an assumption nobody checked.
	 */
	public function test_an_unknown_type_is_refused_rather_than_guessed(): void {
		$this->expectException( Filter_Field_Unavailable::class );

		$this->fields->value_kind( $this->definition( 'some_future_acf_type' ) );
	}

	/**
	 * The refusal carries the field key. The repair path — "remove this condition
	 * and preview again" — needs the key rather than prose, because the rewritten
	 * filter is *wider* and the user has to be told which condition went.
	 */
	public function test_a_refusal_names_the_field(): void {
		try {
			$this->fields->value_kind( $this->definition( 'google_map', array( 'key' => 'field_abc123' ) ) );
			$this->fail( 'Expected a refusal.' );
		} catch ( Filter_Field_Unavailable $e ) {
			$this->assertSame( 'field_abc123', $e->field );
		}
	}

	/**
	 * Choices come back as value => label with both sides cast to string. The value
	 * is what a product's meta row holds; sending the label back as the operand
	 * would build a condition matching nothing.
	 */
	public function test_choices_are_returned_as_stored_value_to_label(): void {
		$choices = $this->fields->choices(
			$this->definition(
				'select',
				array( 'choices' => array( 'us' => 'United States', 'rs' => 'Serbia' ) )
			)
		);

		$this->assertSame( array( 'us' => 'United States', 'rs' => 'Serbia' ), $choices );
	}

	/**
	 * A field with no choices, or a malformed setting, is an empty list rather than
	 * a crash — the control renders either way.
	 */
	public function test_a_field_without_choices_yields_an_empty_list(): void {
		$this->assertSame( array(), $this->fields->choices( $this->definition( 'text' ) ) );
		$this->assertSame(
			array(),
			$this->fields->choices( $this->definition( 'select', array( 'choices' => 'not an array' ) ) )
		);
	}
}
