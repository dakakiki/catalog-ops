<?php
/**
 * Fires due schedules through the operation pipeline.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The supervisor for scheduled operations (CONTEXT §4). On each tick it asks the
 * repository for due schedules and, for each, creates and queues a fresh
 * operation — which resolves the filter at that moment, so a recurring schedule
 * always acts on the catalog as it is now (CONTEXT §2, rule 2), then advances
 * the schedule to its next run (or completes a one-shot).
 *
 * It honors the single-writer lock by deferring: if an operation is already
 * active it stops for this tick and the due schedules fire on a later one. That
 * also naturally spaces recurring runs — at most one new operation is started
 * per tick — rather than piling writes onto the catalog at once.
 */
final class Schedule_Runner {

	/**
	 * Schedules repository.
	 *
	 * @var Schedules
	 */
	private Schedules $schedules;

	/**
	 * Operation service (create + queue).
	 *
	 * @var Operation_Service
	 */
	private Operation_Service $service;

	/**
	 * Operations repository (active-op check and draft cleanup).
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Build the runner.
	 *
	 * @param Schedules         $schedules  Schedules repository.
	 * @param Operation_Service $service    Operation service.
	 * @param Operations        $operations Operations repository.
	 */
	public function __construct( Schedules $schedules, Operation_Service $service, Operations $operations ) {
		$this->schedules  = $schedules;
		$this->service    = $service;
		$this->operations = $operations;
	}

	/**
	 * Fire every schedule that is due, subject to the single-writer lock.
	 *
	 * @param string|null $now_gmt Current time (GMT MySQL datetime); defaults to now.
	 * @return int How many schedules fired this tick.
	 */
	public function run_due( ?string $now_gmt = null ): int {
		$now_gmt = $now_gmt ?? current_time( 'mysql', true );
		$fired   = 0;

		foreach ( $this->schedules->due( $now_gmt ) as $schedule ) {
			// One writer at a time: if an operation is running, leave the rest due
			// and pick them up next tick.
			if ( null !== $this->operations->active_excluding( 0 ) ) {
				break;
			}

			if ( $this->fire( $schedule, $now_gmt ) ) {
				++$fired;
			}
		}

		return $fired;
	}

	/**
	 * Fire one schedule immediately, regardless of its next-run time (the "run
	 * now" control), still honoring the single-writer lock.
	 *
	 * @param int         $id      Schedule id.
	 * @param string|null $now_gmt Current time (GMT MySQL datetime); defaults to now.
	 * @return bool Whether the schedule fired.
	 */
	public function run_one( int $id, ?string $now_gmt = null ): bool {
		$schedule = $this->schedules->find( $id );

		if ( null === $schedule || Schedule_Status::COMPLETED === $schedule->status ) {
			return false;
		}

		if ( null !== $this->operations->active_excluding( 0 ) ) {
			return false;
		}

		return $this->fire( $schedule, $now_gmt ?? current_time( 'mysql', true ) );
	}

	/**
	 * Create and queue an operation from a schedule, then advance the schedule.
	 * On the rare lock race (the active-op check passed but queue was blocked),
	 * the draft is discarded and the schedule is left due for the next tick.
	 *
	 * @param Schedule $schedule The schedule to fire.
	 * @param string   $now_gmt  Fire time (GMT MySQL datetime).
	 * @return bool Whether it fired.
	 */
	private function fire( Schedule $schedule, string $now_gmt ): bool {
		$op_id = $this->service->create(
			$schedule->filter(),
			$schedule->actions(),
			$schedule->mode,
			Operation_Source::SCHEDULE,
			$schedule->user_id
		);

		try {
			$this->service->queue( $op_id );
		} catch ( Operation_Blocked $e ) {
			$this->operations->delete( $op_id );
			return false;
		} catch ( Throwable $e ) {
			// A malformed template (e.g. an unsupported field) must not wedge the
			// supervisor: pause the schedule so it stops retrying, and drop the draft.
			$this->operations->delete( $op_id );
			$this->schedules->set_status( $schedule->id, Schedule_Status::PAUSED );
			return false;
		}

		$next = $schedule->recurrence->next_after(
			new DateTimeImmutable( $schedule->next_run, new DateTimeZone( 'UTC' ) ),
			new DateTimeImmutable( $now_gmt, new DateTimeZone( 'UTC' ) )
		);

		$this->schedules->record_run(
			$schedule->id,
			$op_id,
			$now_gmt,
			null === $next ? null : $next->format( 'Y-m-d H:i:s' ),
			null === $next ? Schedule_Status::COMPLETED : Schedule_Status::ACTIVE
		);

		return true;
	}
}
