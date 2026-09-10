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
	 * @param int|null                                $schedule_id     Schedule that spawned this run.
	 * @param int|null                                $parent_op_id    Parent operation, for undo.
	 * @param Conflict_Policy|null                    $conflict_policy Drift policy (undo only).
	 * @param string|null                             $schedule_name   What the schedule was called
	 *                                                                 at this moment; stored rather
	 *                                                                 than looked up, so deleting or
	 *                                                                 renaming the schedule cannot
	 *                                                                 rewrite this run's history.
	 * @param string|null                             $note            The user's reason for the run.
	 * @return int The new operation id.
	 */
	public function create(
		Filter $filter,
		array $actions,
		Operation_Mode $mode,
		Operation_Source $source,
		int $user_id,
		?int $schedule_id = null,
		?int $parent_op_id = null,
		?Conflict_Policy $conflict_policy = null,
		?string $schedule_name = null,
		?string $note = null
	): int {
		$this->wpdb->insert(
			$this->schema->operations_table(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'user_id'         => $user_id,
				'status'          => Operation_Status::DRAFT->value,
				'source'          => $source->value,
				'schedule_id'     => $schedule_id,
				'parent_op_id'    => $parent_op_id,
				'filter_json'     => (string) wp_json_encode( $filter->to_array() ),
				'actions_json'    => (string) wp_json_encode( Action_Factory::list_to_array( $actions ) ),
				'target_count'    => 0,
				'processed'       => 0,
				'failed'          => 0,
				'mode'            => $mode->value,
				'batch_size'      => 0,
				'conflict_policy' => null === $conflict_policy ? null : $conflict_policy->value,
				'schedule_name'   => $schedule_name,
				'note'            => $note,
				// Taken from the filter rather than passed in beside it: the column
				// is a copy of `filter_json`'s language, kept only so the history can
				// filter on it in SQL, and two arguments for one fact is two things
				// that can disagree.
				'language'        => $filter->language(),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Delete an operation row.
	 *
	 * Two callers: discarding a draft that could not be queued (e.g. a scheduled
	 * fire that lost the single-writer lock in a race), and a user clearing a
	 * finished run out of their history. The second is destructive in a way the
	 * first is not — it throws away the audit trail, and with it the ability to
	 * undo — so it goes through {@see Operation_Service::delete()}, which removes
	 * the recorded deltas and detaches dependents rather than orphaning them.
	 *
	 * @param int $id Operation id.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete(
			$this->schema->operations_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Clear the parent link on any operation that points at this one — the undo
	 * operations spawned from it.
	 *
	 * Called before deleting a parent so its undos do not keep referencing a row
	 * that no longer exists. They stay in the history as the operations they are;
	 * they simply stop claiming a lineage that cannot be shown.
	 *
	 * @param int $parent_id The operation being deleted.
	 * @return int How many rows were detached.
	 */
	public function detach_children( int $parent_id ): int {
		$table = $this->schema->operations_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$detached = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET parent_op_id = NULL WHERE parent_op_id = %d",
				$parent_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_int( $detached ) ? $detached : 0;
	}

	/**
	 * The operations table's name.
	 *
	 * Exposed for the one caller that has to name it in SQL it does not itself run —
	 * see {@see Changes::table()} for the reasoning.
	 */
	public function table(): string {
		return $this->schema->operations_table();
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
	 * @param int         $limit    How many to return.
	 * @param int         $offset   How many to skip — one page's worth per page turned.
	 * @param string|null $language Show only runs belonging to this language (and
	 *                              the ones belonging to none); null shows every run.
	 * @return list<Operation>
	 */
	public function recent( int $limit = 20, int $offset = 0, ?string $language = null ): array {
		$table  = $this->schema->operations_table();
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		list( $where, $where_args ) = $this->language_where( $language );

		// The language clause is a code constant assembled by language_where(); the
		// placeholder it carries is bound below with the limit and the offset, in the
		// order they appear in the statement.
		$sql  = "SELECT * FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$args = array( ...$where_args, $limit, $offset );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, ...$args ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * How many operations the history holds. The list used to answer with the
	 * newest twenty and no hint that there were more; the pager needs the whole
	 * count to say how far back it goes.
	 *
	 * Takes the same language as {@see recent()} and must be called with the same
	 * one: a count that included runs the list is hiding would page past the end
	 * of it and show the user empty pages they cannot explain.
	 *
	 * @param string|null $language Count only runs this language can see; null counts all.
	 */
	public function count_all( ?string $language = null ): int {
		$table = $this->schema->operations_table();

		list( $where, $where_args ) = $this->language_where( $language );

		if ( array() === $where_args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$sql = "SELECT COUNT(*) FROM {$table} {$where}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$where_args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The WHERE clause that confines the history to one language, if it is confined.
	 *
	 * One method rather than the same condition written twice, because the two
	 * places that need it are a list and the count that pages it, and this codebase
	 * has already paid once for a rule kept in two places that drifted apart.
	 *
	 * **A run with no language is visible in every language, and that is the whole
	 * subtlety here.** NULL is written by three different situations — a site with
	 * no WPML, a user working on WPML's "All languages", and every run made before
	 * the column existed — and none of them is a claim that the run belonged to
	 * some other language. Hiding those rows would empty the history of any shop
	 * the moment it upgraded, and would hide an all-languages run from the very
	 * languages it changed.
	 *
	 * @param string|null $language The language to confine to, or null for no confinement.
	 * @return array{0: string, 1: list<string>} The clause (possibly empty) and its arguments.
	 */
	private function language_where( ?string $language ): array {
		if ( null === $language || '' === $language ) {
			return array( '', array() );
		}

		return array( 'WHERE ( language = %s OR language IS NULL )', array( $language ) );
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
	 * @param int $processed_delta Change rows resolved in this chunk — the same unit
	 *                             target_count is seeded in, so the two compare.
	 * @param int $failed_delta    Change rows that failed in this chunk, in the same
	 *                             unit — an object that throws fails all of its rows.
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
	 * Set the counters outright, rather than adding to them.
	 *
	 * The counterpart to {@see record_progress()}, and the only caller is the one
	 * moment the true figures are knowable: a run that has settled, reconciled
	 * against its own change rows by {@see Chunk_Runner::finalize()}. Adding is right
	 * while work is in flight, because each worker knows only what it has just done;
	 * it is wrong at the end, because what a worker never got to report is exactly
	 * what a violent death takes with it.
	 *
	 * @param int $id        Operation id.
	 * @param int $processed Objects, or rows for an undo, that were dealt with.
	 * @param int $failed    Of those, the ones that failed.
	 */
	public function set_progress( int $id, int $processed, int $failed ): void {
		$this->wpdb->update(
			$this->schema->operations_table(),
			array(
				'processed' => $processed,
				'failed'    => $failed,
			),
			array( 'id' => $id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
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
			empty( $row['schedule_id'] ) ? null : (int) $row['schedule_id'],
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
			// Absent on every row written before migration 9, and on every run that
			// had no schedule or no note. Null reads correctly for all three.
			isset( $row['schedule_name'] ) && '' !== $row['schedule_name'] ? (string) $row['schedule_name'] : null,
			isset( $row['note'] ) && '' !== $row['note'] ? (string) $row['note'] : null,
			// Absent on every row written before migration 10, and NULL on every run
			// that was not confined to a language. Both mean the same thing here.
			isset( $row['language'] ) && '' !== $row['language'] ? (string) $row['language'] : null,
		);
	}
}
