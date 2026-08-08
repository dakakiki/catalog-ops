<?php
/**
 * Repository for named, reusable filters.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

use CatalogOps\Database\Schema;
use wpdb;

/**
 * CRUD over the saved_filters table. Filters are stored as filter_json (the
 * same shape {@see Filter::to_array()} produces) and rehydrated on read.
 */
final class Saved_Filters {

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Schema, for the table name.
	 *
	 * @var Schema
	 */
	private Schema $schema;

	/**
	 * Build the repository over a database handle and schema.
	 *
	 * @param wpdb   $wpdb   WordPress database handle.
	 * @param Schema $schema Schema owning the table name.
	 */
	public function __construct( wpdb $wpdb, Schema $schema ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
	}

	/**
	 * Store a new named filter and return its id.
	 *
	 * @param string $name    Filter name.
	 * @param Filter $filter  The filter to store.
	 * @param int    $user_id Owner user id.
	 */
	public function create( string $name, Filter $filter, int $user_id = 0 ): int {
		$now = current_time( 'mysql', true );

		$this->wpdb->insert(
			$this->schema->saved_filters_table(),
			array(
				'user_id'     => $user_id,
				'name'        => $name,
				'filter_json' => (string) wp_json_encode( $filter->to_array() ),
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Overwrite the name and filter of an existing row.
	 *
	 * @param int    $id     Row id.
	 * @param string $name   New name.
	 * @param Filter $filter New filter.
	 * @return bool Whether a row was updated.
	 */
	public function update( int $id, string $name, Filter $filter ): bool {
		$updated = $this->wpdb->update(
			$this->schema->saved_filters_table(),
			array(
				'name'        => $name,
				'filter_json' => (string) wp_json_encode( $filter->to_array() ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Find a saved filter by id.
	 *
	 * @param int $id Row id.
	 */
	public function find( int $id ): ?Saved_Filter {
		$table = $this->schema->saved_filters_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * All saved filters owned by a user, ordered by name.
	 *
	 * @param int $user_id Owner user id.
	 * @return list<Saved_Filter>
	 */
	public function for_user( int $user_id ): array {
		$table = $this->schema->saved_filters_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY name ASC", $user_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Delete a saved filter.
	 *
	 * @param int $id Row id.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete(
			$this->schema->saved_filters_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Build a Saved_Filter from a raw row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 */
	private function hydrate( array $row ): Saved_Filter {
		$data   = json_decode( (string) $row['filter_json'], true );
		$filter = is_array( $data ) ? Filter::from_array( $data ) : new Filter();

		return new Saved_Filter(
			(int) $row['id'],
			(int) $row['user_id'],
			(string) $row['name'],
			$filter,
			(string) $row['created_at'],
			(string) $row['updated_at'],
		);
	}
}
