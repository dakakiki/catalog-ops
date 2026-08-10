<?php
/**
 * Repository for the schedules table.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Database\Schema;
use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Query\Filter;
use wpdb;

/**
 * CRUD and firing bookkeeping over {prefix}catalogops_schedules. A schedule
 * stores the same filter_json / actions_json shapes an operation does, plus a
 * recurrence and the next fire time. {@see due()} is the supervisor's query;
 * {@see record_run()} advances a row after it fires.
 */
final class Schedules {

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
	 * Store a new schedule and return its id.
	 *
	 * @param string                                  $name         Human label.
	 * @param Filter                                  $filter       Filter template.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions      Actions to apply.
	 * @param Operation_Mode                          $mode         Write strategy.
	 * @param Recurrence                              $recurrence   How often it fires.
	 * @param string                                  $next_run_gmt First fire time (GMT MySQL datetime).
	 * @param string                                  $notify_email Report recipient (may be empty).
	 * @param int                                     $user_id      Owner user id.
	 * @return int The new schedule id.
	 */
	public function create(
		string $name,
		Filter $filter,
		array $actions,
		Operation_Mode $mode,
		Recurrence $recurrence,
		string $next_run_gmt,
		string $notify_email,
		int $user_id
	): int {
		$this->wpdb->insert(
			$this->schema->schedules_table(),
			array(
				'user_id'      => $user_id,
				'name'         => $name,
				'filter_json'  => (string) wp_json_encode( $filter->to_array() ),
				'actions_json' => (string) wp_json_encode( Action_Factory::list_to_array( $actions ) ),
				'mode'         => $mode->value,
				'recurrence'   => $recurrence->value,
				'status'       => Schedule_Status::ACTIVE->value,
				'next_run'     => $next_run_gmt,
				'notify_email' => $notify_email,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find a schedule by id.
	 *
	 * @param int $id Schedule id.
	 */
	public function find( int $id ): ?Schedule {
		$table = $this->schema->schedules_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * All schedules, newest first.
	 *
	 * @param int $limit How many to return.
	 * @return list<Schedule>
	 */
	public function all( int $limit = 100 ): array {
		$table = $this->schema->schedules_table();
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
	 * Active schedules whose next fire time has passed — the supervisor's due
	 * list, oldest first so the most-overdue fires soonest.
	 *
	 * @param string $now_gmt Current time (GMT MySQL datetime).
	 * @return list<Schedule>
	 */
	public function due( string $now_gmt ): array {
		$table  = $this->schema->schedules_table();
		$active = Schedule_Status::ACTIVE->value;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND next_run <= %s ORDER BY next_run ASC",
				$active,
				$now_gmt
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Find the schedule whose most recent fire spawned a given operation. The
	 * single-writer lock guarantees a schedule cannot fire again while its last
	 * operation is still active, so this stays a stable back-reference until that
	 * operation completes — which is when the notifier needs it.
	 *
	 * @param int $op_id The spawned operation id.
	 */
	public function find_by_last_op( int $op_id ): ?Schedule {
		$table = $this->schema->schedules_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$table} WHERE last_op_id = %d ORDER BY id DESC LIMIT 1", $op_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Record that a schedule fired: stamp the last run and spawned operation, set
	 * the next fire time (or leave it for a one-shot), and move to the given
	 * status (active to keep recurring, completed for a spent one-shot).
	 *
	 * @param int             $id           Schedule id.
	 * @param int             $op_id        Operation the fire spawned.
	 * @param string          $last_run_gmt Fire time (GMT MySQL datetime).
	 * @param string|null     $next_run_gmt Next fire time, or null to leave unchanged.
	 * @param Schedule_Status $status       New lifecycle state.
	 */
	public function record_run( int $id, int $op_id, string $last_run_gmt, ?string $next_run_gmt, Schedule_Status $status ): void {
		$data    = array(
			'last_run'   => $last_run_gmt,
			'last_op_id' => $op_id,
			'status'     => $status->value,
		);
		$formats = array( '%s', '%d', '%s' );

		if ( null !== $next_run_gmt ) {
			$data['next_run'] = $next_run_gmt;
			$formats[]        = '%s';
		}

		$this->wpdb->update(
			$this->schema->schedules_table(),
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Move a schedule to a new lifecycle state (pause/resume).
	 *
	 * @param int             $id     Schedule id.
	 * @param Schedule_Status $status New state.
	 * @return bool Whether a row changed.
	 */
	public function set_status( int $id, Schedule_Status $status ): bool {
		$updated = $this->wpdb->update(
			$this->schema->schedules_table(),
			array( 'status' => $status->value ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Delete a schedule.
	 *
	 * @param int $id Schedule id.
	 * @return bool Whether a row was deleted.
	 */
	public function delete( int $id ): bool {
		$deleted = $this->wpdb->delete(
			$this->schema->schedules_table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Build a Schedule from a raw row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 */
	private function hydrate( array $row ): Schedule {
		$filter_data  = json_decode( (string) $row['filter_json'], true );
		$actions_data = json_decode( (string) $row['actions_json'], true );

		return new Schedule(
			(int) $row['id'],
			(int) $row['user_id'],
			(string) $row['name'],
			is_array( $filter_data ) ? $filter_data : array(),
			is_array( $actions_data ) ? $actions_data : array(),
			Operation_Mode::from( (string) $row['mode'] ),
			Recurrence::from( (string) $row['recurrence'] ),
			Schedule_Status::from( (string) $row['status'] ),
			(string) $row['next_run'],
			null === $row['last_run'] ? null : (string) $row['last_run'],
			null === $row['last_op_id'] ? null : (int) $row['last_op_id'],
			(string) $row['notify_email'],
			(string) $row['created_at'],
		);
	}
}
