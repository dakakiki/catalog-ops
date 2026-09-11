<?php
/**
 * Integration tests for reading the admin's language off WPML.
 *
 * WPML is not installed here and does not need to be: everything this class asks
 * goes through WPML's published filters, so a test that adds listeners to them is
 * standing exactly where WPML would stand.
 *
 * @package CatalogOps\Tests\Integration\Admin
 */

namespace CatalogOps\Tests\Integration\Admin;

use CatalogOps\Admin\Wpml_Context;
use WP_UnitTestCase;

/**
 * @covers \CatalogOps\Admin\Wpml_Context
 */
final class WpmlContextTest extends WP_UnitTestCase {

	private Wpml_Context $context;

	public function set_up(): void {
		parent::set_up();

		$this->context = new Wpml_Context();
	}

	/**
	 * The overwhelmingly common case, and the one that has to cost nothing: a shop
	 * with no WPML gets no language, no indicator, and the behaviour it had before
	 * any of this existed.
	 */
	public function test_a_site_without_wpml_reports_no_language_at_all(): void {
		$this->assertFalse( $this->context->is_active() );
		$this->assertNull( $this->context->current_code() );
		$this->assertNull( $this->context->to_array() );
	}

	public function test_the_current_language_is_read_from_wpmls_own_filter(): void {
		$this->speak( 'sr' );

		$this->assertTrue( $this->context->is_active() );
		$this->assertSame( 'sr', $this->context->current_code() );
	}

	/**
	 * WPML's "All languages" really is the literal string `all`, and it means the
	 * opposite of a language: place no constraint. It has to collapse to null here,
	 * because null is what every other part of this plugin reads as "no constraint".
	 *
	 * If it did not, the code would travel into filter_json and the engine would
	 * join `language_code = 'all'`, which matches nothing — a filter that silently
	 * finds zero products on the one setting that means "find them all".
	 */
	public function test_all_languages_is_active_but_confines_nothing(): void {
		$this->speak( 'all' );

		$this->assertTrue( $this->context->is_active() );
		$this->assertNull( $this->context->current_code() );

		// Present, so the app knows WPML is there and draws the indicator; with a
		// null code, so it sends no language and says "All languages".
		$this->assertSame( array( 'code' => null, 'label' => null ), $this->context->to_array() );
	}

	/**
	 * A language's own name for itself, because that is what WPML's switcher shows
	 * two menus away, and a user should not have to work out that "Serbian" and
	 * "Srpski" are the same thing.
	 */
	public function test_the_label_is_the_languages_own_name_for_itself(): void {
		$this->speak( 'sr' );
		$this->name_languages(
			array(
				'sr' => array(
					'native_name'     => 'Srpski',
					'translated_name' => 'Serbian',
				),
			)
		);

		$this->assertSame( 'Srpski', $this->context->current_label() );
	}

	/**
	 * An indicator that renders empty is worse than one that renders a code: the
	 * user can look up `sr`, and cannot look up nothing.
	 */
	public function test_the_label_falls_back_to_the_code_rather_than_to_nothing(): void {
		$this->speak( 'sr' );

		$this->assertSame( 'sr', $this->context->current_label() );

		$this->name_languages( array( 'en' => array( 'native_name' => 'English' ) ) );

		$this->assertSame( 'sr', $this->context->current_label() );
	}

	public function test_the_whole_answer_is_the_shape_the_app_is_handed(): void {
		$this->speak( 'sr' );
		$this->name_languages( array( 'sr' => array( 'native_name' => 'Srpski' ) ) );

		$this->assertSame(
			array(
				'code'  => 'sr',
				'label' => 'Srpski',
			),
			$this->context->to_array()
		);
	}

	/**
	 * A translated term is a different row with a different id — Accessories is 18
	 * in English and 73 in Serbian on the development catalogue — so a picker built
	 * in one language hands the engine ids no product in the other carries. Nothing
	 * about the empty result that follows looks like a bug.
	 */
	public function test_a_term_is_mapped_into_the_requested_language(): void {
		$this->speak( 'en' );
		$this->translates( 'product_cat' );
		$this->translate_terms( array( 18 => 73 ) );

		$this->assertSame( 73, $this->context->term_in_language( 18, 'product_cat', 'sr' ) );
	}

