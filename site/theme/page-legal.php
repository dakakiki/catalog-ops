<?php
/**
 * The five legal documents, on one page.
 *
 * One page rather than five, because they are read together and cross-refer:
 * the refund policy only makes sense beside the terms, and the cookie policy is
 * four sentences long. The sticky index beside them is what makes one long page
 * navigable.
 *
 * Assign this template to a page with the slug `legal` (Page Attributes >
 * Template), or simply name the page `legal` — {@see catalogops_page_url()}
 * resolves the footer's links by that slug.
 *
 * Template Name: Legal documents
 *
 * @package CatalogOps_Theme
 */

get_header();
?>

<main class="wrap">

  <div class="page-head">
    <span class="eyebrow"><?php catalogops_the_text( 'legal_eyebrow', 'Legal' ); ?></span>
    <h1><?php catalogops_the_text( 'legal_heading', 'The five documents' ); ?></h1>
    <p class="lede"><?php catalogops_the_rich( 'legal_lede', 'Written against what this site and this plugin actually do, rather than assembled from a template. Where something is unusual — the seller is not us, the code is GPL, the plugin sends nothing without permission — it is said plainly instead of being buried.' ); ?></p>
    <?php
    /*
     * The draft warning. Emptying this field removes the box, which is what to
     * do the day a lawyer has read these — a warning that outlives its reason
     * teaches readers to skip warnings.
     */
    $catalogops_draft = catalogops_text( 'legal_draft_note', '<p><b>These are drafts.</b> Accurate to the best of our knowledge and specific to this product, but not legal advice. Have them read by a lawyer before they stand beside a payment button. Every <code class="fill">[BRACKET]</code> is a value still to be filled in.</p>' );
    ?>
    <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_draft ) ) ) : ?>
      <div class="draft"><?php echo wp_kses_post( $catalogops_draft ); ?></div>
    <?php endif; ?>
  </div>

  <div class="legal">

    <nav class="legal-nav" aria-label="Legal documents">
      <?php
      /*
       * The index is built from the same list the documents are, so renaming a
       * document renames its entry here. Two hand-kept lists of the same five
       * things is one list that goes stale.
       */
      $catalogops_docs = array(
          'privacy' => 'Privacy Policy',
          'cookies' => 'Cookie Policy',
          'terms'   => 'Terms of Service',
          'refunds' => 'Refund Policy',
          'licence' => 'Plugin Licence',
      );
      ?>
      <div class="legal-nav-title"><?php catalogops_the_text( 'legal_nav_title', 'Documents' ); ?></div>
      <ol>
        <?php foreach ( $catalogops_docs as $catalogops_slug => $catalogops_name ) : ?>
          <li><a href="#<?php echo esc_attr( $catalogops_slug ); ?>"><?php echo esc_html( catalogops_text( 'legal_' . $catalogops_slug . '_title', $catalogops_name ) ); ?></a></li>
        <?php endforeach; ?>
      </ol>
    </nav>

    <div>

      <!-- ============================================================ 01 -->
      <article id="privacy">
        <header>
          <h2><?php catalogops_the_text( 'legal_privacy_title', 'Privacy Policy' ); ?></h2>
          <p class="updated"><?php
            /* translators: %s: the date this document was last changed. */
            printf( esc_html__( 'Last updated %s', 'catalogops' ), wp_kses_post( catalogops_text( 'legal_privacy_updated', '<code class="fill">[DATE]</code>' ) ) );
          ?></p>
        </header>
        <?php $catalogops_body = catalogops_text( 'legal_privacy_body', '' ); ?>
        <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_body ) ) ) : ?>
          <?php echo wp_kses_post( $catalogops_body ); ?>
        <?php else : ?>
          <h3>Who is responsible</h3>
          <p>CatalogOps is run by Davor Kikindjanin, an individual trader in Serbia.</p>
          <ul>
            <li>Email: <strong>contact@catalog-ops.app</strong></li>
            <li>Postal address: available on request at the address above.</li>
          </ul>
          <p>Serbia is outside the European Economic Area. Where this policy concerns people in the EU or the UK, it is written to meet the GDPR and the UK GDPR, which apply because CatalogOps is offered to customers there.</p>
  
          <h3>The short version</h3>
          <p class="big">This website sets no cookies, runs no advertising, and shares nothing with anyone for marketing. The plugin sends nothing at all unless you explicitly allow it. Purchases are handled by Freemius, not by us, so we never see your card.</p>
          <p>The rest of this document is the same thing said precisely.</p>
  
          <h3>What the website collects</h3>
          <p><strong>Server logs.</strong> Every request to catalog-ops.app is written to an ordinary web-server log: IP address, date and time, the page requested, the referring page, and the browser's user-agent string. These exist to keep the site running and to investigate abuse.</p>
          <ul>
            <li>Legal basis: legitimate interest in operating and securing the site.</li>
            <li>Retention: <strong>7 days</strong>, then deleted — the period our host, Hetzner, keeps web-server and mail-server logs for.</li>
          </ul>
          <p><strong>Audience measurement.</strong> Visits are counted with <a href="https://plausible.io" target="_blank" rel="noopener">Plausible Analytics</a>, hosted in the European Union. Plausible sets no cookies, stores no persistent identifier, and does not follow you between websites. It records the page, the referrer, the country and the device type as aggregate counts — there is no profile, and no way to single you out from what it keeps.</p>
          <ul>
            <li>Legal basis: legitimate interest in knowing which pages are read.</li>
            <li>Because nothing is stored on your device and no identifier is kept, this does not require your consent — which is precisely why it was chosen over the alternatives.</li>
          </ul>
          <p><strong>Fonts and other assets</strong> are served from our own server. No request goes to Google, to a CDN, or to any other third party while you read this site.</p>
  
          <h3>What happens when you buy</h3>
          <p>Purchases go through <strong>Freemius Inc.</strong>, which is the seller and merchant of record. Your purchase contract is with Freemius. They take the payment, issue the invoice, handle tax, and process your billing details.</p>
          <p><strong>We never receive or store your card details.</strong> What reaches us is the information needed to support a licence: your email address, the plan you bought, and the sites your licence is activated on.</p>
          <p>Freemius acts as an independent controller for the payment itself. Their handling of your data is governed by <a href="https://freemius.com/privacy/" target="_blank" rel="noopener">Freemius's privacy policy</a>.</p>
  
          <h3>What the plugin sends, and only if you allow it</h3>
          <p>When CatalogOps is activated, Freemius shows a screen asking permission to connect your site. <strong>It has a "Skip" button, and skipping costs you nothing in the plugin's day-to-day work.</strong></p>
          <p>If — and only if — you choose <em>Allow</em>, the following is sent to Freemius:</p>
          <ul>
            <li>your site's URL and the email address of the administrator who activated it,</li>
            <li>the WordPress and PHP versions,</li>
            <li>a list of active plugins and themes,</li>
            <li>and, later, licence activations and deactivations.</li>
          </ul>
          <p>This is used for licensing, update delivery and support. <strong>If you skip, none of it is sent</strong> — the plugin runs, filters, previews and applies exactly the same. The one thing you give up is automatic update notices, because the plugin has no way to ask whether a new version exists without identifying itself.</p>
          <p>You can withdraw this at any time from the plugin's own settings. Legal basis: your consent.</p>
          <div class="callout">
            <p><strong>CatalogOps never sends your product data anywhere.</strong> Prices, stock, SKUs, customer records and orders stay in your database. The plugin reads and writes them on your own server and transmits none of it.</p>
          </div>
  
          <h3>Email you send us</h3>
          <p>If you write to contact@catalog-ops.app, we keep the message and your address for as long as needed to answer you and to understand the history if you write again. Legal basis: legitimate interest in answering support, or performance of the contract where you hold a licence.</p>
  
          <h3>Who else sees any of this</h3>
          <table>
            <thead><tr><th>Who</th><th>What for</th></tr></thead>
            <tbody>
              <tr><td>Freemius</td><td>Payments, licensing, updates</td></tr>
              <tr><td>Hetzner Online GmbH (Germany)</td><td>The server this site runs on, and the mailbox that receives what you send us — our processor under a data-processing agreement, inside the EEA</td></tr>
              <tr><td>Plausible</td><td>Aggregate visit counts, hosted in the EU</td></tr>
            </tbody>
          </table>
          <p>Nobody else. Your data is not sold, rented, or handed to advertisers, and it is not used to build a profile of you.</p>
  
          <h3>International transfers</h3>
          <p><strong>Nothing this site collects is stored outside the European Economic Area.</strong> The server, its logs and the mailbox that receives your messages are all with Hetzner in Germany. Audience measurement is with Plausible in the European Union.</p>
          <p><strong>How we work with it in practice.</strong> We use each of these services through its own web interface and nothing else. Mail is read in Hetzner's webmail, so there is no mail client and no copy of your message on any machine of ours. Customer records are read in the Freemius dashboard; we do not export them, and we keep no list of customers anywhere else. If that ever changes — if we start sending a newsletter, for instance — this page changes with it, before it does.</p>
          <p>We are established in Serbia, which is outside the EEA and is <strong>not</strong> covered by an adequacy decision of the European Commission. We reach the data described above by logging in to those EEA services from Serbia; it is not copied here in the ordinary course.</p>
          <p>Because that data stays with our processors inside the EEA, and we are its controller rather than a further recipient, we do not rely on a transfer mechanism for it. What you send us through the contact form you send directly and on your own initiative, which under the European Data Protection Board's guidance is not a transfer at all.</p>
          <p>The GDPR and the UK GDPR apply to us directly, because CatalogOps is offered to customers in those territories.</p>
          <p><strong>Freemius Inc.</strong> is the one service outside the EEA: it is in the United States, and its data-processing agreement incorporates the European Commission's Standard Contractual Clauses of 2021 for transfers.</p>
  
          <h3>Your rights</h3>
          <p>If the GDPR or UK GDPR applies to you, you may ask us to give you a copy of what we hold, correct it, delete it, restrict what we do with it, or object to it — and to receive it in a portable form. Where we rely on consent, you may withdraw it at any time without affecting what was done before.</p>
          <p>Write to <strong>contact@catalog-ops.app</strong>. We will answer within one month.</p>
          <p>You may also complain to a supervisory authority: in the EU, the one in your country of residence; in Serbia, the Commissioner for Information of Public Importance and Personal Data Protection.</p>
  
          <h3>Children</h3>
          <p>CatalogOps is a tool for running an online shop. It is not directed at children and we do not knowingly collect anything from them.</p>
  
          <h3>Changes</h3>
          <p>If this policy changes, the date at the top changes with it. Material changes will be announced on the site.</p>
        <?php endif; ?>
      </article>

      <!-- ============================================================ 02 -->
      <article id="cookies">
        <header>
          <h2><?php catalogops_the_text( 'legal_cookies_title', 'Cookie Policy' ); ?></h2>
          <p class="updated"><?php
            /* translators: %s: the date this document was last changed. */
            printf( esc_html__( 'Last updated %s', 'catalogops' ), wp_kses_post( catalogops_text( 'legal_cookies_updated', '<code class="fill">[DATE]</code>' ) ) );
          ?></p>
        </header>
        <?php $catalogops_body = catalogops_text( 'legal_cookies_body', '' ); ?>
        <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_body ) ) ) : ?>
          <?php echo wp_kses_post( $catalogops_body ); ?>
        <?php else : ?>
          <p class="big">This website sets no cookies. Not "only essential ones". None.</p>
          <p>That is why there is no consent banner here. A banner exists to ask permission to put something on your device; there is nothing on this site to ask about.</p>
  
          <h3>What that took</h3>
          <p>A site sets no cookies only on purpose, so here is what was deliberately left out:</p>
          <ul>
            <li><strong>Fonts are served from our own server</strong>, not from Google Fonts. Loading them from Google would send your IP address there before you had any say in it.</li>
            <li><strong>Audience measurement is Plausible</strong>, hosted in the EU, which counts visits without a cookie and without any identifier that follows you. It cannot tell one reader from another; it only knows a page was read.</li>
            <li><strong>No embedded videos, maps, social widgets or avatars.</strong> These are the usual quiet culprits — one embedded player is enough to put a third party's cookies on your device on a page that claims to have none.</li>
            <li><strong>No advertising or tracking pixels</strong>, of any kind.</li>
            <li><strong>Comments are disabled</strong>, because a WordPress comment form stores your name and email on your device for the next time.</li>
          </ul>
  
          <h3>Where cookies do appear, and why it is not this site</h3>
          <p><strong>When you buy.</strong> Checkout runs on checkout.freemius.com, which is Freemius's service, not ours. That page needs cookies to hold your basket and complete the payment, and it is covered by <a href="https://freemius.com/privacy/" target="_blank" rel="noopener">Freemius's own policy</a>. It is a different site; we mention it so the transition does not surprise you.</p>
          <p><strong>Inside your own WordPress admin.</strong> The CatalogOps plugin runs in your dashboard, where WordPress sets its own login and session cookies. Those belong to your installation and have nothing to do with this website. The plugin adds none of its own.</p>
  
          <h3>Server logs are not cookies</h3>
          <p>Our web server writes an ordinary access log — your IP address, the time, and the page requested. That is a record kept on our side, not something stored on your device, so it needs no consent. It is described in the <a href="#privacy">Privacy Policy</a>, which also says how long it is kept.</p>
  
          <h3>If this ever changes</h3>
          <p>If we add anything that stores data on your device, this page changes first and a consent request appears with it — before the thing that needs consent, not after.</p>
        <?php endif; ?>
      </article>

      <!-- ============================================================ 03 -->
      <article id="terms">
        <header>
          <h2><?php catalogops_the_text( 'legal_terms_title', 'Terms of Service' ); ?></h2>
          <p class="updated"><?php
            /* translators: %s: the date this document was last changed. */
            printf( esc_html__( 'Last updated %s', 'catalogops' ), wp_kses_post( catalogops_text( 'legal_terms_updated', '<code class="fill">[DATE]</code>' ) ) );
          ?></p>
        </header>
        <?php $catalogops_body = catalogops_text( 'legal_terms_body', '' ); ?>
        <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_body ) ) ) : ?>
          <?php echo wp_kses_post( $catalogops_body ); ?>
        <?php else : ?>
          <h3>Who you are dealing with</h3>
          <p>CatalogOps is made and supported by Davor Kikindjanin, an individual trader in Serbia, reachable at <strong>contact@catalog-ops.app</strong>.</p>
  
          <h3>Who sells you the licence</h3>
          <p><strong>Freemius Inc. is the seller and merchant of record.</strong> When you buy a CatalogOps licence, your purchase contract is with Freemius: they take the payment, issue the invoice, handle sales tax and VAT, and manage your subscription.</p>
          <p>This matters in practice. Billing questions, invoices, VAT numbers and payment disputes are theirs. Everything about the software — what it does, whether it works, and what to do when it does not — is ours. <a href="https://freemius.com/terms/" target="_blank" rel="noopener">Freemius's terms</a> govern the transaction itself.</p>
  
          <h3>What a licence gives you</h3>
          <p>The CatalogOps code is free software under the GPL and always will be — see the <a href="#licence">Plugin Licence</a>. <strong>You are not buying permission to use the code.</strong> What a paid licence gives you is:</p>
          <ul>
            <li>a key that unlocks the paid capabilities inside the plugin — percentages, formulas, undo, scheduling, and, on Studio, the ACF fields,</li>
            <li>software updates for as long as the licence is active,</li>
            <li>support by email for as long as the licence is active,</li>
            <li>for as many sites as your plan covers: one for Solo, five, twenty-five or unlimited for Studio.</li>
          </ul>
          <div class="callout">
            <p><strong>When a licence expires, the plugin keeps working exactly as it did.</strong> You stop receiving updates and support. Nothing is taken away, your operation history stays, and a run from months ago can still be undone.</p>
          </div>
  
          <h3>The free tier</h3>
          <p>CatalogOps can be used without paying, for as long as you like, on one site: it filters, previews and applies, up to 200 objects in a single operation, setting a value or adjusting by an amount. It is not a trial and it does not expire.</p>
  
          <h3>What you are responsible for</h3>
          <p><strong>Backups.</strong> CatalogOps writes to your products. Every change is recorded and every run can be reverted, and that is a strong safety net — but it is a safety net inside your database, not a substitute for a backup of it. Take a backup before a large operation. The plugin asks you to confirm you have one, and that confirmation is not a formality.</p>
          <p><strong>Your own use of it.</strong> You decide which products a filter selects and what happens to them. The preview tells you the count before anything is written; the decision to proceed is yours.</p>
          <p><strong>Your environment.</strong> The plugin needs WooCommerce 9.0 or later and a working WordPress installation. Scheduling needs your host's cron to actually run.</p>
  
          <h3>Support</h3>
          <p>Support is by email at contact@catalog-ops.app, in English, for people with an active licence. We answer questions about installing, configuring and using CatalogOps, and we investigate anything that looks like a defect.</p>
          <p>Support does not extend to writing your pricing rules for you, to fixing unrelated problems on your site, or to conflicts caused by other plugins that we cannot reproduce — though we will always try to tell you what we found.</p>
  
          <h3>Refunds</h3>
          <p>Fourteen days, any reason, no questions asked. The whole policy is in the <a href="#refunds">Refund Policy</a>.</p>
  
          <h3>What we do not promise</h3>
          <p>The software is provided as it is. We do not promise it is free of defects, that it will suit a particular purpose, or that it will run uninterrupted — no software of this kind can honestly promise that.</p>
          <p>To the extent the law allows, we are not liable for indirect or consequential loss, for lost profit, or for loss of data. Where liability cannot be excluded, it is limited to what you paid for the licence in the twelve months before the claim.</p>
          <p><strong>None of this limits your rights as a consumer under the law of your own country.</strong> Where those rights conflict with this section, they win.</p>
  
          <h3>Acceptable use</h3>
          <p>Do not use CatalogOps to break the law, and do not attempt to strip or circumvent the licensing in the distributed build. The code is GPL and you may modify it freely for your own use; what is not fine is redistributing a build altered to defeat the licence check while presenting it as CatalogOps.</p>
  
          <h3>Governing law</h3>
          <p>These terms are governed by the law of the Republic of Serbia. If you are a consumer resident elsewhere, the mandatory consumer-protection law of your own country applies to you regardless of this clause.</p>
  
          <h3>Changes</h3>
          <p>These terms may change. The date at the top changes with them, and a material change is announced on the site. A change never applies retroactively to a licence you have already bought.</p>
        <?php endif; ?>
      </article>

      <!-- ============================================================ 04 -->
      <article id="refunds">
        <header>
          <h2><?php catalogops_the_text( 'legal_refunds_title', 'Refund Policy' ); ?></h2>
          <p class="updated"><?php
            /* translators: %s: the date this document was last changed. */
            printf( esc_html__( 'Last updated %s', 'catalogops' ), wp_kses_post( catalogops_text( 'legal_refunds_updated', '<code class="fill">[DATE]</code>' ) ) );
          ?></p>
        </header>
        <?php $catalogops_body = catalogops_text( 'legal_refunds_body', '' ); ?>
        <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_body ) ) ) : ?>
          <?php echo wp_kses_post( $catalogops_body ); ?>
        <?php else : ?>
          <p class="big">Fourteen days, any reason, no questions asked.</p>
          <p>If CatalogOps is not what you wanted, tell us within 14 days of your purchase and you get 100% of your money back. You do not have to explain why, and you will not be asked to.</p>
          <p>That is the whole promise. The rest of this document is only detail.</p>
  
          <h3>Why it is worded that way</h3>
          <p>CatalogOps writes to your prices. Asking someone to trust unfamiliar software with that, from a developer they have never heard of, is asking a lot — and a guarantee that arrives with conditions attached is not much of a guarantee.</p>
          <p>So there are no conditions. Not "if you found a bug we could not fix". Not "if you have not downloaded it yet". You changed your mind; that is reason enough.</p>
  
          <h3>How to ask</h3>
          <p>Email <strong>contact@catalog-ops.app</strong> from the address you bought with, or use the contact option in your Freemius account. One line is enough.</p>
          <p>What then happens, in order:</p>
          <ol class="steps">
            <li>Your subscription is cancelled, so nothing renews.</li>
            <li>Your licence is cancelled.</li>
            <li>The full amount is refunded.</li>
          </ol>
          <p>We do not delay it to ask what went wrong. If we ask anything, it is <em>after</em> the money has gone back, it is one question, and you are free to ignore it.</p>
          <p>The refund is issued by Freemius, who is the merchant of record for the sale, and lands back on the card or account you paid with. How long it takes to appear depends on your bank — usually a few working days.</p>
  
          <h3>After the fourteen days</h3>
          <p>The guarantee period is over, but you are not stuck with anything:</p>
          <ul>
            <li><strong>Cancel the subscription</strong> at any time from your Freemius account and it will not renew.</li>
            <li><strong>The plugin keeps working</strong> after a licence lapses. You stop getting updates and support; you do not lose your catalogue tools, your operation history, or the ability to undo a run you made months ago.</li>
          </ul>
  
          <h3>Renewals</h3>
          <p>A subscription renews once a year unless you cancel it. Freemius emails you before it does.</p>
          <p>If a renewal takes you by surprise, write to us. We are not interested in keeping money from someone who did not mean to spend it, and this is a case where we will simply look at it rather than point at a date.</p>
        <?php endif; ?>
      </article>

      <!-- ============================================================ 05 -->
      <article id="licence">
        <header>
          <h2><?php catalogops_the_text( 'legal_licence_title', 'Plugin Licence' ); ?></h2>
          <p class="updated"><?php
            /* translators: %s: the date this document was last changed. */
            printf( esc_html__( 'Last updated %s', 'catalogops' ), wp_kses_post( catalogops_text( 'legal_licence_updated', '<code class="fill">[DATE]</code>' ) ) );
          ?></p>
        </header>
        <?php $catalogops_body = catalogops_text( 'legal_licence_body', '' ); ?>
        <?php if ( '' !== trim( wp_strip_all_tags( $catalogops_body ) ) ) : ?>
          <?php echo wp_kses_post( $catalogops_body ); ?>
        <?php else : ?>
          <h3>CatalogOps is free software</h3>
          <p>CatalogOps is a WordPress plugin, and WordPress plugins are derivative works of WordPress. CatalogOps is therefore released under the <strong>GNU General Public License, version 2 or later</strong> — the same licence WordPress itself uses.</p>
          <p>That is not a concession. It is what the licence of the thing we build on requires, and we would rather state it plainly than bury it.</p>
  
          <h3>What the GPL gives you</h3>
          <p>Under the GPL you may:</p>
          <ul>
            <li><strong>run</strong> the plugin, for any purpose, on any number of sites,</li>
            <li><strong>study</strong> how it works and change it,</li>
            <li><strong>redistribute</strong> copies,</li>
            <li><strong>distribute your modified versions</strong>, under the same GPL terms.</li>
          </ul>
          <p>Nothing on this website takes any of that away, and nothing in our <a href="#terms">Terms of Service</a> attempts to. A clause purporting to restrict your use of GPL code would be void, so writing one would only mislead you.</p>
  
          <h3>Then what exactly are you paying for?</h3>
          <p class="big">Not permission to use the code. You already have that.</p>
          <p>A paid licence is a key that buys three things:</p>
          <ol class="steps">
            <li><strong>The paid capabilities inside the plugin</strong> — percentages, formulas, undo, scheduling, and, on Studio, filtering by your ACF fields. The code that implements them ships in the same file everyone downloads; the key is what unlocks it.</li>
            <li><strong>Updates</strong> for as long as the licence is active, delivered automatically.</li>
            <li><strong>Support</strong> by email for as long as the licence is active.</li>
          </ol>
          <p>This is the ordinary arrangement for commercial WordPress plugins, and it is compatible with the GPL because what is sold is the service and the delivery, not the right to use the software.</p>
  
          <h3>What we ask of you</h3>
          <p>The licence check is not a legal restriction on the code; it is how a very small business stays alive. You are free to modify CatalogOps for your own site.</p>
          <p>What we ask — and it is a request, resting on the terms you agreed to rather than on copyright — is that you not distribute a build altered to defeat the licence check while still calling it CatalogOps. That harms the people paying for the work and misleads whoever installs it.</p>
  
          <h3>Third-party components</h3>
          <table>
            <thead><tr><th>Component</th><th>Licence</th></tr></thead>
            <tbody>
              <tr><td>WordPress, WooCommerce</td><td>GPLv2 or later</td></tr>
              <tr><td>Freemius WordPress SDK</td><td>GPLv3</td></tr>
              <tr><td>Action Scheduler</td><td>GPLv3</td></tr>
              <tr><td>Archivo, Newsreader, IBM Plex Mono</td><td>SIL Open Font License 1.1</td></tr>
            </tbody>
          </table>
          <p>The typefaces are served from our own server, and their licence and copyright notices travel with the font files.</p>
  
          <h3>Warranty</h3>
          <p>As the GPL puts it, and we will not dress it up: the program is provided without warranty, to the extent permitted by law. Your rights as a consumer under the law of your own country are not affected by that sentence, and neither is our <a href="#refunds">refund guarantee</a>, which is a promise we make on top of it.</p>
  
          <h3>The full text</h3>
          <p>The complete GNU General Public License is included in the plugin package as <code>LICENSE</code>, and is published at <a href="https://www.gnu.org/licenses/old-licenses/gpl-2.0.html" target="_blank" rel="noopener">gnu.org</a>.</p>
        <?php endif; ?>
      </article>

    </div>
  </div>

</main>

<?php
get_footer();
