<?php
/**
 * Integration tests for the saved-filters repository.
 *
 * @package CatalogOps\Tests\Integration\Query
 */

namespace CatalogOps\Tests\Integration\Query;

use CatalogOps\Database\Schema;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Saved_Filters;
use WP_UnitTestCase;

/**
 * The table is created once as a real table before the per-test transactions,
 * so each test's writes roll back cleanly for isolation.
 *
 * @covers \CatalogOps\Query\Saved_Filters
 * @covers \CatalogOps\Query\Saved_Filter
 */
final class SavedFiltersTest extends WP_UnitTestCase {

	private Saved_Filters $repository;
	private Query_Engine $engine;

	/**
	 * @param \WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		global $wpdb;
		( new Schema( $wpdb ) )->install();
	}

	public static function wpTearDownAfterClass(): void {
		global $wpdb;
		( new Schema( $wpdb ) )->drop();
	}

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->repository = new Saved_Filters( $wpdb, new Schema( $wpdb ) );
		$this->engine     = new Query_Engine( $wpdb );
	}

	public function test_create_and_find_round_trips_the_filter(): void {
		$filter = new Filter( array( new Condition( 'price', Operator::GREATER_THAN, 20 ) ) );

		$id = $this->repository->create( 'Expensive', $filter, 7 );
		$this->assertGreaterThan( 0, $id );

		$saved = $this->repository->find( $id );
		$this->assertNotNull( $saved );
		$this->assertSame( 'Expensive', $saved->name );
		$this->assertSame( 7, $saved->user_id );
		$this->assertEquals( $filter->to_array(), $saved->filter()->to_array() );
	}

	public function test_update_changes_name_and_filter(): void {
		$id  = $this->repository->create( 'Before', new Filter(), 1 );
		$new = new Filter( array( new Condition( 'stock_status', Operator::EQUALS, 'instock' ) ) );

		$this->assertTrue( $this->repository->update( $id, 'After', $new ) );

		$saved = $this->repository->find( $id );
		$this->assertSame( 'After', $saved->name );
		$this->assertEquals( $new->to_array(), $saved->filter()->to_array() );
	}

	public function test_delete_removes_the_row(): void {
		$id = $this->repository->create( 'Temp', new Filter(), 1 );

		$this->assertTrue( $this->repository->delete( $id ) );
		$this->assertNull( $this->repository->find( $id ) );
	}

	public function test_for_user_returns_only_that_users_filters_sorted_by_name(): void {
		$this->repository->create( 'Zebra', new Filter(), 100 );
		$this->repository->create( 'Alpha', new Filter(), 100 );
		$this->repository->create( 'Other', new Filter(), 200 );

		$names = array_map(
			static fn( $saved ): string => $saved->name,
			$this->repository->for_user( 100 )
		);

		$this->assertSame( array( 'Alpha', 'Zebra' ), $names );
	}

	public function test_find_returns_null_for_a_missing_id(): void {
		$this->assertNull( $this->repository->find( 999999 ) );
	}

	/**
	 * Pins the load/run line the whole refusal design rests on: a saved filter
	 * naming a field the engine cannot answer still comes back from the list with
	 * its name intact and its condition rebuilt, so a human can open it and repair
	 * it — and running it is the only thing that refuses.
	 *
	 * The second half is what 0.7.1 got wrong, and silently: `clause_for()` ended
	 * in `return array( '', array() )` for a field it did not recognise,
	 * `build_where()` skips an empty fragment, and so counting this filter answered
	 * for the whole catalogue instead of refusing — a strictly wider set that
	 * preview and run agreed on perfectly.
	 *
	 * The first half is why `Saved_Filter` now keeps `filter_data` and rebuilds via
	 * `filter()` rather than holding a hydrated `Filter`: `for_user()` maps over
	 * every row, so anything hydration can throw on takes the entire list with it
	 * instead of just the one filter that needs fixing.
	 */
	public function test_a_saved_filter_naming_a_field_the_engine_cannot_answer_still_loads(): void {
		$filter = new Filter( array( new Condition( 'acf:clearance', Operator::EQUALS, '1' ) ) );

		$id = $this->repository->create( 'Clearance', $filter, 42 );

		$rows = $this->repository->for_user( 42 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $id, $rows[0]->id );
		$this->assertSame( 'Clearance', $rows[0]->name );

		$conditions = $rows[0]->filter()->conditions();
		$this->assertCount( 1, $conditions );
		$this->assertSame( 'acf:clearance', $conditions[0]->field );
		$this->assertSame( Operator::EQUALS, $conditions[0]->operator );
		$this->assertSame( '1', $conditions[0]->value );

		// Loaded, listed and editable — and refused the moment it is run.
		$this->expectException( Filter_Field_Unavailable::class );
		$this->engine->count( $rows[0]->filter() );
	}
}
