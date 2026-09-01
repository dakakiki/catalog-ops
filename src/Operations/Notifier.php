<?php
/**
 * Emails a report when a scheduled operation completes.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The M5 notification (CONTEXT §4: "zakazana operacija šalje izveštaj"). A
 * scheduled operation runs unattended, so when it settles the person who set it
 * up gets a short report — how many objects were changed, skipped (e.g. formula
 * inputs that were empty), or failed.
 *
 * Only scheduled operations notify: a UI operation is watched live, and an undo
 * is a manual step. The listener hangs off the `catalogops_operation_completed`
 * action; it resolves the schedule behind the operation, then mails its report
 * recipient (falling back to the site admin). Both the recipient and whether to
 * send at all are filterable, so a site can redirect or silence the reports.
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

		$schedule = $this->schedules->find_by_last_op( $op_id );
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
	 * Build the report for a completed operation: recipient, subject, and body.
	 * Kept separate from sending so the content is straightforward to test.
	 *
	 * @param Operation     $operation The completed operation.
	 * @param Schedule|null $schedule  The schedule behind it, if still resolvable.
	 * @return array{recipient: string, subject: string, body: string}
	 */
	public function build_report( Operation $operation, ?Schedule $schedule ): array {
		$counts = $this->changes->counts( $operation->id );
		$label  = ( null !== $schedule && '' !== $schedule->name ) ? $schedule->name : sprintf( 'Operation #%d', $operation->id );
		$site   = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		$recipient = ( null !== $schedule && '' !== $schedule->notify_email )
			? $schedule->notify_email
			: (string) get_option( 'admin_email' );

		/**
		 * Filters the recipient of a completion report.
		 *
		 * @param string    $recipient The email address.
		 * @param Operation $operation The completed operation.
		 */
		$recipient = (string) apply_filters( 'catalogops_notification_recipient', $recipient, $operation );

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
