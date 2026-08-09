<?php
/**
 * Repository for the operations table.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Database\Schema;
use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Query\Filter;
use wpdb;

/**
 * CRUD and progress bookkeeping over {prefix}catalogops_operations. An operation
 * is created as a `draft` holding the filter and actions; the service later
 * freezes its targets and drives it through the lifecycle. Progress writes are
 * small, frequent updates from the chunk runner (counters plus the watchdog
 * heartbeat).
 */
final class Operations {

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
	 * Build the repository.
	 *
	 * @param wpdb   $wpdb   WordPress database handle.
	 * @param Schema $schema Schema owning the table name.
	 */
	public function __construct( wpdb $wpdb, Schema $schema ) {
		$this->wpdb   = $wpdb;
		$this->schema = $schema;
	}

	/**
	 * Create a draft operation from a filter and a list of actions.
	 *
	 * @param Filter                                  $filter       The target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions The actions to apply.
	 * @param Operation_Mode                          $mode         Write strategy.
	 * @param Operation_Source                        $source          Origin.
	 * @param int                                     $user_id         Owner user id.
	 * @param int|null                                $parent_op_id    Parent operation, for undo.
	 * @param Conflict_Policy|null                    $conflict_policy Drift policy (undo only).
	 * @return int The new operation id.
	 */
	public function create(
		Filter $filter,
		array $actions,
		Operation_Mode $mode,
		Operation_Source $source,
		int $user_id,
		?int $parent_op_id = null,
		?Conflict_Policy $conflict_policy = null
	): int {
		$this->wpdb->insert(
			$this->schema->operations_table(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'user_id'         => $user_id,
				'status'          => Operation_Status::DRAFT->value,
				'source'          => $source->value,
				'parent_op_id'    => $parent_op_id,
				'filter_json'     => (string) wp_json_encode( $filter->to_array() ),
				'actions_json'    => (string) wp_json_encode( Action_Factory::list_to_array( $actions ) ),
				'target_count'    => 0,
				'processed'       => 0,
				'failed'          => 0,
				'mode'            => $mode->value,
				'batch_size'      => 0,
				'conflict_policy' => null === $conflict_policy ? null : $conflict_policy->value,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find an operation by id.
	 *
	 * @param int $id Operation id.
	 */
	public function find( int $id ): ?Operation {
		$table = $this->schema->operations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * The most recent operations, newest first.
	 *
	 * @param int $limit How many to return.
	 * @return list<Operation>
	 */
	public function recent( int $limit = 20 ): array {
		$table = $this->schema->operations_table();
		$limit = max( 1, $limit );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * The oldest still-active operation other than the given one, or null.
	 * Used to enforce the single-writer lock (CONTEXT §3).
	 *
	 * @param int $exclude_id Operation id to exclude (the one asking).
	 */
	public function active_excluding( int $exclude_id ): ?Operation {
		$table = $this->schema->operations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ( 'queued', 'running' ) AND id <> %d ORDER BY id ASC LIMIT 1",
				$exclude_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * All operations whose heartbeat is older than a cutoff and are still
	 * running — the watchdog's candidates for a stalled-operation failure.
	 *
	 * @param string $cutoff_gmt MySQL datetime (GMT); rows with last_progress_at before this.
	 * @return list<Operation>
	 */
	public function stalled_before( string $cutoff_gmt ): array {
		$table = $this->schema->operations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'running' AND last_progress_at IS NOT NULL AND last_progress_at < %s ORDER BY id ASC",
				$cutoff_gmt
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Set the frozen target count once the filter has been resolved and seeded.
	 *
	 * @param int $id    Operation id.
	 * @param int $count Number of target objects.
	 */
	public function set_target_count( int $id, int $count ): void {
		$this->wpdb->update(
			$this->schema->operations_table(),
			array( 'target_count' => $count ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Record the adaptive batch size to use for the next chunk.
	 *
	 * @param int $id   Operation id.
	 * @param int $size Batch size.
	 */
	public function set_batch_size( int $id, int $size ): void {
		$this->wpdb->update(
			$this->schema->operations_table(),
			array( 'batch_size' => $size ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Move an operation to a new status, optionally stamping completed_at.
	 *
	 * @param int              $id     Operation id.
	 * @param Operation_Status $status New status.
	 * @param bool             $finish Whether to set completed_at to now.
	 */
	public function set_status( int $id, Operation_Status $status, bool $finish = false ): void {
		$data    = array( 'status' => $status->value );
		$formats = array( '%s' );

		if ( $finish ) {
			$data['completed_at'] = current_time( 'mysql', true );
			$formats[]            = '%s';
		}

		$this->wpdb->update(
			$this->schema->operations_table(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Atomically add to the processed/failed counters and refresh the heartbeat.
	 * Chunks run one at a time per operation (single-writer lock), but the
	 * increment form keeps the counters correct regardless.
	 *
	 * @param int $id             Operation id.
	 * @param int $processed_delta Objects processed in this chunk.
	 * @param int $failed_delta    Objects failed in this chunk.
	 */
	public function record_progress( int $id, int $processed_delta, int $failed_delta ): void {
		$table = $this->schema->operations_table();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET processed = processed + %d, failed = failed + %d, last_progress_at = %s WHERE id = %d",
				$processed_delta,
				$failed_delta,
				$now,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Refresh only the heartbeat (used when a chunk starts, before it has
	 * processed anything, so a slow first chunk does not look stalled).
	 *
	 * @param int $id Operation id.
	 */
	public function touch( int $id ): void {
		$this->wpdb->update(
			$this->schema->operations_table(),
			array( 'last_progress_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Build an Operation from a raw row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 */
	private function hydrate( array $row ): Operation {
		$filter_data  = json_decode( (string) $row['filter_json'], true );
		$actions_data = json_decode( (string) $row['actions_json'], true );

		return new Operation(
			(int) $row['id'],
			Operation_Status::from( (string) $row['status'] ),
			Operation_Source::from( (string) $row['source'] ),
			Operation_Mode::from( (string) $row['mode'] ),
			(int) $row['user_id'],
			null === $row['parent_op_id'] ? null : (int) $row['parent_op_id'],
			is_array( $filter_data ) ? $filter_data : array(),
			is_array( $actions_data ) ? $actions_data : array(),
			(int) $row['target_count'],
			(int) $row['processed'],
			(int) $row['failed'],
			(int) $row['batch_size'],
			(string) $row['created_at'],
			null === $row['completed_at'] ? null : (string) $row['completed_at'],
			null === $row['last_progress_at'] ? null : (string) $row['last_progress_at'],
			empty( $row['conflict_policy'] ) ? null : Conflict_Policy::from( (string) $row['conflict_policy'] ),
		);
	}
}
