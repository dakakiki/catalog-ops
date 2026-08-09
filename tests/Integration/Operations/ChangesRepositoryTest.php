<?php
/**
 * Integration tests for the changes repository (seed + idempotent claims).
 *
 * @package CatalogOps\Tests\Integration\Operations
 */

namespace CatalogOps\Tests\Integration\Operations;

use CatalogOps\Operations\Change_Status;
use CatalogOps\Operations\Changes;
use CatalogOps\Operations\Fields\Field_Type;

/**
 * @covers \CatalogOps\Operations\Changes
 * @covers \CatalogOps\Operations\Change
 */
final class ChangesRepositoryTest extends Operations_Database_Case {

	private Changes $changes;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->changes = new Changes( $wpdb, $this->schema );
	}

	public function test_seed_inserts_pending_rows(): void {
		$inserted = $this->changes->seed( 42, $this->rows_for( array( 101, 102, 103 ) ) );

		$this->assertSame( 3, $inserted );
		$this->assertSame( 3, $this->changes->pending_count( 42 ) );
	}

	public function test_seed_handles_more_than_one_batch(): void {
		// 2,500 rows spans three SEED_CHUNK inserts.
		$ids = range( 1, 2500 );

		$inserted = $this->changes->seed( 7, $this->rows_for( $ids ) );

		$this->assertSame( 2500, $inserted );
		$this->assertSame( 2500, $this->changes->pending_count( 7 ) );
	}

	public function test_pending_chunk_is_ordered_and_limited(): void {
		$this->changes->seed( 1, $this->rows_for( array( 30, 10, 20 ) ) );

		$chunk = $this->changes->pending_chunk( 1, 2 );

		$this->assertCount( 2, $chunk );
		// Ordered by object_id ascending.
		$this->assertSame( 10, $chunk[0]->object_id );
		$this->assertSame( 20, $chunk[1]->object_id );
		$this->assertSame( Field_Type::POST_FIELD, $chunk[0]->field_type );
		$this->assertSame( 'regular_price', $chunk[0]->field_key );
		$this->assertSame( Change_Status::PENDING, $chunk[0]->status );
	}

	public function test_mark_applied_claims_the_row_only_once(): void {
		$this->changes->seed( 5, $this->rows_for( array( 200 ) ) );
		$id = $this->changes->pending_chunk( 5, 1 )[0]->id;

		// First claim wins and records the values.
		$this->assertTrue( $this->changes->mark_applied( $id, '19.90', '9.99' ) );

		// A re-run (e.g. after a watchdog failure) claims nothing.
		$this->assertFalse( $this->changes->mark_applied( $id, '19.90', '9.99' ) );

		$this->assertSame( 0, $this->changes->pending_count( 5 ) );

		$counts = $this->changes->counts( 5 );
		$this->assertSame( 1, $counts['applied'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	public function test_mark_failed_and_skipped_are_guarded(): void {
		$this->changes->seed( 9, $this->rows_for( array( 300, 301 ) ) );
		$rows = $this->changes->pending_chunk( 9, 2 );

		$this->assertTrue( $this->changes->mark_failed( $rows[0]->id, '5.00' ) );
		$this->assertTrue( $this->changes->mark_skipped( $rows[1]->id ) );

		// Neither can be re-claimed.
		$this->assertFalse( $this->changes->mark_failed( $rows[0]->id ) );
		$this->assertFalse( $this->changes->mark_applied( $rows[1]->id, 'a', 'b' ) );

		$counts = $this->changes->counts( 9 );
		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( 1, $counts['skipped'] );
		$this->assertSame( 0, $counts['pending'] );
	}

	public function test_applied_row_stores_old_and_new_values(): void {
		$this->changes->seed( 11, $this->rows_for( array( 400 ) ) );
		$id = $this->changes->pending_chunk( 11, 1 )[0]->id;

		$this->changes->mark_applied( $id, '19.90', '9.99' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT old_value, new_value, status FROM {$this->schema->changes_table()} WHERE id = %d", $id ),
			ARRAY_A
		);

		$this->assertSame( '19.90', $row['old_value'] );
		$this->assertSame( '9.99', $row['new_value'] );
		$this->assertSame( (string) Change_Status::APPLIED->value, $row['status'] );
	}

	public function test_seed_from_parent_copies_only_applied_rows_as_pending(): void {
		// Parent op 20 has three targets; two get applied, one is skipped.
		$this->changes->seed( 20, $this->rows_for( array( 500, 501, 502 ) ) );
		$rows = $this->changes->pending_chunk( 20, 10 );
		$this->changes->mark_applied( $rows[0]->id, '19.90', '9.99' );
		$this->changes->mark_applied( $rows[1]->id, '25.00', '9.99' );
		$this->changes->mark_skipped( $rows[2]->id );

		// Undo op 21 freezes its targets from the parent's applied deltas.
		$seeded = $this->changes->seed_from_parent( 21, 20 );

		$this->assertSame( 2, $seeded );
		$this->assertSame( 2, $this->changes->pending_count( 21 ) );

		// The undo rows carry the parent's object+field identity, values still null.
		$undo = $this->changes->pending_chunk( 21, 10 );
		$this->assertSame( array( 500, 501 ), array_map( static fn( $c ) => $c->object_id, $undo ) );
		$this->assertSame( 'regular_price', $undo[0]->field_key );
		$this->assertNull( $undo[0]->old_value );
		$this->assertNull( $undo[0]->new_value );
		$this->assertSame( Change_Status::PENDING, $undo[0]->status );
	}

	public function test_applied_index_maps_object_and_field_to_the_delta(): void {
		$this->changes->seed( 30, $this->rows_for( array( 600, 601 ) ) );
		$rows = $this->changes->pending_chunk( 30, 10 );
		$this->changes->mark_applied( $rows[0]->id, '19.90', '9.99' );
		$this->changes->mark_applied( $rows[1]->id, '30.00', '9.99' );

		$index = $this->changes->applied_index( 30, array( 600, 601, 999 ) );

		$this->assertArrayHasKey( '600|post_field|regular_price', $index );
		$this->assertSame( '19.90', $index['600|post_field|regular_price']['old'] );
		$this->assertSame( '9.99', $index['600|post_field|regular_price']['new'] );
		$this->assertSame( '30.00', $index['601|post_field|regular_price']['old'] );
		// An object with no applied delta is simply absent.
		$this->assertArrayNotHasKey( '999|post_field|regular_price', $index );
	}

	public function test_applied_index_is_empty_for_no_objects(): void {
		$this->assertSame( array(), $this->changes->applied_index( 30, array() ) );
	}

	public function test_applied_sample_returns_applied_rows_oldest_object_first(): void {
		$this->changes->seed( 40, $this->rows_for( array( 720, 700, 710 ) ) );
		$rows = $this->changes->pending_chunk( 40, 10 );
		foreach ( $rows as $row ) {
			$this->changes->mark_applied( $row->id, '10.00', '1.00' );
		}

		$sample = $this->changes->applied_sample( 40, 2 );

		$this->assertCount( 2, $sample );
		$this->assertSame( 700, $sample[0]->object_id );
		$this->assertSame( 710, $sample[1]->object_id );
		$this->assertSame( '10.00', $sample[0]->old_value );
	}

	public function test_page_returns_rows_for_the_audit_view(): void {
		$this->changes->seed( 50, $this->rows_for( array( 800, 801, 802 ) ) );

		$first = $this->changes->page( 50, 2, 0 );
		$this->assertCount( 2, $first );

		$second = $this->changes->page( 50, 2, 2 );
		$this->assertCount( 1, $second );
	}

	/**
	 * Build seed rows for a list of object ids, all targeting regular_price.
	 *
	 * @param int[] $object_ids Object ids.
	 * @return array<int, array{object_id: int, field_type: string, field_key: string}>
	 */
	private function rows_for( array $object_ids ): array {
		return array_map(
			static fn( int $id ): array => array(
				'object_id'  => $id,
				'field_type' => Field_Type::POST_FIELD->value,
				'field_key'  => 'regular_price',
			),
			$object_ids
		);
	}
}
