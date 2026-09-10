<?php
/**
 * Integration tests for the history and the schedules list being confined to the
 * language the user is working in.
 *
 * The two are one rule, written once in each repository and asserted together
 * here — a list and the count that pages it, and a NULL that has to stay visible
 * everywhere. WPML is not needed for any of it: what is stored is a language code
 * copied off the filter, and what is asserted is which rows come back.
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Actions\Set_Value;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Operation_Source;
use CatalogOps\Operations\Operations;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedules;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Scope;
use WP_REST_Request;

/**
 * @covers \CatalogOps\Operations\Operations
 * @covers \CatalogOps\Operations\Schedules
 * @covers \CatalogOps\Rest\Schedules_Controller
 */
final class ListedByLanguageTest extends Operations_Database_Case {

	private Operations $operations;
	private Schedules $schedules;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->operations = new Operations( $wpdb, $this->schema );
		$this->schedules  = new Schedules( $wpdb, $this->schema );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	// -- the history -------------------------------------------------------

	public function test_a_runs_language_is_taken_from_its_own_filter(): void {
		$id = $this->run_in( 'sr' );

		$this->assertSame( 'sr', $this->operations->find( $id )->language );
		$this->assertSame( 'sr', $this->operations->find( $id )->filter()->language() );
	}

	public function test_a_run_that_was_not_confined_stores_no_language(): void {
		$this->assertNull( $this->operations->find( $this->run_in( null ) )->language );

		// "All languages" is not a language, and must not be stored as one.
		$this->assertNull( $this->operations->find( $this->run_in( 'all' ) )->language );
	}

	public function test_the_history_shows_one_language_plus_the_runs_that_have_none(): void {
		$english     = $this->run_in( 'en' );
		$serbian     = $this->run_in( 'sr' );
		$unconfined  = $this->run_in( null );

		$this->assertSame(
			array( $unconfined, $serbian ),
			$this->ids( $this->operations->recent( 50, 0, 'sr' ) ),
			'Serbian sees its own runs and the ones belonging to no language'
		);

		$this->assertSame(
			array( $unconfined, $english ),
			$this->ids( $this->operations->recent( 50, 0, 'en' ) )
		);

		$this->assertCount( 3, $this->operations->recent( 50, 0, null ) );
	}

	/**
	 * A count that disagreed with the list would page past the end of it and show
	 * the user empty pages they cannot explain. The two take the same argument and
	 * this is what says they honour it the same way.
	 */
	public function test_the_count_agrees_with_the_list_it_pages(): void {
		$this->run_in( 'en' );
		$this->run_in( 'sr' );
		$this->run_in( 'sr' );
		$this->run_in( null );

		foreach ( array( null, 'en', 'sr' ) as $language ) {
			$this->assertSame(
				count( $this->operations->recent( 50, 0, $language ) ),
				$this->operations->count_all( $language ),
				sprintf( 'count and list disagree for %s', $language ?? 'no language' )
			);
		}

		$this->assertSame( 4, $this->operations->count_all( null ) );
		$this->assertSame( 3, $this->operations->count_all( 'sr' ) );
		$this->assertSame( 2, $this->operations->count_all( 'en' ) );
	}

