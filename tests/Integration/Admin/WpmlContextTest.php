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
