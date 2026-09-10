<?php
/**
 * The contact form: what happens when somebody presses Send.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * The action the form posts to, and the nonce it carries.
 */
const CATALOGOPS_FORM_ACTION = 'catalogops_contact';

/**
 * Handle a submitted contact form.
 *
 * **The honeypot is the whole anti-spam story, and that is a deliberate
 * trade.** reCAPTCHA would put Google back on a site whose entire privacy
 * position is that it loads nothing from anybody — it would undo the cookie
 * policy, the consent banner's absence and the transfer disclosure in one
 * script tag, to stop spam on a form that receives a handful of messages a
 * week. A field hidden from people and filled in by robots stops most of it and
 * costs nothing.
 *
 * A trapped submission is answered with the same "thank you" a real one gets.
 * Telling a robot it was caught is telling whoever wrote it what to change.
 */
function catalogops_handle_contact(): void {
	if ( ! isset( $_POST['catalogops_contact_nonce'] ) ) {
		return;
	}

	$nonce = sanitize_text_field( wp_unslash( (string) $_POST['catalogops_contact_nonce'] ) );

	if ( ! wp_verify_nonce( $nonce, CATALOGOPS_FORM_ACTION ) ) {
		catalogops_redirect_with( 'error' );
	}

	// The honeypot. Hidden with CSS and aria-hidden, no label a person can
	// reach, tabindex -1 and autocomplete off — anything in it was not typed.
	$trap = isset( $_POST['company'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['company'] ) ) ) : '';

	if ( '' !== $trap ) {
		catalogops_redirect_with( 'sent' );
	}

	$topic   = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['topic'] ) ) : '';
	$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( (string) $_POST['email'] ) ) : '';
	$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
	$site    = isset( $_POST['site'] ) ? esc_url_raw( wp_unslash( (string) $_POST['site'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '';

	if ( ! is_email( $email ) || '' === trim( $message ) ) {
		catalogops_redirect_with( 'invalid' );
	}

	$topics = array(
		'presale' => __( 'Before buying', 'catalogops' ),
		'bug'     => __( 'Something is not working', 'catalogops' ),
		'billing' => __( 'Billing, licence or refund', 'catalogops' ),
		'other'   => __( 'Something else', 'catalogops' ),
	);

	$subject = sprintf(
		/* translators: %s: the topic the sender chose. */
		__( '[CatalogOps] %s', 'catalogops' ),
		$topics[ $topic ] ?? __( 'Message', 'catalogops' )
	);

	$body = array(
		sprintf( __( 'From: %s', 'catalogops' ), '' !== $name ? $name . ' <' . $email . '>' : $email ),
		'' !== $site ? sprintf( __( 'Site: %s', 'catalogops' ), $site ) : '',
		'',
		$message,
	);

	/**
	 * Filters who a contact message is sent to.
	 *
	 * @param string $to The recipient address.
	 */
	$to = (string) apply_filters( 'catalogops_contact_recipient', (string) get_option( 'admin_email' ) );

	// Reply-To rather than From: sending as the visitor's own address is what
	// makes a message fail SPF and land in spam, so the site sends as itself and
	// the reply goes where it should.
	$sent = wp_mail(
		$to,
		$subject,
		implode( "\n", array_filter( $body, static fn( string $line ): bool => '' !== $line || true ) ),
		array( 'Reply-To: ' . ( '' !== $name ? $name . ' <' . $email . '>' : $email ) )
	);

	catalogops_redirect_with( $sent ? 'sent' : 'error' );
}
add_action( 'template_redirect', 'catalogops_handle_contact' );

/**
 * Answer a submission by redirecting back to the form with a result.
 *
 * Post/Redirect/Get, so a reload does not send the message twice and the back
 * button does not offer to.
 *
 * @param string $result `sent`, `invalid` or `error`.
 */
function catalogops_redirect_with( string $result ): void {
	wp_safe_redirect( add_query_arg( 'contact', $result, catalogops_page_url( 'contact' ) ) . '#form' );
	exit;
}

/**
 * The notice to show above the form, if the last thing that happened deserves one.
 *
 * @return array{tone: string, title: string, body: string}|null
 */
function catalogops_contact_notice(): ?array {
	$result = isset( $_GET['contact'] ) ? sanitize_key( wp_unslash( (string) $_GET['contact'] ) ) : '';

	switch ( $result ) {
		case 'sent':
			return array(
				'tone'  => 'good',
				'title' => __( 'Message sent.', 'catalogops' ),
				'body'  => __( 'We read everything and answer in a working day or two. If it is urgent, say so and it moves up.', 'catalogops' ),
			);

		case 'invalid':
			return array(
				'tone'  => 'danger',
				'title' => __( 'Not quite.', 'catalogops' ),
				'body'  => __( 'An email address we can reply to and a message are both needed. Nothing was sent, so nothing was lost — add what is missing and press Send again.', 'catalogops' ),
			);

		case 'error':
			return array(
				'tone'  => 'danger',
				'title' => __( 'That did not send.', 'catalogops' ),
				'body'  => __( 'Something went wrong at our end rather than yours. Please try once more, or write to us directly.', 'catalogops' ),
			);
	}

	return null;
}
