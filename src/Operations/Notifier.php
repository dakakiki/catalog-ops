<?php
/**
 * Tells somebody what happened to work nobody was watching.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The M5 notification (CONTEXT §4: "zakazana operacija šalje izveštaj"), grown
 * into the three things a person who is not at the screen actually needs to hear.
 *
 * It used to say only one of them. A completed run mailed a report; a run that
 * died said nothing, and a schedule that stopped itself said nothing — the
 * `catalogops_schedule_paused` hook fired into a room with no listener in it. For
 * somebody who set a nightly job and went to bed, that is precisely backwards:
 * the plugin spoke when there was nothing to do and fell silent when there was.
 *
 * So there are three messages now, and one rule behind which of them are sent:
 *
 *   - **A run finished with something skipped or failed.** A run that changed
 *     everything it promised says nothing at all, because an hourly schedule
 *     sending twenty-four cheerful reports a day teaches its reader to delete
 *     them unopened — including the one that mattered.
 *   - **A run was given up on** ({@see Watchdog}). Downstream of {@see Recovery},
 *     so this means the work really stopped, not that a process hiccuped.
 *   - **A schedule stopped itself** ({@see Schedule_Runner::pause()}). Worse than
 *     a failed run, because there is no row in the history to notice: it simply
 *     never happens again.
 *
 * Only scheduled work notifies: a UI operation is watched live, an undo is a
 * manual step, and a schedule the user paused themselves needs no announcement.
 * The recipient is the address on the schedule, falling back to the site admin;
 * both it and whether to send at all stay filterable.
 */
final class Notifier {

	/**
	 * Operations repository.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Changes repository (for the applied/skipped/failed tally).
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Schedules repository (to find the schedule behind an operation).
	 *
	 * @var Schedules
	 */
	private Schedules $schedules;

	/**
	 * Build the notifier.
	 *
	 * @param Operations $operations Operations repository.
	 * @param Changes    $changes    Changes repository.
	 * @param Schedules  $schedules  Schedules repository.
	 */
	public function __construct( Operations $operations, Changes $changes, Schedules $schedules ) {
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->schedules  = $schedules;
	}

	/**
	 * Send the completion report for an operation, if it is a scheduled one.
	 *
	 * @param int $op_id The completed operation's id.
	 */
	public function notify( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation || Operation_Source::SCHEDULE !== $operation->source ) {
			return;
		}

		$counts = $this->changes->counts( $op_id );

		// A run that changed everything it promised has nothing to tell anyone. An
		// hourly schedule was sending twenty-four of these a day, which is the surest
		// way to teach someone to ignore the twenty-fifth — and the twenty-fifth is
		// now the one that says something went wrong. Silence on success is what buys
		// the failure messages their meaning.
		if ( 0 === (int) $counts['skipped'] && 0 === (int) $counts['failed'] ) {
			return;
		}

		$schedule = $this->schedule_for( $operation );
		$report   = $this->build_report( $operation, $schedule );

		/**
		 * Filters whether a completion notification is sent.
		 *
		 * @param bool      $send      Whether to send (default true).
		 * @param Operation $operation The completed operation.
		 */
		if ( ! apply_filters( 'catalogops_send_notifications', true, $operation ) ) {
			return;
		}