	/**
	 * The list a language gets is that language's list. A term nobody has
	 * translated labels nothing there and could match nothing there, and
	 * translating it is the shop's business rather than this control's to disguise.
	 */
	public function test_a_term_with_no_translation_is_dropped_rather_than_substituted(): void {
		$this->speak( 'en' );
		$this->translates( 'product_cat' );
		$this->translate_terms( array( 18 => 73 ) );

		$this->assertNull( $this->context->term_in_language( 99, 'product_cat', 'sr' ) );
	}

	/**
	 * The overwhelmingly common case has to cost nothing and change nothing: with
	 * no language asked for, or no WPML at all, a term is itself.
	 */
	public function test_a_term_is_untouched_without_a_language_or_without_wpml(): void {
		$this->assertSame( 18, $this->context->term_in_language( 18, 'product_cat', null ) );

		// WPML absent: even a language cannot make this map anything.
		$this->assertSame( 18, $this->context->term_in_language( 18, 'product_cat', 'sr' ) );
	}

	/**
	 * A taxonomy WPML does not translate has one shared set of terms, so a term in
	 * it is already the answer in every language. WooCommerce's `product_brand` is
	 * this on a default install — it is absent from `taxonomies_sync_option`
	 * entirely on the development site — and mapping it anyway would empty the
	 * brand control in every language but the default.
	 */
	public function test_an_untranslated_taxonomy_maps_nothing_and_loses_nothing(): void {
		$this->speak( 'en' );
		$this->translates( 'product_cat' );
		$this->translate_terms( array( 18 => 73 ) );

		// product_brand is not in the translated list, so its term survives as itself
		// rather than being dropped for having no translation.
		$this->assertSame( 51, $this->context->term_in_language( 51, 'product_brand', 'sr' ) );
	}

	/**
	 * WPML stores an attribute taxonomy's setting as the STRING '1' — measured in
	 * `taxonomies_sync_option` on the development site, where `pa_color` and
	 * `pa_size` are `'1'` while `product_cat` is the integer `1`. Both mean
	 * translated, and a test that only ever saw one of them would let the other
	 * silently stop mapping.
	 */
	public function test_a_taxonomy_whose_setting_is_a_string_still_maps(): void {
		$this->speak( 'en' );

		add_filter(
			'wpml_is_translated_taxonomy',
			static fn( $answer, $taxonomy ) => 'pa_color' === $taxonomy ? '1' : $answer,
			10,
			2
		);
		$this->translate_terms( array( 30 => 90 ) );

		$this->assertSame( 90, $this->context->term_in_language( 30, 'pa_color', 'sr' ) );
	}

	/**
	 * Stand where WPML stands on `wpml_is_translated_taxonomy`.
	 *
	 * **It answers `1`, and the integer is the point.** WPML's answer comes from
	 * `icl_get_sub_setting( 'taxonomies_sync_option', $taxonomy )`, which returns
	 * whatever is stored — the integer `1` for `product_cat`, the *string* `'1'`
	 * for an attribute taxonomy. A stand-in that returned a real boolean made an
	 * identity test pass here and map nothing at all against real WPML, where the
	 * Serbian category picker came back holding the English ids. A stub politer
	 * than the thing it stands for tests the stub.
	 *
	 * @param string ...$taxonomies The taxonomies WPML translates.
	 */
	private function translates( string ...$taxonomies ): void {
		add_filter(
			'wpml_is_translated_taxonomy',
			static fn( $answer, $taxonomy ) => in_array( $taxonomy, $taxonomies, true ) ? 1 : $answer,
			10,
			2
		);
	}

	/**
	 * Stand where WPML stands on `wpml_object_id`.
	 *
	 * WPML's own signature is ( $id, $type, $return_original_if_missing, $lang ),
	 * and this listener honours the third argument by ignoring it — the caller
	 * passes false, and a stand-in that quietly returned the original anyway would
	 * make the test above pass for the wrong reason.
	 *
	 * @param array<int, int> $map Source term id => translated term id.
	 */
	private function translate_terms( array $map ): void {
		add_filter(
			'wpml_object_id',
			static fn( $id ) => $map[ (int) $id ] ?? null,
			10,
			4
		);
	}

	/**
	 * Stand where WPML stands on `wpml_current_language`.
	 *
	 * @param string $code The language WPML would report.
	 */
	private function speak( string $code ): void {
		add_filter( 'wpml_current_language', static fn(): string => $code );
	}

	/**
	 * Stand where WPML stands on `wpml_active_languages`.
	 *
	 * @param array<string, array<string, string>> $languages Code => language data.
	 */
	private function name_languages( array $languages ): void {
		add_filter( 'wpml_active_languages', static fn(): array => $languages );
	}
}
