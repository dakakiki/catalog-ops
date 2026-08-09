<?php
/**
 * Repository for the changes table — the frozen execution units.
 *
 * @package CatalogOps\Operations;
 */

namespace CatalogOps\Operations;

use CatalogOps\Database\Schema;
use CatalogOps\Operations\Fields\Field_Type;
use wpdb;

/**
 * Owns {prefix}catalogops_changes. When an operation is queued the filter is
 * resolved once and one pending row is seeded per (object, field) — that set is
 * the frozen target list execution reads from, never the live catalog
 * (CONTEXT §2). During execution the runner claims a row by flipping it out of
 * PENDING; the flip is a single conditional UPDATE guarded on status = PENDING,
 * so a re-run of a partially applied chunk neither double-applies nor
 * double-counts (CONTEXT §3).
 */
final class Changes {

	/**
	 * How many rows to insert per statement while seeding (CONTEXT §3).
	 */
	private const SEED_CHUNK = 1000;

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
	 * Seed pending change rows for an operation, in bulk.
	 *
	 * @param int                                                                                            $operation_id Owning operation id.
	 * @param array<int, array{object_type?: string, object_id: int, field_type: string, field_key: string}> $rows         One entry per (object, field) to change.
	 * @return int Number of rows inserted.
	 */
	public function seed( int $operation_id, array $rows ): int {
		if ( array() === $rows ) {
			return 0;
		}

		$table    = $this->schema->changes_table();
		$inserted = 0;

		foreach ( array_chunk( $rows, self::SEED_CHUNK ) as $batch ) {
			$placeholders = array();
			$values       = array();

			foreach ( $batch as $row ) {
				$placeholders[] = '( %d, %s, %d, %s, %s, 0 )';
				$values[]       = $operation_id;
				$values[]       = $row['object_type'] ?? 'product';
				$values[]       = (int) $row['object_id'];
				$values[]       = $row['field_type'];
				$values[]       = $row['field_key'];
			}

			$sql = "INSERT INTO {$table}
				(operation_id, object_type, object_id, field_type, field_key, status)
				VALUES " . implode( ', ', $placeholders );

			// $sql is built from a constant table name and fixed placeholder
			// tuples; every value is bound through prepare().
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted += (int) $this->wpdb->query( $this->wpdb->prepare( $sql, ...$values ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return $inserted;
	}

	/**
	 * Freeze an undo operation's target list by copying the parent operation's
	 * applied deltas as fresh pending rows (CONTEXT §2: undo enters the same
	 * pipeline, its targets frozen up front). Each new row carries the same
	 * (object, field) identity as the parent delta it reverts, with null values to
	 * be filled at apply time and status PENDING. Done as a single INSERT…SELECT so
	 * even a 20k-row operation seeds in one statement.
	 *
	 * @param int $undo_op_id   The undo operation receiving the rows.
	 * @param int $parent_op_id The operation whose applied deltas are reverted.
	 * @return int Number of rows seeded (the undo's target count).
	 */
	public function seed_from_parent( int $undo_op_id, int $parent_op_id ): int {
		$table = $this->schema->changes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->query(
			$this->wpdb->prepare(
				"INSERT INTO {$table} (operation_id, object_type, object_id, field_type, field_key, status)
				SELECT %d, object_type, object_id, field_type, field_key, 0
				FROM {$table} WHERE operation_id = %d AND status = %d",
				$undo_op_id,
				$parent_op_id,
				Change_Status::APPLIED->value
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * The parent operation's applied deltas for a set of objects, indexed by
	 * object+field so an undo chunk can look up, per row, both the value to write
	 * back (the parent's old_value) and the drift baseline (the parent's new_value)
	 * in one query. Keyed by "{object_id}|{field_type}|{field_key}".
	 *
	 * @param int   $parent_op_id The reverted operation.
	 * @param int[] $object_ids   Object ids in the current chunk.
	 * @return array<string, array{old: ?string, new: ?string}>
	 */
	public function applied_index( int $parent_op_id, array $object_ids ): array {
		$object_ids = array_values( array_unique( array_map( 'intval', $object_ids ) ) );

		if ( array() === $object_ids ) {
			return array();
		}

		$table        = $this->schema->changes_table();
		$placeholders = implode( ', ', array_fill( 0, count( $object_ids ), '%d' ) );
		$params       = array_merge( array( $parent_op_id, Change_Status::APPLIED->value ), $object_ids );

		// The IN-list placeholders are generated to match $object_ids exactly, so
		// the static replacement-count check cannot see they line up.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT object_id, field_type, field_key, old_value, new_value
				FROM {$table}
				WHERE operation_id = %d AND status = %d AND object_id IN ( {$placeholders} )",
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$index = array();

		foreach ( $rows as $row ) {
			$key           = $row['object_id'] . '|' . $row['field_type'] . '|' . $row['field_key'];
			$index[ $key ] = array(
				'old' => null === $row['old_value'] ? null : (string) $row['old_value'],
				'new' => null === $row['new_value'] ? null : (string) $row['new_value'],
			);
		}

		return $index;
	}

	/**
	 * A sample of an operation's applied deltas, oldest object first — for the
	 * undo preview, which reads each sampled object's current value to show whether
	 * it would revert or be skipped as drift.
	 *
	 * @param int $operation_id Operation id.
	 * @param int $limit        Maximum rows to return.
	 * @return list<Change>
	 */
	public function applied_sample( int $operation_id, int $limit ): array {
		$table = $this->schema->changes_table();
		$limit = max( 0, $limit );

		if ( 0 === $limit ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE operation_id = %d AND status = %d ORDER BY object_id ASC, id ASC LIMIT %d",
				$operation_id,
				Change_Status::APPLIED->value,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * A page of an operation's change rows, newest object first — the audit-log
	 * detail view of what an operation did, field by field.
	 *
	 * @param int $operation_id Operation id.
	 * @param int $limit        Page size.
	 * @param int $offset       Rows to skip.
	 * @return list<Change>
	 */
	public function page( int $operation_id, int $limit, int $offset ): array {
		$table  = $this->schema->changes_table();
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE operation_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$operation_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * The next slice of still-pending rows for an operation, in frozen order
	 * (by object, then by seed order) so an object's fields stay together.
	 *
	 * @param int $operation_id Operation id.
	 * @param int $limit        Maximum rows to return.
	 * @return list<Change>
	 */
	public function pending_chunk( int $operation_id, int $limit ): array {
		$table = $this->schema->changes_table();
		$limit = max( 1, $limit );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE operation_id = %d AND status = 0 ORDER BY object_id ASC, id ASC LIMIT %d",
				$operation_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * How many rows are still pending for an operation.
	 *
	 * @param int $operation_id Operation id.
	 */
	public function pending_count( int $operation_id ): int {
		$table = $this->schema->changes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE operation_id = %d AND status = 0",
				$operation_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Record a successful change and claim the row: set the old/new values and
	 * flip PENDING → APPLIED in one guarded statement.
	 *
	 * @param int         $id        Change row id.
	 * @param string|null $old_value Value before the change.
	 * @param string|null $new_value Value written.
	 * @return bool Whether this call claimed the row (false if already non-pending).
	 */
	public function mark_applied( int $id, ?string $old_value, ?string $new_value ): bool {
		$table = $this->schema->changes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = 1, old_value = %s, new_value = %s WHERE id = %d AND status = 0",
				$old_value,
				$new_value,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Mark a still-pending row as failed. Records the snapshot's old value when
	 * one was read before the failure.
	 *
	 * @param int         $id        Change row id.
	 * @param string|null $old_value Value read before the failure, if any.
	 * @return bool Whether this call claimed the row.
	 */
	public function mark_failed( int $id, ?string $old_value = null ): bool {
		return $this->set_terminal_status( $id, Change_Status::FAILED, $old_value );
	}

	/**
	 * Mark a still-pending row as skipped (e.g. a formula over an empty field, or
	 * a drift conflict in M3).
	 *
	 * @param int         $id        Change row id.
	 * @param string|null $old_value Value read before skipping, if any.
	 * @return bool Whether this call claimed the row.
	 */
	public function mark_skipped( int $id, ?string $old_value = null ): bool {
		return $this->set_terminal_status( $id, Change_Status::SKIPPED, $old_value );
	}

	/**
	 * Row counts grouped by status for an operation.
	 *
	 * @param int $operation_id Operation id.
	 * @return array{pending: int, applied: int, failed: int, skipped: int}
	 */
	public function counts( int $operation_id ): array {
		$table = $this->schema->changes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT status, COUNT(*) AS total FROM {$table} WHERE operation_id = %d GROUP BY status",
				$operation_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$counts = array(
			'pending' => 0,
			'applied' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		$by_value = array(
			Change_Status::PENDING->value => 'pending',
			Change_Status::APPLIED->value => 'applied',
			Change_Status::FAILED->value  => 'failed',
			Change_Status::SKIPPED->value => 'skipped',
		);

		foreach ( $rows as $row ) {
			$key = $by_value[ (int) $row['status'] ] ?? null;
			if ( null !== $key ) {
				$counts[ $key ] = (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Delete all rows for an operation (used by tests and retention).
	 *
	 * @param int $operation_id Operation id.
	 * @return int Rows removed.
	 */
	public function delete_for_operation( int $operation_id ): int {
		return (int) $this->wpdb->delete(
			$this->schema->changes_table(),
			array( 'operation_id' => $operation_id ),
			array( '%d' )
		);
	}

	/**
	 * Purge the recorded deltas of operations that completed before a cutoff — the
	 * retention window closing (CONTEXT §3). Only the heavy changes rows are
	 * removed; the operations themselves stay as the audit trail. Bounded to a
	 * number of operations per call so a first run over a long-lived catalog does
	 * not delete millions of rows in one statement; the daily job drains the
	 * backlog over successive runs. Idempotent: an operation with no remaining rows
	 * is simply not selected again.
	 *
	 * @param string $cutoff_gmt     MySQL datetime (GMT); operations completed before this.
	 * @param int    $max_operations Maximum operations to purge this call.
	 * @return array{operations: int, rows: int}
	 */
	public function purge_completed_before( string $cutoff_gmt, int $max_operations = 100 ): array {
		$changes    = $this->schema->changes_table();
		$operations = $this->schema->operations_table();
		$max        = max( 1, $max_operations );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$op_ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT DISTINCT c.operation_id
				FROM {$changes} c
				INNER JOIN {$operations} o ON o.id = c.operation_id
				WHERE o.completed_at IS NOT NULL AND o.completed_at < %s
				ORDER BY c.operation_id ASC
				LIMIT %d",
				$cutoff_gmt,
				$max
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = 0;
		foreach ( $op_ids as $op_id ) {
			$rows += $this->delete_for_operation( (int) $op_id );
		}

		return array(
			'operations' => count( $op_ids ),
			'rows'       => $rows,
		);
	}

	/**
	 * Flip a pending row to a terminal status, recording the old value if given.
	 *
	 * @param int           $id     Change row id.
	 * @param Change_Status $status Terminal status to set.
	 * @param string|null   $old    Value read before the transition, if any.
	 */
	private function set_terminal_status( int $id, Change_Status $status, ?string $old ): bool {
		$table = $this->schema->changes_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$table} SET status = %d, old_value = %s WHERE id = %d AND status = 0",
				$status->value,
				$old,
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Build a Change from a raw row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 */
	private function hydrate( array $row ): Change {
		return new Change(
			(int) $row['id'],
			(int) $row['operation_id'],
			(string) $row['object_type'],
			(int) $row['object_id'],
			Field_Type::from( (string) $row['field_type'] ),
			(string) $row['field_key'],
			null === $row['old_value'] ? null : (string) $row['old_value'],
			null === $row['new_value'] ? null : (string) $row['new_value'],
			Change_Status::from( (int) $row['status'] ),
		);
	}
}