	/**
	 * The rule that keeps an upgrade from looking like a disaster. Every run made
	 * before migration 10 has a NULL language, and a history that hid them would
	 * be empty the first time a bilingual shop opened it after updating.
	 */
	public function test_a_run_from_before_the_column_existed_is_visible_in_every_language(): void {
		global $wpdb;

		$id = $this->run_in( 'en' );

		// Exactly what such a row looks like: the column present but never written.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'UPDATE ' . $this->schema->operations_table() . ' SET language = NULL WHERE id = %d',
				$id
			)
		);

		$this->assertSame( array( $id ), $this->ids( $this->operations->recent( 50, 0, 'sr' ) ) );
		$this->assertSame( array( $id ), $this->ids( $this->operations->recent( 50, 0, 'en' ) ) );
	}

	// -- the schedules -----------------------------------------------------

	public function test_a_schedules_language_is_taken_from_its_own_filter(): void {
		$id = $this->schedule_in( 'sr' );

		$this->assertSame( 'sr', $this->schedules->find( $id )->language );
		$this->assertNull( $this->schedules->find( $this->schedule_in( null ) )->language );
		$this->assertNull( $this->schedules->find( $this->schedule_in( 'all' ) )->language );
	}

	public function test_the_schedules_list_shows_one_language_plus_the_ones_with_none(): void {
		$english    = $this->schedule_in( 'en' );
		$serbian    = $this->schedule_in( 'sr' );
		$unconfined = $this->schedule_in( null );

		$this->assertSame(
			array( $unconfined, $serbian ),
			$this->ids( $this->schedules->all( 50, 0, 'sr' ) )
		);

		$this->assertSame( 2, $this->schedules->count_all( 'sr' ) );
		$this->assertSame( 2, $this->schedules->count_all( 'en' ) );
		$this->assertSame( 3, $this->schedules->count_all( null ) );

		$this->assertContains( $english, $this->ids( $this->schedules->all( 50, 0, 'en' ) ) );
	}

	/**
	 * **The one that matters most on this branch.** A cron tick has no language of
	 * its own, so a due list narrowed by one would leave every schedule outside the
	 * site's default language never firing — silently, and for as long as nobody
	 * noticed. What a run touches is decided by the language inside its own frozen
	 * filter, which is where it has always been decided.
	 */
	public function test_every_language_still_fires_because_due_knows_nothing_about_them(): void {
		$this->schedule_in( 'en' );
		$this->schedule_in( 'sr' );
		$this->schedule_in( null );

		$due = $this->schedules->due( gmdate( 'Y-m-d H:i:s' ) );

		$this->assertCount( 3, $due, 'a due schedule fires whatever language it belongs to' );

		// And each one still carries its own confinement into the run it makes.
		$languages = array_map(
			static fn( $schedule ): ?string => $schedule->filter()->language(),
			$due
		);

		$this->assertEqualsCanonicalizing( array( 'en', 'sr', null ), $languages );
	}

	// -- the route ---------------------------------------------------------

	public function test_the_schedules_route_honours_the_language_and_says_which(): void {
		$this->schedule_in( 'en' );
		$serbian = $this->schedule_in( 'sr' );

		$request = new WP_REST_Request( 'GET', '/catalogops/v1/schedules' );
		$request->set_param( 'language', 'sr' );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( $serbian, $data['items'][0]['id'] );
		$this->assertSame( 'sr', $data['items'][0]['language'] );
	}

	public function test_the_schedules_route_refuses_a_language_that_is_not_one(): void {
		$request = new WP_REST_Request( 'GET', '/catalogops/v1/schedules' );
		$request->set_param( 'language', "sr' OR 1=1" );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	// -- helpers -----------------------------------------------------------

	/**
	 * The ids of a list of read-models, in the order they came back.
	 *
	 * @param array<int, object> $rows Operations or schedules.
	 * @return list<int>
	 */
	private function ids( array $rows ): array {
		return array_map( static fn( $row ): int => $row->id, $rows );
	}

	/**
	 * A filter confined to a language, or to none.
	 *
	 * @param string|null $language Language code, 'all', or null.
	 */
	private function filter_in( ?string $language ): Filter {
		return new Filter( array(), Filter::RELATION_AND, Query_Scope::PRODUCT, $language );
	}

	/**
	 * An operation whose filter is confined to a language.
	 *
	 * @param string|null $language Language code, 'all', or null.
	 */
	private function run_in( ?string $language ): int {
		return $this->operations->create(
			$this->filter_in( $language ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Operation_Source::UI,
			1
		);
	}

	/**
	 * A schedule, already due, whose filter is confined to a language.
	 *
	 * @param string|null $language Language code, 'all', or null.
	 */
	private function schedule_in( ?string $language ): int {
		return $this->schedules->create(
			'Nightly ' . ( $language ?? 'everything' ),
			$this->filter_in( $language ),
			array( new Set_Value( 'regular_price', '9.99' ) ),
			Operation_Mode::SAFE,
			Recurrence::DAILY,
			gmdate( 'Y-m-d H:i:s', time() - 3600 ),
			'',
			1
		);
	}
}
