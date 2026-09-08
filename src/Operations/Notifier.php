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
 * So there are four messages, and one rule behind which of them are sent:
 *
 *   - **A run completed.** Every operation that reaches {@see
 *     Chunk_Runner::finalize()} reports, whatever its source and whether or not
 *     anything was skipped.
 *   - **A run finished with something skipped or failed** — the same message,
 *     carrying the breakdown that explains the difference.
 *   - **A run was given up on** ({@see Watchdog}). Downstream of {@see Recovery},
 *     so this means the work really stopped, not that a process hiccuped.
 *   - **A schedule stopped itself** ({@see Schedule_Runner::pause()}). Worse than
 *     a failed run, because there is no row in the history to notice: it simply
 *     never happens again.
 *
 * **Success used to be silent, and the reason it no longer is.** The old rule was
 * that a run which changed everything it promised said nothing, on the argument
 * that an hourly schedule sending twenty-four cheerful reports a day teaches its
 * reader to delete the twenty-fifth unopened. That argument is real and it is not
 * wrong; it was simply outweighed by what silence actually costs. **Silence is
 * ambiguous.** "No mail arrived" means the run was clean, *or* the schedule never
 * fired, *or* cron is not reaching the site, *or* the host is dropping outgoing
 * mail — four very different situations that the reader cannot tell apart without
 * opening a screen they had no reason to open, which is the exact thing this class
 * exists to spare them. That ambiguity is not hypothetical: it cost a live
 * afternoon on a fresh server, where a working schedule and a broken mail
 * transport produced identical evidence. A report that always arrives is also the
 * only one whose *absence* means something.
 *
 * Every source notifies now, not just scheduled work. A UI operation is watched
 * live in the browser, so its report is redundant to whoever started it — and it
 * is not redundant to the colleague who did not, or to the same person tomorrow.
 * The volume this creates is the honest cost of the change; `catalogops_send_notifications`
 * is the opt-out, and it is handed the operation so a site can silence one source
 * and keep the rest.
 *
 * The recipient is the address on the schedule, falling back to the site admin;
 * both it and whether to send at all stay filterable.
 */
final class Notifier {

	/**
	 * The colour a figure is printed in, matching the admin screens.
	 *
	 * The same three the operation history uses, and they are the whole reason the
	 * report is HTML rather than text: a reader scanning a phone at seven in the
	 * morning takes in "is there any red in this" long before they read a number.
	 * Amber is the shop's own `#d97706`, deliberately not a second red — a skip is
	 * a decision the plugin made and explains, not a failure.
	 */
	private const COLOR_APPLIED = '#15803d';
	private const COLOR_SKIPPED = '#d97706';
	private const COLOR_FAILED  = '#b91c1c';
	private const COLOR_TEXT    = '#111827';
	private const COLOR_MUTED   = '#6b7280';
	private const COLOR_RULE    = '#e5e7eb';

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
	 * Send the completion report for an operation that finished.
	 *
	 * Reached from `catalogops_operation_completed`, which {@see
	 * Chunk_Runner::finalize()} fires only after the status has settled as
	 * COMPLETED and the counters have been reconciled from the change rows. So
	 * every mail this method sends is about work that really finished, and the
	 * figures in it are the settled ones rather than whatever the last surviving
	 * worker happened to have filed — a run stopped or given up on never arrives
	 * here at all, and has {@see notify_failed()} instead.
	 *
	 * No outcome is filtered out and no source is filtered out; see the class
	 * docblock for why silence on a clean run was withdrawn.
	 *
	 * @param int $op_id The completed operation's id.
	 */
	public function notify( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation ) {
			return;
		}

		$report = $this->build_report( $operation, $this->schedule_for( $operation ) );

		$this->send( $operation, $report['recipient'], $report['subject'], $report['html'], $report['body'] );
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

		$opening = sprintf(
			/* translators: %s: schedule or operation label. */
			__( 'The scheduled operation "%s" stopped before it finished, and could not be carried on.', 'catalogops' ),
			$label
		);

