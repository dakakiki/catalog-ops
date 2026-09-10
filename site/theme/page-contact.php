<?php
/**
 * The contact page.
 *
 * Template Name: Contact
 *
 * @package CatalogOps_Theme
 */

get_header();
?>

<main class="wrap">

  <div class="page-head">
    <span class="eyebrow"><?php catalogops_the_text( 'contact_eyebrow', 'Contact' ); ?></span>
    <h1><?php catalogops_the_text( 'contact_heading', 'Write to us' ); ?></h1>
    <p class="lede"><?php catalogops_the_rich( 'contact_lede', 'A person reads every message — the same person who wrote the plugin. Answers usually come the same working day, and always within two.' ); ?></p>
  </div>

  <div class="contact">

    <div>
      <?php $notice = catalogops_contact_notice(); ?>
      <?php if ( null !== $notice ) : ?>
        <p class="notice notice--<?php echo esc_attr( $notice['tone'] ); ?>" role="status">
          <b><?php echo esc_html( $notice['title'] ); ?></b>
          <?php echo esc_html( $notice['body'] ); ?>
        </p>
      <?php endif; ?>

      <form id="form" method="post" action="<?php echo esc_url( catalogops_page_url( 'contact' ) ); ?>#form" novalidate>
        <?php wp_nonce_field( CATALOGOPS_FORM_ACTION, 'catalogops_contact_nonce' ); ?>
        <div class="field">
          <label for="topic">What is this about?</label>
          <select id="topic" name="topic" required>
            <option value="presale">Before buying — will it do what I need?</option>
            <option value="bug">Something is not working</option>
            <option value="billing">Billing, licence or refund</option>
            <option value="other">Something else</option>
          </select>
          <span class="hint">This decides who reads it first and how quickly.</span>
        </div>

        <div class="field">
          <label for="email">Your email</label>
          <input id="email" name="email" type="email" required autocomplete="email" placeholder="you@yourshop.com">
          <span class="hint">The only field we truly need — it is where the answer goes.</span>
        </div>

        <div class="field">
          <label for="name">Your name <span class="optional">optional</span></label>
          <input id="name" name="name" type="text" autocomplete="name" placeholder="So we know what to call you">
        </div>

        <div class="field">
          <label for="site">Your shop's address <span class="optional">optional</span></label>
          <input id="site" name="site" type="url" placeholder="https://yourshop.com">
          <span class="hint">Helps for anything that is not working. We do not visit it without asking.</span>
        </div>

        <div class="field">
          <label for="message">Your message</label>
          <textarea id="message" name="message" required placeholder="If something went wrong, what you expected and what happened instead is usually enough to find it."></textarea>
        </div>

        <div class="trap" aria-hidden="true">
          <label for="company">Company — leave this empty</label>
          <input id="company" name="company" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div class="submit-row">
          <button type="submit"><?php catalogops_the_text( 'contact_submit', 'Send message' ); ?></button>
        </div>

        <?php
        /*
         * The link is put in by the template and marked in the copy with %s, so
         * it cannot rot when the page is renamed and cannot be lost by an edit
         * that only meant to reword the sentence. A note written without the
         * marker simply has no link, which is the editor's business.
         */
        $catalogops_note = catalogops_text(
            'contact_privacy_note',
            'We use your message and your address only to answer you, and keep them so the history makes sense if you write again. Nothing here goes to a mailing list. See the %s.'
        );

        $catalogops_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( catalogops_page_url( 'legal', 'privacy' ) ),
            esc_html__( 'Privacy Policy', 'catalogops' )
        );
        ?>
        <p class="privacy-note"><?php echo wp_kses_post( str_replace( '%s', $catalogops_link, $catalogops_note ) ); ?></p>

      </form>
    </div>

    <?php
    /*
     * Three panels, and three because the design has three rules to hang them
     * from — the first takes the indigo one. Groups rather than a repeated row
     * for the same reason as everywhere else on this site: a fourth would need
     * a fourth rule drawn.
     */
    $catalogops_panels = array(
        'aside_1' => array(
            'Billing goes elsewhere',
            'Invoices, VAT and payments',
            'Freemius is the seller of record, so invoices, VAT numbers and card problems are theirs and they can act on them immediately. Write to us anyway if you are not sure — we will point you the right way rather than send you back.',
        ),
        'aside_2' => array(
            'If something broke',
            'What saves a round trip',
            'The operation number from the history, and what you expected against what happened. That is usually enough. Versions and active plugins help too, and we will ask if we need them.',
        ),
        'aside_3' => array(
            'Not sure yet',
            'You can try it first',
            'The free tier filters, previews and applies up to 200 objects per operation, on one site, for as long as you like. Often that answers "will it work on my catalogue" faster than we can.',
        ),
    );
    ?>
    <aside class="aside">
      <?php foreach ( $catalogops_panels as $catalogops_key => $catalogops_default ) : ?>
        <section>
          <span class="kicker"><?php echo esc_html( catalogops_group( $catalogops_key, 'kicker', $catalogops_default[0] ) ); ?></span>
          <h2><?php echo esc_html( catalogops_group( $catalogops_key, 'title', $catalogops_default[1] ) ); ?></h2>
          <p><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'body', $catalogops_default[2] ) ); ?></p>
        </section>
      <?php endforeach; ?>
    </aside>

  </div>

</main>

<?php
get_footer();
