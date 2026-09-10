<?php
/**
 * Unit tests for the language a filter carries.
 *
 * The engine's refusal lives here rather than in the integration suite because
 * "this site has no translations table" is exactly what a `wpdb` with no database
 * behind it says, and saying it in the integration suite would mean dropping a
 * table inside a transaction the test framework is relying on.
 *
 * @package CatalogOps\Tests\Unit\Query
 */

namespace CatalogOps\Tests\Unit\Query;

use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * @covers \CatalogOps\Query\Filter
 * @covers \CatalogOps\Query\Query_Engine
 */
final class FilterLanguageTest extends TestCase {

	/**
	 * Every spelling of "no confinement" has to collapse onto one, or the engine,
	 * the history and the schedules list each have three things to know and one of
	 * them will eventually know two.
	 *
	 * @dataProvider unconfined_languages
	 *
	 * @param string|null $given What arrives.
	 */
	public function test_every_spelling_of_no_confinement_becomes_null( ?string $given ): void {
		$this->assertNull( ( new Filter( array(), Filter::RELATION_AND, null, $given ) )->language() );
	}

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public function unconfined_languages(): array {
		return array(
			'null'            => array( null ),
			'empty string'    => array( '' ),
			'whitespace'      => array( '   ' ),
			'all'             => array( 'all' ),
			'ALL'             => array( 'ALL' ),
			'all with spaces' => array( ' all ' ),
		);
	}

	public function test_a_real_language_is_kept_as_it_arrives(): void {
		$this->assertSame( 'sr', ( new Filter( array(), Filter::RELATION_AND, null, 'sr' ) )->language() );
		$this->assertSame( 'pt-br', ( new Filter( array(), Filter::RELATION_AND, null, ' pt-br ' ) )->language() );
	}

	public function test_the_language_is_serialized_even_when_it_is_null(): void {
		$this->assertArrayHasKey( 'language', ( new Filter() )->to_array() );
		$this->assertNull( ( new Filter() )->to_array()['language'] );
		$this->assertSame( 'sr', ( new Filter( array(), Filter::RELATION_AND, null, 'sr' ) )->to_array()['language'] );
	}

	/**
	 * A filter written before the language existed carries no key at all, and has to
	 * rebuild as the unconfined filter it was.
	 */
	public function test_a_filter_with_no_language_key_rebuilds_unconfined(): void {
		$this->assertNull( Filter::from_array( array( 'relation' => 'AND', 'scope' => 'product' ) )->language() );
		$this->assertNull( Filter::from_array( array( 'language' => null ) )->language() );
		$this->assertNull( Filter::from_array( array( 'language' => '' ) )->language() );
		$this->assertSame( 'sr', Filter::from_array( array( 'language' => 'sr' ) )->language() );
	}

	public function test_the_language_survives_both_immutable_copies(): void {
		$filter = new Filter( array(), Filter::RELATION_AND, Query_Scope::PRODUCT, 'sr' );

		$this->assertSame( 'sr', $filter->for_scope( Query_Scope::VARIATION )->language() );
	}

	/**
	 * A filter can outlive WPML: a saved filter, a schedule and a frozen operation
	 * all persist a language, and the plugin that gave it meaning can be switched
	 * off afterwards. Running unconfined instead would be the one unrecoverable
	 * failure — a schedule written for one language repricing the whole shop — so
	 * the engine stops.
	 */
	public function test_a_language_is_refused_when_the_translations_table_is_gone(): void {
		// A `wpdb` that answers nothing: `get_var()` returns null, so the probe finds
		// no table, which is precisely the state of a site whose WPML has gone.
		$engine = new Query_Engine( $this->createStub( wpdb::class ) );

		$this->expectException( Filter_Field_Unavailable::class );
		$this->expectExceptionMessage( 'no longer has WPML installed' );

		$engine->count_sql( new Filter( array(), Filter::RELATION_AND, null, 'sr' ) );
	}
}