		$done    = __( 'Changed before it stopped:', 'catalogops' );
		$waiting = __( 'Still waiting:', 'catalogops' );

		// The one sentence that turns a report into something the reader can act
		// on: the work is not lost, and finishing it is not the same as running
		// the whole thing again over a catalogue that has moved on.
		$reassurance = __( 'Nothing was lost. The items still waiting are the ones this run had already frozen, and Resume in the operation history finishes exactly those.', 'catalogops' );

		$lines = array(
			$opening,
			'',
			$done . '  ' . $operation->processed,
			$waiting . '  ' . $pending,
			'',
			$reassurance,
		);

		// What is still waiting is the figure this message exists to deliver, so it
		// carries the alarming colour; what was already done is green because it is
		// genuinely done and stays done.
		$rows = $this->figure( $done, (string) $operation->processed, $operation->processed > 0 ? self::COLOR_APPLIED : '' )
			. $this->figure( $waiting, (string) $pending, $pending > 0 ? self::COLOR_FAILED : '' );

		$this->send(
			$operation,
			$this->recipient( $schedule, $operation ),
			sprintf(
				/* translators: 1: site name, 2: schedule or operation label. */
				__( '[%1$s] Scheduled operation stopped: %2$s', 'catalogops' ),
				$this->site(),
				$label
			),
			$this->shell( esc_html( $opening ), $this->figures( $rows ), $this->note( $reassurance ) ),
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

		$opening = sprintf(
			/* translators: %s: schedule name. */
			__( 'The schedule "%s" stopped itself and will not run again until it is resumed.', 'catalogops' ),
			$label
		);

		$because = __( 'Reason:', 'catalogops' );
		$advice  = __( 'Resume it from the Schedules list once whatever stopped it has been put right. Resuming without fixing it will simply stop it again on the next run.', 'catalogops' );

		$lines = array(
			$opening,
			'',
			$because . '  ' . $reason,
			'',
			$advice,
		);

		$recipient = $this->recipient( $schedule, null );

		/** This filter is documented in src/Operations/Notifier.php */
		if ( ! apply_filters( 'catalogops_send_notifications', true, null ) ) {
			return;
		}

		$this->mail(
			$recipient,
			sprintf(
				/* translators: 1: site name, 2: schedule name. */
				__( '[%1$s] Schedule stopped: %2$s', 'catalogops' ),
				$this->site(),
				$label
			),
			$this->shell(
				esc_html( $opening ),
				$this->figures( $this->figure( $because, $reason, self::COLOR_FAILED ) ),
				$this->note( $advice )
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
	 * The subject line's format string, chosen by what kind of run this was.
	 *
	 * Three whole sentences rather than one sentence with an interchangeable noun
	 * dropped into it. A translator handed "%1$s completed: %2$s" and the word
	 * "Undo" separately cannot make either agree with the other in a language that
	 * inflects — which is most of them, including the one this plugin is being
	 * built in. The duplication is the price of translatable output.
	 *
	 * The scheduled wording is unchanged from when it was the only wording, so a
	 * mail rule somebody already filed on the old subject keeps matching.
	 *
	 * @param Operation $operation The completed operation.
	 */
	private function subject_format( Operation $operation ): string {
		if ( $operation->is_undo() ) {
			/* translators: 1: site name, 2: operation label. */
			return __( '[%1$s] Undo completed: %2$s', 'catalogops' );
		}

		if ( Operation_Source::SCHEDULE === $operation->source ) {
			/* translators: 1: site name, 2: schedule or operation label. */
			return __( '[%1$s] Scheduled operation completed: %2$s', 'catalogops' );
		}

		/* translators: 1: site name, 2: operation label. */
		return __( '[%1$s] Operation completed: %2$s', 'catalogops' );
	}

	/**
	 * The report's first line, matching the subject.
	 *
	 * @param Operation $operation The completed operation.
	 * @param string    $label     What to call it.
	 */
	private function opening( Operation $operation, string $label ): string {
		if ( $operation->is_undo() ) {
			return sprintf(
				/* translators: %s: operation label. */
				__( 'The undo "%s" has completed.', 'catalogops' ),
				$label
			);
		}

		if ( Operation_Source::SCHEDULE === $operation->source ) {
			return sprintf(
				/* translators: %s: schedule or operation label. */
				__( 'The scheduled operation "%s" has completed.', 'catalogops' ),
				$label
			);
		}

		return sprintf(
			/* translators: %s: operation label. */
			__( 'The operation "%s" has completed.', 'catalogops' ),
			$label
		);
	}

	/**
	 * Wrap a message in the shared shell: a ruled header, the content, a ruled
	 * footer.
	 *
	 * Deliberately old-fashioned HTML — one table, every style inline, no class
	 * attributes, no external stylesheet, no web font, nothing that needs to be
	 * fetched. Mail clients strip `<style>` blocks, rewrite classes and block remote
	 * assets by default, so anything cleverer degrades into unstyled text at exactly
	 * the moment somebody needs to read it. The layout survives that anyway: with
	 * every rule and colour removed it is still a heading, a list of labelled
	 * figures and a footer.
	 *
	 * @param string $lead  The opening sentence, already escaped.
	 * @param string $rows  The figures table, or '' when the message carries none.
	 * @param string $notes Paragraphs after the figures, already escaped markup.
	 */
	private function shell( string $lead, string $rows, string $notes ): string {
		$font = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif';

		return '<div style="margin:0;padding:24px 12px;background:#f3f4f6;' . $font . '">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid ' . self::COLOR_RULE . ';border-radius:8px">'
			. '<tr><td style="padding:18px 24px;border-bottom:1px solid ' . self::COLOR_RULE . '">' . $this->header() . '</td></tr>'
			. '<tr><td style="padding:22px 24px;color:' . self::COLOR_TEXT . ';font-size:15px;line-height:1.5">'
			. '<p style="margin:0 0 16px">' . $lead . '</p>'
			. $rows
			. $notes
			. '</td></tr>'
			. '<tr><td style="padding:14px 24px;border-top:1px solid ' . self::COLOR_RULE . ';color:' . self::COLOR_MUTED . ';font-size:12px;line-height:1.5">' . $this->footer() . '</td></tr>'
			. '</table></div>';
	}

	/**
	 * The CID a bundled logo is embedded under.
	 */
	private const LOGO_CID = 'catalogops-logo';

	/**
	 * Where the notification logo comes from, and how it gets into the message.
	 *
	 * Three outcomes, in order: a URL a site supplied through the filter; the
	 * bundled `assets/email-logo.png`, embedded in the message itself; or nothing,
	 * in which case the header falls back to a text wordmark.
	 *
	 * The bundled file is a PNG and the admin header's mark is an SVG, which is
	 * the whole reason `bin/make-email-logo.php` exists: Gmail, Outlook and Yahoo
	 * all strip SVG, inline or linked, so the header's own markup would arrive as a
	 * broken-image icon. The PNG carries the entire lockup — tile, wordmark and
	 * tagline — rather than the tile alone beside HTML text, because the two-tone
	 * name and letterspaced tagline depend on a font stack no mail client
	 * guarantees; half the brand rendering in Times New Roman beside a
	 * pixel-perfect tile is worse than either alone.
	 *
	 * Embedding beats linking for the same reason the plain-text alternative
	 * exists. A linked image is remote content, and Outlook and Gmail both refuse
	 * to fetch it until the reader says so, so the default experience of a linked
	 * logo is a grey placeholder. It also assumes the shop is reachable from
	 * wherever the reader is, which a staging site behind HTTP auth is not.
	 *
	 * @return array{mode: string, src: string, path: string, width: int, height: int}
	 */
	private function logo(): array {
		$none = array(
			'mode'   => 'none',
			'src'    => '',
			'path'   => '',
			'width'  => 0,
			'height' => 0,
		);

		/**
		 * Filters the logo shown at the top of a notification.
		 *
		 * Must be a PNG, JPEG or GIF reachable without authentication — a mail client
		 * fetches it as an anonymous visitor, and many will not fetch it at all until
		 * the reader allows images, which is why the bundled default is embedded in
		 * the message rather than linked. Return '' to keep the bundled logo.
		 *
		 * @param string $url Absolute image URL, or '' for the bundled one.
		 */
		$url = (string) apply_filters( 'catalogops_notification_logo', '' );

		if ( '' !== $url ) {
			return array(
				'mode'   => 'url',
				'src'    => $url,
				'path'   => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		if ( ! defined( 'CATALOGOPS_PATH' ) ) {
			return $none;
		}

		$path = CATALOGOPS_PATH . 'assets/email-logo.png';

		if ( ! is_readable( $path ) ) {
			return $none;
		}

		$size = getimagesize( $path );

		if ( false === $size ) {
			return $none;
		}

		// The file is drawn at twice its display size so it stays sharp on a phone.
		// Reading the dimensions rather than hardcoding them means regenerating the
		// logo at a different width needs no change here.
		return array(
			'mode'   => 'cid',
			'src'    => 'cid:' . self::LOGO_CID,
			'path'   => $path,
			'width'  => (int) round( $size[0] / 2 ),
			'height' => (int) round( $size[1] / 2 ),
		);
	}

	/**
	 * The header: the brand, and the shop this is about.
	 */
	private function header(): string {
		$logo = $this->logo();

		if ( 'none' === $logo['mode'] ) {
			$mark = '<span style="font-size:17px;font-weight:700;color:' . self::COLOR_TEXT . '">CatalogOps</span>';
		} else {
			// The alt is the product's name and nothing else: it is what a reader with
			// images off actually sees, so it has to read as a masthead rather than as
			// a description of one.
			// `esc_url()` cannot be used on the embedded case: `cid` is not in
			// WordPress's allowed protocols, so it returns the empty string and the
			// mark silently disappears. The value there is this class's own constant
			// with no user input in it, so escaping it as an attribute is both
			// sufficient and correct; a filtered URL still goes through `esc_url()`.
			$src = 'cid' === $logo['mode'] ? esc_attr( $logo['src'] ) : esc_url( $logo['src'] );

			$mark = '<img src="' . $src . '" alt="CatalogOps"'
				. ( $logo['width'] > 0 ? ' width="' . $logo['width'] . '" height="' . $logo['height'] . '"' : ' height="28"' )
				. ' style="'
				. ( $logo['width'] > 0 ? 'width:' . $logo['width'] . 'px;height:' . $logo['height'] . 'px;' : 'height:28px;width:auto;' )
				. 'border:0;display:block;font-size:17px;font-weight:700;color:' . self::COLOR_TEXT . '" />';
		}

		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"><tr>'
			. '<td align="left" style="vertical-align:middle">' . $mark . '</td>'
			. '<td align="right" style="vertical-align:middle;color:' . self::COLOR_MUTED . ';font-size:13px">' . esc_html( $this->site() ) . '</td>'
			. '</tr></table>';
	}

	/**
	 * The footer: who to talk to about what this message says.
	 *
	 * Defaults to the shop's own identity rather than the plugin author's, because
	 * the reader of a scheduled-run report is usually not the person who installed
	 * the plugin — they are a colleague who needs to know whose shop this was and
	 * where to reply. An agency running this for a client replaces the lot through
	 * the filter.
	 */
	private function footer(): string {
		$home  = home_url( '/' );
		$admin = (string) get_option( 'admin_email' );

		$lines = array(
			'<strong style="color:' . self::COLOR_TEXT . '">' . esc_html( $this->site() ) . '</strong>'
				. ' &middot; <a href="' . esc_url( $home ) . '" style="color:' . self::COLOR_MUTED . '">' . esc_html( (string) wp_parse_url( $home, PHP_URL_HOST ) ) . '</a>',
		);

		if ( '' !== $admin ) {
			$lines[] = '<a href="mailto:' . esc_attr( $admin ) . '" style="color:' . self::COLOR_MUTED . '">' . esc_html( $admin ) . '</a>';
		}

		$lines[] = esc_html__( 'Sent automatically by CatalogOps. Reply to this address if something here looks wrong.', 'catalogops' );

		/**
		 * Filters the notification footer's HTML.
		 *
		 * @param string $footer The assembled footer markup.
		 */
		return (string) apply_filters( 'catalogops_notification_footer', implode( '<br />', $lines ) );
	}

	/**
	 * One labelled figure: a bold label, and a value in whatever colour it earns.
	 *
	 * @param string $label Row label, already translated.
	 * @param string $value The figure.
	 * @param string $color Value colour, or '' for the body colour.
	 * @param array  $notes Sub-lines under the figure — a skip breakdown.
	 */
	private function figure( string $label, string $value, string $color = '', array $notes = array() ): string {
		$html = '<tr>'
			. '<td style="padding:3px 14px 3px 0;font-weight:700;color:' . self::COLOR_TEXT . ';white-space:nowrap">' . esc_html( $label ) . '</td>'
			. '<td style="padding:3px 0;font-weight:700;color:' . ( '' !== $color ? $color : self::COLOR_TEXT ) . '">' . esc_html( $value ) . '</td>'
			. '</tr>';

		foreach ( $notes as $note ) {
			$html .= '<tr><td></td><td style="padding:0 0 3px;color:' . self::COLOR_MUTED . ';font-size:13px;font-weight:400">'
				. esc_html( $note ) . '</td></tr>';
		}

		return $html;
	}

	/**
	 * Wrap figure rows in their table.
	 *
	 * @param string $rows Rows from {@see figure()}.
	 */
	private function figures( string $rows ): string {
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;font-size:15px">' . $rows . '</table>';
	}

	/**
	 * A closing paragraph.
	 *
	 * @param string $text Plain text; escaped here.
	 */
	private function note( string $text ): string {
		return '<p style="margin:0;color:' . self::COLOR_MUTED . ';font-size:14px;line-height:1.5">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Render a GMT MySQL datetime on the shop's own clock.
	 *
	 * The report used to print the stored GMT value and label it "(GMT)", which was
	 * honest and still wrong for the reader: the history screen beside it prints
	 * local time, and a five-hour gap between two views of the same run was once
	 * reported as a schedule firing five hours early. Being consistently local
	 * across every surface is worth more than being explicit on one of them.
	 *
	 * Falls back to the raw stored value rather than an empty string — an
	 * unparseable timestamp is a curiosity, and a report that silently loses its
	 * only time is worse than one that shows an odd one.
	 *
	 * @param string $gmt GMT MySQL datetime.
	 */
	private function to_local( string $gmt ): string {
		$timestamp = strtotime( $gmt . ' UTC' );

		if ( false === $timestamp ) {
			return $gmt;
		}

		return wp_date(
			get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ),
			$timestamp
		);
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
	 * @param string    $html      HTML body.
	 * @param string    $text      Plain-text alternative.
	 */
	private function send( Operation $operation, string $recipient, string $subject, string $html, string $text ): void {
		/**
		 * Filters whether a notification is sent.
		 *
		 * The operation is passed so a site can silence one kind of message and keep
		 * the rest — most usefully, quieten the completion report for interactive
		 * work (`Operation_Source::UI`) while leaving scheduled runs, failures and
		 * self-pausing schedules audible. Returning false for everything turns the
		 * plugin silent, which is a choice a site is entitled to make and which this
		 * class will not second-guess.
		 *
		 * @param bool           $send      Whether to send (default true).
		 * @param Operation|null $operation The operation being reported on, or null
		 *                                  for a message about a schedule rather than
		 *                                  a run.
		 */
		if ( ! apply_filters( 'catalogops_send_notifications', true, $operation ) ) {
			return;
		}

		$this->mail( $recipient, $subject, $html, $text );
	}

	/**
	 * Send one message as HTML with a plain-text alternative beside it.
	 *
	 * The alternative is not politeness. An HTML-only message with no text part is
	 * one of the oldest and cheapest spam signals there is, and this plugin's mail
	 * is being sent by shops that have just wired up an SMTP relay for the first
	 * time and have no reputation to spend — the one message that must not land in
	 * a junk folder is the one saying a nightly schedule stopped. It also means a
	 * watch, a terminal client or a screen reader gets the same figures rather than
	 * a page of markup.
	 *
	 * `phpmailer_init` is the only seam WordPress offers for a multipart body, so
	 * the listener is added immediately before the send and removed immediately
	 * after: leaving it registered would staple this operation's report onto the
	 * next mail any plugin on the site happens to send.
	 *
	 * @param string $recipient Where to send it.
	 * @param string $subject   Subject line.
	 * @param string $html      HTML body.
	 * @param string $text      Plain-text alternative.
	 */
	private function mail( string $recipient, string $subject, string $html, string $text ): void {
		$logo = $this->logo();

		$alternative = static function ( $phpmailer ) use ( $text, $logo ): void {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer's own property name.
			$phpmailer->AltBody = $text;

			if ( 'cid' !== $logo['mode'] ) {
				return;
			}

			// Attached inline under the CID the header's <img> names, so the mark
			// renders without the reader having to allow remote images.
			$phpmailer->addEmbeddedImage( $logo['path'], self::LOGO_CID, 'catalogops.png', 'base64', 'image/png' );
		};

		add_action( 'phpmailer_init', $alternative );

		wp_mail( $recipient, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );

		remove_action( 'phpmailer_init', $alternative );
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

		$subject = sprintf( $this->subject_format( $operation ), $site, $label );

		// An undo counts in rows, an edit in distinct objects — the same distinction
		// {@see Changes::settled_counts()} makes, and getting it wrong here would put
		// the report's own arithmetic at odds with the bar the user watched.
		$targets = $operation->is_undo()
			? __( 'Changes to revert:', 'catalogops' )
			: __( 'Targets:', 'catalogops' );
		$changed = $operation->is_undo()
			? __( 'Reverted:', 'catalogops' )
			: __( 'Changed:', 'catalogops' );

		$opening = $this->opening( $operation, $label );

		$lines = array(
			$opening,
			'',
			$targets . '  ' . $operation->target_count,
			$changed . '  ' . $counts['applied'],
			__( 'Skipped:', 'catalogops' ) . '  ' . $counts['skipped'],
		);

		// A bare skipped count leaves the reader guessing at exactly the moment they
		// cannot come and look; the breakdown is the point of the report.
		$breakdown = array();

		foreach ( $this->changes->skip_reasons( $operation->id ) as $reason ) {
			$explanation = Skip_Reason::tryFrom( $reason['reason'] );

			$breakdown[] = $reason['count'] . ': ' . (
				null === $explanation
					? __( 'no reason recorded', 'catalogops' )
					: $explanation->label()
			);
		}

		foreach ( $breakdown as $entry ) {
			$lines[] = '  - ' . $entry;
		}

		$completed = sprintf(
			/* translators: %s: completion time, on the shop's own clock. */
			__( 'Completed at %s.', 'catalogops' ),
			$this->to_local( (string) $operation->completed_at )
		);

		$lines = array(
			...$lines,
			__( 'Failed:', 'catalogops' ) . '   ' . $counts['failed'],
			'',
			$completed,
		);

		// Zero is printed in the body colour, not in green, orange or red. A row of
		// coloured noughts trains the eye to ignore the colour, which is the one thing
		// it is here to do; a figure earns its colour by being non-zero.
		$rows = $this->figure( $targets, (string) $operation->target_count )
			. $this->figure( $changed, (string) $counts['applied'], $counts['applied'] > 0 ? self::COLOR_APPLIED : '' )
			. $this->figure( __( 'Skipped:', 'catalogops' ), (string) $counts['skipped'], $counts['skipped'] > 0 ? self::COLOR_SKIPPED : '', $breakdown )
			. $this->figure( __( 'Failed:', 'catalogops' ), (string) $counts['failed'], $counts['failed'] > 0 ? self::COLOR_FAILED : '' );

		return array(
			'recipient' => $recipient,
			'subject'   => $subject,
			'body'      => implode( "\n", $lines ),
			'html'      => $this->shell(
				esc_html( $opening ),
				$this->figures( $rows ),
				$this->note( $completed )
			),
		);
	}
}