		wp_mail( $report['recipient'], $report['subject'], $report['body'] );
	}

	/**
	 * Say that a scheduled run stopped and was not brought back.
	 *
	 * The message this plugin most needed and did not have. Everything else here
	 * announces success; a run that died announced nothing at all, so the person who
	 * set the schedule up found out by opening a screen they had no reason to open.
	 * Nobody watches a nightly job — that is the point of it being nightly.
	 *
	 * Sent when {@see Watchdog} gives up on a run, which is deliberately downstream
	 * of {@see Recovery}: a run whose process died is first handed to a new worker,
	 * silently and repeatedly, and only one that cannot be carried on at all reaches
	 * here. So this message means "it is really over", not "something hiccuped".
	 *
	 * @param int $op_id The operation that was given up on.
	 */
	public function notify_failed( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation || Operation_Source::SCHEDULE !== $operation->source ) {
			return;
		}

		$schedule = $this->schedule_for( $operation );
		$label    = $this->label( $operation, $schedule );
		$pending  = $this->changes->pending_count( $op_id );

		$lines = array(
			sprintf(
				/* translators: %s: schedule or operation label. */
				__( 'The scheduled operation "%s" stopped before it finished, and could not be carried on.', 'catalogops' ),
				$label
			),
			'',
			__( 'Changed before it stopped:', 'catalogops' ) . '  ' . $operation->processed,
			__( 'Still waiting:', 'catalogops' ) . '  ' . $pending,
			'',
			// The one sentence that turns a report into something the reader can act
			// on: the work is not lost, and finishing it is not the same as running
			// the whole thing again over a catalogue that has moved on.
			__( 'Nothing was lost. The items still waiting are the ones this run had already frozen, and Resume in the operation history finishes exactly those.', 'catalogops' ),
		);

		$this->send(
			$operation,
			$this->recipient( $schedule, $operation ),
			sprintf(
				/* translators: 1: site name, 2: schedule or operation label. */
				__( '[%1$s] Scheduled operation stopped: %2$s', 'catalogops' ),
				$this->site(),
				$label
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * Say that a schedule stopped itself.
	 *
	 * Listens to `catalogops_schedule_paused`, which {@see Schedule_Runner::pause()}
	 * fires and which had no listener at all — the hook existed, announced a
	 * schedule going quiet, and nobody was told. A schedule that stops answering is
	 * worse than a run that fails, because there is no row in the history to notice:
	 * it simply never happens again, and the first sign is that prices are a month
	 * stale.
	 *
	 * Only the supervisor's own pauses reach here. A schedule the user paused, or
	 * one paused because they stopped or undid its run, is their own doing and needs
	 * no announcement.
	 *
	 * @param int             $schedule_id The schedule that paused itself.
	 * @param \Throwable|null $error       What stopped it, when there was an error.
	 */
	public function notify_schedule_paused( int $schedule_id, $error = null ): void {
		$schedule = $this->schedules->find( $schedule_id );

		if ( null === $schedule ) {
			return;
		}

		$label = '' !== $schedule->name ? $schedule->name : sprintf( 'Schedule #%d', $schedule->id );

		// The exception, then the column, then an admission. The thrown message is
		// preferred because it is the first-hand account; the column holds the same
		// text truncated to fit, and a schedule paused before that column existed has
		// neither. Saying "not recorded" is better than a blank line the reader has
		// to interpret.
		$reason = '';

		if ( $error instanceof \Throwable ) {
			$reason = trim( $error->getMessage() );
		}

		if ( '' === $reason ) {
			$reason = trim( (string) $schedule->paused_reason );
		}

		if ( '' === $reason ) {
			$reason = __( 'not recorded', 'catalogops' );
		}

		$lines = array(
			sprintf(
				/* translators: %s: schedule name. */
				__( 'The schedule "%s" stopped itself and will not run again until it is resumed.', 'catalogops' ),
				$label
			),
			'',
			__( 'Reason:', 'catalogops' ) . '  ' . $reason,
			'',
			__( 'Resume it from the Schedules list once whatever stopped it has been put right. Resuming without fixing it will simply stop it again on the next run.', 'catalogops' ),
		);

		$recipient = $this->recipient( $schedule, null );

		if ( ! apply_filters( 'catalogops_send_notifications', true, null ) ) {
			return;
		}

		wp_mail(
			$recipient,
			sprintf(
				/* translators: 1: site name, 2: schedule name. */
				__( '[%1$s] Schedule stopped: %2$s', 'catalogops' ),
				$this->site(),
				$label
			),
			implode( "\n", $lines )
		);
	}

	/**
	 * The schedule an operation came from, read straight off the run.
	 *
	 * Replaces a lookup through `schedules.last_op_id`, which is overwritten on every
	 * fire and so could only ever resolve a schedule's newest run. That was survivable
	 * while the only message was a completion report, which arrives while the run
	 * that triggered it is still the newest — and it is not survivable for a failure
	 * message, which may well be about an older one.
	 *
	 * @param Operation $operation The operation to attribute.
	 */
	private function schedule_for( Operation $operation ): ?Schedule {
		return null === $operation->schedule_id ? null : $this->schedules->find( $operation->schedule_id );
	}

	/**
	 * What to call this run in a subject line.
	 *
	 * @param Operation     $operation The operation.
	 * @param Schedule|null $schedule  Its schedule, when there is one.
	 */
	private function label( Operation $operation, ?Schedule $schedule ): string {
		return ( null !== $schedule && '' !== $schedule->name )
			? $schedule->name
			: sprintf( 'Operation #%d', $operation->id );
	}

	/**
	 * The site's name, as a human reads it.
	 */
	private function site(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Who hears about this: the address on the schedule, or the site admin.
	 *
	 * One resolution for every message this class sends. Two would drift, and a
	 * failure notice arriving somewhere other than the success notices is worse than
	 * no failure notice at all — the reader would conclude the schedule was fine.
	 *
	 * @param Schedule|null  $schedule  The schedule, when there is one.
	 * @param Operation|null $operation The operation, for the filter's sake.
	 */
	private function recipient( ?Schedule $schedule, ?Operation $operation ): string {
		$recipient = ( null !== $schedule && '' !== $schedule->notify_email )
			? $schedule->notify_email
			: (string) get_option( 'admin_email' );

		if ( null === $operation ) {
			return $recipient;
		}

		/** This filter is documented in src/Operations/Notifier.php */
		return (string) apply_filters( 'catalogops_notification_recipient', $recipient, $operation );
	}

	/**
	 * Send, subject to the same opt-out every message here honours.
	 *
	 * @param Operation $operation The operation being reported on.
	 * @param string    $recipient Where to send it.
	 * @param string    $subject   Subject line.
	 * @param string    $body      Message body.
	 */
	private function send( Operation $operation, string $recipient, string $subject, string $body ): void {
		/** This filter is documented in src/Operations/Notifier.php */
		if ( ! apply_filters( 'catalogops_send_notifications', true, $operation ) ) {
			return;
		}

		wp_mail( $recipient, $subject, $body );
	}

	/**
	 * Build the report for a completed operation: recipient, subject, and body.
	 * Kept separate from sending so the content is straightforward to test.
	 *
	 * @param Operation     $operation The completed operation.
	 * @param Schedule|null $schedule  The schedule behind it, if still resolvable.
	 * @return array{recipient: string, subject: string, body: string}
	 */
	public function build_report( Operation $operation, ?Schedule $schedule ): array {
		$counts = $this->changes->counts( $operation->id );
		$label  = $this->label( $operation, $schedule );
		$site   = $this->site();

		/**
		 * Filters the recipient of a notification.
		 *
		 * @param string    $recipient The email address.
		 * @param Operation $operation The operation being reported on.
		 */
		$recipient = $this->recipient( $schedule, $operation );

		$subject = sprintf(
			/* translators: 1: site name, 2: schedule or operation label. */
			__( '[%1$s] Scheduled operation completed: %2$s', 'catalogops' ),
			$site,
			$label
		);

		$lines = array(
			sprintf(
				/* translators: %s: schedule or operation label. */
				__( 'The scheduled operation "%s" has completed.', 'catalogops' ),
				$label
			),
			'',
			__( 'Targets:', 'catalogops' ) . '  ' . $operation->target_count,
			__( 'Changed:', 'catalogops' ) . '  ' . $counts['applied'],
			__( 'Skipped:', 'catalogops' ) . '  ' . $counts['skipped'],
		);

		// A bare skipped count leaves the reader guessing at exactly the moment they
		// cannot come and look; the breakdown is the point of the report.
		foreach ( $this->changes->skip_reasons( $operation->id ) as $reason ) {
			$explanation = Skip_Reason::tryFrom( $reason['reason'] );

			$lines[] = '  - ' . $reason['count'] . ': ' . (
				null === $explanation
					? __( 'no reason recorded', 'catalogops' )
					: $explanation->label()
			);
		}

		$lines = array(
			...$lines,
			__( 'Failed:', 'catalogops' ) . '   ' . $counts['failed'],
			'',
			sprintf(
				/* translators: %s: completion time. */
				__( 'Completed at %s (GMT).', 'catalogops' ),
				(string) $operation->completed_at
			),
		);

		return array(
			'recipient' => $recipient,
			'subject'   => $subject,
			'body'      => implode( "\n", $lines ),
		);
	}
}
