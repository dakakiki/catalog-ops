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
	 * How much of a failure's message the schedules table records.
	 */
	private const REASON_LENGTH = 191;

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
	 *
	 * Everything that can fail is inside a guard, and the guard's job is to contain
	 * the failure to this one schedule. `create()` used to sit outside it, so a
	 * schedule whose stored template no longer holds — an action type that has gone,
	 * a formula a lapsed licence no longer covers, a filter naming a field nothing
	 * can answer — threw straight out of {@see run_due()}. Because
	 * {@see Schedules::due()} orders by next_run and a schedule that throws never
	 * reaches {@see Schedules::record_run()}, that one row stayed the most overdue
	 * for ever and headed the list on every tick: every other schedule on the site
	 * was skipped, permanently, while Action Scheduler quietly re-queued the tick
	 * and marked it failed. Nothing recovered on its own.
	 *
	 * On the rare lock race (the active-op check passed but queue was blocked) the
	 * schedule is left due for the next tick. On anything else it is paused with the
	 * reason, so it stops retrying and someone can see why.
	 *
	 * @param Schedule $schedule The schedule to fire.
	 * @param string   $now_gmt  Fire time (GMT MySQL datetime).
	 * @return bool Whether it fired.
	 */
	private function fire( Schedule $schedule, string $now_gmt ): bool {
		// Assigned inside the try, so it has to exist before it: both arms run on
		// paths where create() may never have returned an id.
		$op_id = 0;

		try {
			// The arguments are part of the guarded statement rather than hoisted
			// above it: filter() and actions() rebuild the stored template and are the
			// most likely thing here to throw.
			$op_id = $this->service->create(
				$schedule->filter(),
				$schedule->actions(),
				$schedule->mode,
				Operation_Source::SCHEDULE,
				$schedule->user_id
			);

			$this->service->queue( $op_id );
		} catch ( Operation_Blocked $e ) {
			// The rare lock race. Nothing is wrong with this schedule, so it is left
			// due for the next tick rather than paused.
			$this->discard_draft( $op_id );

			return false;
		} catch ( Throwable $e ) {
			// Throwable, not Exception: \ValueError from a corrupt enum token extends
			// \Error, so narrowing this would silently reopen the hole.
			$this->discard_draft( $op_id );
			$this->pause( $schedule, $e );

			return false;
		}

		// The operation is real from here: queued, holding the write lock, and the
		// chunk runner will finish it. Only the schedule's own bookkeeping is left,
		// so a failure here pauses the schedule *without* touching the operation —
		// deleting it would strip a running job out of the history and orphan the
		// rows it seeded.
		try {
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
		} catch ( Throwable $e ) {
			$this->pause( $schedule, $e );
		}

		return true;
	}

	/**
	 * Stop a schedule retrying, and record what stopped it.
	 *
	 * Pausing on its own would be a downgrade rather than a fix. Before the guard
	 * existed the throw escaped as far as Action Scheduler, which logged its message
	 * against a failed action under WooCommerce → Status → Scheduled Actions — the
	 * only account of the failure anywhere — and containing the throw silences it.
	 * So the reason goes on the row, where the schedules table can say why the one
	 * control on offer (Resume) is going to stop it again, and the hook is there for
	 * a site that would rather be told.
	 *
	 * @param Schedule  $schedule The schedule to pause.
	 * @param Throwable $e        What stopped it.
	 */
	private function pause( Schedule $schedule, Throwable $e ): void {
		// Truncated rather than dropped: the first clause is the part that names the
		// field or the action, which is the whole of what the reader needs.
		$reason = mb_substr( trim( $e->getMessage() ), 0, self::REASON_LENGTH );

		$this->schedules->set_status(
			$schedule->id,
			Schedule_Status::PAUSED,
			'' === $reason ? null : $reason
		);

		/**
		 * Fires when a schedule pauses itself because it could not fire.
		 *
		 * @param int       $schedule_id The schedule that paused.
		 * @param Throwable $e           The error that stopped it.
		 */
		do_action( 'catalogops_schedule_paused', $schedule->id, $e );
	}

	/**
	 * Remove the draft a failed fire left behind, if there still is one.
	 *
	 * {@see Operation_Service::queue()} already discards its own draft on both throw
	 * paths, and deliberately leaves alone anything that got as far as being frozen —
	 * that is a real operation. This applies the same rule to the cases queue()
	 * cannot see: a throw from create(), which either produced no row at all (hence
	 * the zero) or a draft nobody else will clean up, and the two argument checks
	 * queue() makes before its own guard opens. Without the status check this would
	 * delete a live QUEUED operation whenever queue() threw after the hand-off,
	 * orphaning the changes rows it had already seeded.
	 *
	 * @param int $op_id Draft id, or 0 when create() never returned one.
	 */
	private function discard_draft( int $op_id ): void {
		if ( 0 === $op_id ) {
			return;
		}

		$operation = $this->operations->find( $op_id );

		if ( null === $operation || Operation_Status::DRAFT !== $operation->status ) {
			return;
		}

		$this->operations->delete( $op_id );
	}
}
