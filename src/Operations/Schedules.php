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
				// Taken from the filter rather than passed in beside it: the column
				// is a copy of `filter_json`'s language, kept only so the list can
				// filter on it in SQL, and two arguments for one fact is two things
				// that can disagree.
				'language'     => $filter->language(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
	 * @param int         $limit    How many to return.
	 * @param int         $offset   How many to skip — one page's worth per page turned.
	 * @param string|null $language Show only schedules belonging to this language
	 *                              (and the ones belonging to none); null shows all.
	 * @return list<Schedule>
	 */
	public function all( int $limit = 100, int $offset = 0, ?string $language = null ): array {
		$table  = $this->schema->schedules_table();
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		list( $where, $where_args ) = $this->language_where( $language );

		// The language clause is a code constant assembled by language_where(); the
		// placeholder it carries is bound below with the limit and the offset, in
		// the order they appear in the statement.
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
	 * How many schedules exist, for the pager to know how far the list goes.
	 *
	 * Takes the same language as {@see all()} and must be called with the same one:
	 * a count that included schedules the list is hiding would offer pages the list
	 * cannot fill.
	 *
	 * @param string|null $language Count only schedules this language can see; null counts all.
	 */
	public function count_all( ?string $language = null ): int {
		$table = $this->schema->schedules_table();

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
	 * The WHERE clause that confines the list to one language, if it is confined.
	 *
	 * The twin of {@see Operations::language_where()}, and it says the same thing
	 * for the same reasons — one method here rather than the condition written
	 * twice, because the two places that need it are a list and the count that
	 * pages it.
	 *
	 * **A schedule with no language is visible in every language.** NULL is written
	 * by three different situations — a site with no WPML, a schedule authored on
	 * WPML's "All languages", and every schedule written before the column existed
	 * — and none of them is a claim that it belongs to some other language. Hiding
	 * those rows would empty the schedules page of any shop the moment it upgraded,
	 * and would hide an all-languages schedule from the very languages it changes.
	 *
	 * **{@see due()} does not use this, and must never.** A cron tick has no
	 * language, and a due list narrowed by one would leave every schedule outside
	 * the site's default language silently never firing. What a run touches is
	 * decided by the language inside its own frozen filter.
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
	 * A successful fire also clears any pause explanation: the column describes the
	 * pause a schedule is currently in, and a schedule that just ran is not in one.
	 *
	 * @param int             $id           Schedule id.
	 * @param int             $op_id        Operation the fire spawned.
	 * @param string          $last_run_gmt Fire time (GMT MySQL datetime).
	 * @param string|null     $next_run_gmt Next fire time, or null to leave unchanged.
	 * @param Schedule_Status $status       New lifecycle state.
	 */
	public function record_run( int $id, int $op_id, string $last_run_gmt, ?string $next_run_gmt, Schedule_Status $status ): void {
		$data    = array(
			'last_run'      => $last_run_gmt,
			'last_op_id'    => $op_id,
			'status'        => $status->value,
			'paused_reason' => null,
		);
		$formats = array( '%s', '%d', '%s', '%s' );

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
	 * Move a schedule to a new lifecycle state (pause/resume), recording why when
	 * the plugin paused it itself.
	 *
	 * @param int             $id     Schedule id.
	 * @param Schedule_Status $status New state.
	 * @param string|null     $reason Why, for a self-inflicted pause; null clears it.
	 * @return bool Whether a row changed.
	 */
	public function set_status( int $id, Schedule_Status $status, ?string $reason = null ): bool {
		$updated = $this->wpdb->update(
			$this->schema->schedules_table(),
			array( 'status' => $status->value ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		// Written as its own statement, and its result ignored on purpose.
		// maybe_upgrade() runs on admin_init and a cron tick does not, so between a
		// plugin update landing on disk and the next admin request this column may
		// not exist yet. A schedule that pauses without its explanation is far better
		// than one whose pause failed to stick and goes on retrying. The reason is
		// always written, so resuming clears a stale explanation rather than leaving
		// it on a live schedule.
		$this->wpdb->update(
			$this->schema->schedules_table(),
			array( 'paused_reason' => $reason ),
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
			// array_key_exists, not isset: the column is nullable, and on a database
			// still on schema 6 the key is absent altogether.
			array_key_exists( 'paused_reason', $row ) && null !== $row['paused_reason']
				? (string) $row['paused_reason']
				: null,
			// Absent on every row written before migration 11, and NULL on every
			// schedule that was not confined to a language. Both mean the same here.
			array_key_exists( 'language', $row ) && '' !== $row['language'] && null !== $row['language']
				? (string) $row['language']
				: null,
		);
	}
}
