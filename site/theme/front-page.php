<?php
/**
 * The landing page.
 *
 * Static rather than assembled from editor content, and deliberately: this is a
 * bespoke layout whose every section carries meaning in its markup — the
 * pipeline's stages are numbered by CSS counters, the preview card is a real
 * table, the pricing tiers carry the colour language the plugin itself uses.
 * Rebuilt out of blocks it would be a worse page that is harder to change.
 *
 * @package CatalogOps_Theme
 */

get_header();
?>

<main>

<section class="hero">
  <div class="wrap hero-grid">
    <div>
      <span class="eyebrow hero-eyebrow rise rise-1"><?php catalogops_the_text( 'hero_eyebrow', 'Bulk operations for WooCommerce' ); ?></span>
      <h1 class="rise rise-2"><?php catalogops_the_rich( 'hero_heading', 'Change 10,000 prices.<br>Then <span>change your mind.</span>' ); ?></h1>
      <p class="lede rise rise-3"><?php catalogops_the_rich( 'hero_lede', 'CatalogOps edits your catalogue in bulk — price, sale price, cost, stock — and records every single change, so any run can be put back exactly as it was. The preview tells you how many products will change. That is how many change.' ); ?></p>
      <div class="cta-row rise rise-4">
        <a class="btn btn-primary" href="<?php echo esc_url( catalogops_home_anchor( 'pricing' ) ); ?>"><?php catalogops_the_text( 'hero_cta_primary', 'See pricing' ); ?></a>
        <a class="btn btn-quiet" href="<?php echo esc_url( catalogops_home_anchor( 'how' ) ); ?>"><?php catalogops_the_text( 'hero_cta_secondary', 'How it works' ); ?></a>
      </div>
      <p class="cta-note rise rise-4" style="margin-top:1rem"><?php catalogops_the_text( 'hero_cta_note', 'Free tier, no account needed. Works on WooCommerce 9.0 and later.' ); ?></p>
    </div>

    <?php
    /*
     * The preview card is a picture of the product rather than a screenshot of
     * it, so it is markup: a real table, real chips, the same colour language
     * the plugin uses. That is what lets it be read aloud, printed, and set in
     * the reader's own type.
     *
     * The counter's `data-` attributes are DERIVED from the values rather than
     * typed beside them. Written by hand they were a second copy of the same
     * numbers, and a second copy is a copy that goes stale the first time
     * somebody edits one and not the other.
     */
    $catalogops_panel_rows = catalogops_lines(
        catalogops_group(
            'preview_panel',
            'rows',
            "Wireless charging pad | 24.00 | 20.99\nBraided USB-C cable, 2 m | 31.50 | 27.99\nLaptop stand, aluminium | 57.55 | 50.99\nDesk mat, felt | 42.00 | 36.99"
        )
    );

    $catalogops_count = catalogops_group( 'preview_panel', 'count', '1,204' );
    ?>
    <div class="panel rise rise-5" role="img" aria-label="<?php echo esc_attr( catalogops_group( 'preview_panel', 'alt', 'A preview panel showing 1,204 products will change, with sample before and after prices, and a completed run offering Undo.' ) ); ?>">
      <div class="panel-bar">
        <span class="panel-title"><?php echo esc_html( catalogops_group( 'preview_panel', 'title', 'Preview' ) ); ?></span>
        <span class="chip"><?php echo esc_html( catalogops_group( 'preview_panel', 'scope', 'Products' ) ); ?></span>
      </div>
      <div class="panel-body">
        <p class="count">
          <b class="tick" data-to="<?php echo esc_attr( (string) (int) str_replace( array( ',', ' ' ), '', $catalogops_count ) ); ?>"><?php echo esc_html( $catalogops_count ); ?></b>
          <span><?php echo esc_html( catalogops_group( 'preview_panel', 'caption', 'products will change' ) ); ?></span>
        </p>

        <div class="criteria">
          <?php foreach ( catalogops_lines( catalogops_group( 'preview_panel', 'criteria', "category is Accessories\nprice ≥ 20.00\nin stock" ) ) as $catalogops_chip ) : ?>
            <span class="chip"><?php echo esc_html( $catalogops_chip ); ?></span>
          <?php endforeach; ?>
          <span class="chip formula"><?php echo esc_html( catalogops_group( 'preview_panel', 'formula', 'regular_price → roundto( regular_price * 0.9, 1 ) − 0.01' ) ); ?></span>
        </div>

        <table class="rows">
          <thead>
            <tr>
              <th><?php echo esc_html( catalogops_group( 'preview_panel', 'col_product', 'Product' ) ); ?></th>
              <th class="num"><?php echo esc_html( catalogops_group( 'preview_panel', 'col_now', 'Now' ) ); ?></th>
              <th class="num"><?php echo esc_html( catalogops_group( 'preview_panel', 'col_after', 'After' ) ); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ( $catalogops_panel_rows as $catalogops_row ) : ?>
              <?php
              $catalogops_cells = array_map( 'trim', explode( '|', $catalogops_row, 3 ) );
              $catalogops_was   = $catalogops_cells[1] ?? '';
              $catalogops_now   = $catalogops_cells[2] ?? '';
              ?>
              <tr>
                <td class="name"><?php echo esc_html( $catalogops_cells[0] ); ?></td>
                <td class="num"><span class="was"><?php echo esc_html( $catalogops_was ); ?></span></td>
                <td class="num"><span class="now tick" data-from="<?php echo esc_attr( $catalogops_was ); ?>" data-to="<?php echo esc_attr( $catalogops_now ); ?>" data-dec="2"><?php echo esc_html( $catalogops_now ); ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="panel-foot">
        <span class="mono" style="font-size:.8rem"><?php echo esc_html( catalogops_group( 'preview_panel', 'foot', 'Run #148 · 1,204 changed · 0 failed' ) ); ?></span>
        <span class="undo"><?php echo esc_html( catalogops_group( 'preview_panel', 'undo', '↩ Undo this run' ) ); ?></span>
      </div>
    </div>
  </div>
</section>

<div class="strip strip--problem">
  <div class="wrap">
    <p class="reveal"><?php catalogops_the_rich( 'problem_text', 'Every other bulk editor is a one-way door. You type a percentage, you press go, and whatever happens to your catalogue is now simply the state of your catalogue. <strong>The undo is a restore from backup, and the backup is from last night.</strong>' ); ?></p>
  </div>
</div>

<section id="how">
  <div class="wrap">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'how_eyebrow', 'How it works' ); ?></span>
      <h2><?php catalogops_the_text( 'how_heading', 'Five stages, in this order, every time' ); ?></h2>
      <p class="lede"><?php catalogops_the_rich( 'how_lede', 'Nothing is written until you have seen the number and approved it — and the number cannot drift between the preview and the run, because one set of rules produces both.' ); ?></p>
    </div>

    <?php
    /*
     * Five, and the number is the design: the pipeline has five steps, the CSS
     * numbers them 01 to 05, and a sixth would need a sixth to be drawn. So
     * they are five groups of fields rather than a repeated row — adding one is
     * a change to the page, and ought to feel like one.
     */
    $catalogops_stages = array(
        'stage_1' => array( 'Filter', 'Category, brand, tag, price, stock, SKU, attribute, or a field from ACF. Target parent products or their variations.' ),
        'stage_2' => array( 'Preview', 'The count, and a sample of before-and-after values, computed by the rules that will run.' ),
        'stage_3' => array( 'Snapshot', "Each object's current value is recorded before a single byte is written." ),
        'stage_4' => array( 'Apply', 'Work runs in the background, in chunks. Stop it whenever you like. It survives a restart.' ),
        'stage_5' => array( 'Verify', 'When the run settles, the counts are reconciled from the recorded changes — so the history says what actually happened.' ),
    );
    ?>
    <div class="stages reveal">
      <?php $catalogops_n = 0; ?>
      <?php foreach ( $catalogops_stages as $catalogops_key => $catalogops_default ) : ?>
        <?php $catalogops_n++; ?>
        <div class="stage">
          <span class="stage-n"><?php echo esc_html( sprintf( '%02d', $catalogops_n ) ); ?></span>
          <h3><?php echo esc_html( catalogops_group( $catalogops_key, 'title', $catalogops_default[0] ) ); ?></h3>
          <p><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'body', $catalogops_default[1] ) ); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section style="padding-top:0">
  <div class="wrap">
    <?php
    $catalogops_promises = array(
        'promise_1' => array( 'The preview is the run', 'One set of rules counts the products and freezes the list, so the figure you approve is the figure that changes. A condition that cannot be answered stops the whole run rather than being quietly dropped — because a dropped condition means a <em>wider</em> edit than you asked for.' ),
        'promise_2' => array( 'Every change is on the record', 'Old value and new value, per object, per run. Any run can be reverted from the history in one click. Undo is deliberately one-way: a safety net, not a toggle you can flip back and forth until nobody knows what the price should be.' ),
        'promise_3' => array( 'An interrupted run finishes itself', 'If a worker dies mid-run, the operation notices, takes itself back and carries on with nobody at the screen. Verified by stopping the host halfway through a live run: it resumed by itself and finished on exactly the same figures.' ),
    );
    ?>
    <div class="promises">
      <?php foreach ( $catalogops_promises as $catalogops_key => $catalogops_default ) : ?>
        <div class="promise reveal">
          <div class="rule"></div>
          <h3><?php echo esc_html( catalogops_group( $catalogops_key, 'title', $catalogops_default[0] ) ); ?></h3>
          <p><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'body', $catalogops_default[1] ) ); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="formulas" class="strip" style="border-bottom:none">
  <div class="wrap" style="padding-block:clamp(3.5rem,8vw,6rem)">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'formulas_eyebrow', 'Formulas' ); ?></span>
      <h2><?php catalogops_the_text( 'formulas_heading', "Pricing rules, written the way you'd say them" ); ?></h2>
      <p class="lede"><?php catalogops_the_rich( 'formulas_lede', 'Set a value, adjust it by an amount or a percent, or write an expression over the fields you already have — including cost, so margin is a rule rather than a spreadsheet.' ); ?></p>
    </div>

    <?php
    /*
     * Four examples, each one a rule somebody actually asked for. The code and
     * the sentence beside it are a pair, so they are a group each rather than
     * two lists that could fall out of step.
     */
    $catalogops_formulas = array(
        'formula_1' => array( 'regular_price * 0.9', 'Ten percent off, everywhere the filter reaches.' ),
        'formula_2' => array( 'cost / 0.7', 'A 30% <em>margin</em> — not a 30% markup, which is a different and smaller number.' ),
        'formula_3' => array( 'roundto( cost / 0.7, 1 ) − 0.01', 'The same margin, landing on a price that ends in <em>.99</em>. <code style="background:none;border:none;padding:0">roundto</code> gives the nearest multiple, so to finish on .99 you round to the whole unit and take a cent back off.' ),
        'formula_4' => array( 'sale_price = regular_price * 0.8', 'A campaign you can lift in one click afterwards, because the run is on the record.' ),
    );
    ?>
    <div class="formula-list reveal">
      <?php foreach ( $catalogops_formulas as $catalogops_key => $catalogops_default ) : ?>
        <div class="formula-row">
          <code><?php echo esc_html( catalogops_group( $catalogops_key, 'code', $catalogops_default[0] ) ); ?></code>
          <p><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'body', $catalogops_default[1] ) ); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="scheduling">
  <div class="wrap">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'scheduling_eyebrow', 'Scheduling' ); ?></span>
      <h2><?php catalogops_the_text( 'scheduling_heading', "A schedule that doesn't compound" ); ?></h2>
      <p class="lede"><?php catalogops_the_rich( 'scheduling_lede', 'A recurring rule changes each object <em>at most once</em> — everything on its first run, and only newcomers after that. So an hourly &ldquo;reduce by 5%&rdquo; reduces by five percent, rather than five percent of five percent of five percent while you sleep.' ); ?></p>
    </div>

    <?php
    /*
     * Numbers, and the last one is a nought left uncoloured on purpose — the
     * same decision the plugin's own emailed run report makes. Colouring a
     * nought green congratulates the reader for nothing having happened.
     */
    $catalogops_facts = array(
        'fact_1' => array( '<b class="tick" data-to="31084">31,084</b>', 'products in the catalogue it is developed against, with 50,000 variations' ),
        'fact_2' => array( '<b><span class="tick" data-to="12.5" data-dec="1">12.5</span> / s</b>', 'objects written in safe mode — about 22 minutes for ten thousand products' ),
        'fact_3' => array( '<b>0</b>', 'changes written before you have seen the count and approved it' ),
    );
    ?>
    <div class="facts">
      <?php foreach ( $catalogops_facts as $catalogops_key => $catalogops_default ) : ?>
        <div class="fact reveal"><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'figure', $catalogops_default[0] ) ); ?><span><?php echo wp_kses_post( catalogops_group( $catalogops_key, 'caption', $catalogops_default[1] ) ); ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="pricing" class="strip" style="border-bottom:none">
  <div class="wrap" style="padding-block:clamp(3.5rem,8vw,6rem)">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'pricing_eyebrow', 'Pricing' ); ?></span>
      <h2><?php catalogops_the_text( 'pricing_heading', 'Annual, per site, no surprises' ); ?></h2>
      <p class="lede"><?php catalogops_the_rich( 'pricing_lede', 'If a licence lapses, the plugin keeps working exactly as it did. You stop receiving updates and support — you never lose access to your own catalogue tools.' ); ?></p>
    </div>

    <?php
    /*
     * Three columns, and the count is the design rather than data: a fourth
     * would need a fourth column drawn. So each is a group of fields, and the
     * things that differ between them — which one is highlighted, which button
     * is the loud one, whether there is a seats table — are decided here by
     * name rather than being three more fields for somebody to fill in wrongly.
     *
     * A feature line beginning with a minus is one the plan does NOT have, and
     * renders greyed. That is the whole syntax: a textarea reorders by dragging
     * a line and does not cap how many there are, which numbered fields do both.
     */
    $catalogops_plans = array(
        'plan_free'   => array(
            'variant'  => '',
            'button'   => 'btn-quiet',
            'name'     => 'Free',
            'tag'      => '',
            'price'    => '$0',
            'period'   => 'one site',
            'features' => "Filter, preview and apply\nSet a value, or adjust by an amount\nUp to 200 objects per operation\n-No undo\n-No percentages or formulas\n-No scheduling",
            'cta'      => 'Download',
            'url'      => '',
            'seats'    => '',
            'note'     => 'A zip you install like any plugin. Upgrade later without reinstalling.',
        ),
        'plan_solo'   => array(
            'variant'  => 'featured',
            'button'   => 'btn-primary',
            'name'     => 'Solo',
            'tag'      => 'Most shops',
            'price'    => '$99',
            'period'   => '/ year · one site',
            'features' => "Everything, with no object limit\nPercentages and formulas\nUndo any run from the history\nRecurring schedules\nEmail report after every run",
            'cta'      => 'Buy Solo',
            'url'      => 'https://checkout.freemius.com/plugin/36843/plan/61201/',
            'seats'    => '',
            'note'     => '',
        ),
        'plan_studio' => array(
            'variant'  => 'studio',
            'button'   => 'btn-quiet',
            'name'     => 'Studio',
            'tag'      => 'Agencies',
            'price'    => '$199',
            'period'   => '/ year · from',
            'features' => "Everything in Solo\nFilter by your ACF fields\nMultisite",
            'cta'      => 'Buy Studio',
            'url'      => 'https://checkout.freemius.com/plugin/36843/plan/61377/',
            'seats'    => "5 sites | $199\n25 sites | $399\nUnlimited | $699",
            'note'     => 'Choose the number of sites at checkout.',
        ),
    );
    ?>
    <div class="plans">
      <?php foreach ( $catalogops_plans as $catalogops_key => $catalogops_plan ) : ?>
        <?php
        $catalogops_url   = catalogops_group( $catalogops_key, 'url', $catalogops_plan['url'] );
        $catalogops_seats = catalogops_lines( catalogops_group( $catalogops_key, 'seats', $catalogops_plan['seats'] ) );
        $catalogops_note  = catalogops_group( $catalogops_key, 'note', $catalogops_plan['note'] );
        $catalogops_tag   = catalogops_group( $catalogops_key, 'tag', $catalogops_plan['tag'] );

        // The free tier has no checkout of its own: it is the download, and
        // where that lives is still moving — a direct zip until wp.org approves
        // the listing, a wp.org URL afterwards.
        if ( '' === $catalogops_url ) {
            $catalogops_url = catalogops_download_url();
        }
        ?>
        <div class="plan <?php echo esc_attr( trim( $catalogops_plan['variant'] . ' reveal' ) ); ?>">
          <div class="plan-name">
            <?php echo esc_html( catalogops_group( $catalogops_key, 'name', $catalogops_plan['name'] ) ); ?>
            <?php if ( '' !== $catalogops_tag ) : ?>
              <span class="tag"><?php echo esc_html( $catalogops_tag ); ?></span>
            <?php endif; ?>
          </div>
          <p class="price">
            <b><?php echo esc_html( catalogops_group( $catalogops_key, 'price', $catalogops_plan['price'] ) ); ?></b>
            <span><?php echo esc_html( catalogops_group( $catalogops_key, 'period', $catalogops_plan['period'] ) ); ?></span>
          </p>
          <ul>
            <?php foreach ( catalogops_lines( catalogops_group( $catalogops_key, 'features', $catalogops_plan['features'] ) ) as $catalogops_line ) : ?>
              <?php $catalogops_off = str_starts_with( $catalogops_line, '-' ); ?>
              <li<?php echo $catalogops_off ? ' class="off"' : ''; ?>><?php echo esc_html( ltrim( $catalogops_line, '- ' ) ); ?></li>
            <?php endforeach; ?>
          </ul>
          <?php if ( array() !== $catalogops_seats ) : ?>
            <div class="seats">
              <?php foreach ( $catalogops_seats as $catalogops_seat ) : ?>
                <?php $catalogops_parts = array_map( 'trim', explode( '|', $catalogops_seat, 2 ) ); ?>
                <div><span><?php echo esc_html( $catalogops_parts[0] ); ?></span><span><?php echo esc_html( $catalogops_parts[1] ?? '' ); ?></span></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <a class="btn <?php echo esc_attr( $catalogops_plan['button'] ); ?>" href="<?php echo esc_url( $catalogops_url ); ?>" style="justify-content:center" target="_blank" rel="noopener"><?php echo esc_html( catalogops_group( $catalogops_key, 'cta', $catalogops_plan['cta'] ) ); ?></a>
          <?php if ( '' !== $catalogops_note ) : ?>
            <p class="cta-note" style="font-size:.85rem;margin-top:-.3rem"><?php echo esc_html( $catalogops_note ); ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="guarantee reveal">
      <p class="guarantee-main">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
          <path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 0 10h-3"/>
        </svg>
        <span><?php catalogops_the_rich( 'guarantee_text', '<b>14 days, money back.</b> Any reason, no questions asked.' ); ?></span>
      </p>
      <p class="guarantee-note"><?php catalogops_the_text( 'guarantee_note', 'One guarantee per customer.' ); ?></p>
    </div>
  </div>
</section>

<section id="faq">
  <div class="wrap">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'faq_eyebrow', 'Questions' ); ?></span>
      <h2><?php catalogops_the_text( 'faq_heading', 'The ones worth asking before you buy' ); ?></h2>
    </div>

    <?php
    /*
     * The questions live under FAQ in the sidebar, one entry each, dragged into
     * the order they should be read in — a list that grows is a list of things,
     * and WordPress already has an editor for that. The shipped questions below
     * are what shows until somebody adds one, so a fresh install is a finished
     * page rather than a heading with a rule under it.
     */
    $catalogops_questions = catalogops_entries( 'co_faq' );
    ?>
    <?php if ( array() !== $catalogops_questions ) : ?>
      <div class="faq reveal">
        <?php foreach ( $catalogops_questions as $catalogops_post ) : ?>
          <details>
            <summary><?php echo esc_html( get_the_title( $catalogops_post ) ); ?></summary>
            <div class="faq-body"><div class="faq-body-inner">
              <?php echo wp_kses_post( apply_filters( 'the_content', $catalogops_post->post_content ) ); ?>
            </div></div>
          </details>
        <?php endforeach; ?>
      </div>
    <?php else : ?>
      <div class="faq reveal">
        <details>
          <summary>Can I try it before I buy it?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>Yes, and not on a countdown. The free tier filters, previews and applies for as long as you like — up to 200 objects in one operation, setting a value or adjusting by an amount. Percentages, formulas, undo and scheduling are the paid half.</p></div></div>
        </details>
  
        <details>
          <summary>Can I really undo anything?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>Every object's current value is recorded before a single byte is written, so any run can be reverted from the history in one click.</p>
          <p>Undo is deliberately one-way: a run that has been reverted does not then offer a redo. A safety net that works in both directions is a toggle, and a toggle is how nobody ends up sure what the price was supposed to be.</p></div></div>
        </details>
  
        <details>
          <summary>Can I get a refund?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>Within 14 days, for any reason, with no questions asked. You cancel, we refund the whole amount, and any conversation about why comes afterwards or not at all.</p>
  <p>The guarantee is one per customer. It exists so that a first purchase carries no risk — not as a way to use the plugin a fortnight at a time.</p></div></div>
        </details>
  
        <details>
          <summary>What happens when my licence expires?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>The plugin keeps working exactly as it did. You stop receiving updates and support — you never lose your catalogue tools, your operation history, or the ability to undo a run from months ago.</p></div></div>
        </details>
  
        <details>
          <summary>How many products can it handle?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>It is developed against a catalogue of 31,084 products with 50,000 variations. Work runs in the background in chunks, at about 12.5 objects a second — so ten thousand products take roughly 22 minutes, and you can keep using WordPress while it does.</p></div></div>
        </details>
  
        <details>
          <summary>What happens if my server restarts in the middle of a run?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>The run notices that its worker has gone, takes itself back, and carries on with nobody at the screen. Nothing is left half-applied and nothing needs a person to press resume.</p>
          <p>This is not a claim from a test suite: the host was stopped halfway through a live run of 1,596 objects. The run recovered by itself and finished on exactly the figures its preview had promised.</p></div></div>
        </details>
  
        <details>
          <summary>Do I need WP-CLI or server access to use scheduling?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>No. Schedules run on your host's ordinary cron, and the setup is a single line you paste into a Windows Task Scheduler dialog, a cPanel cron form, or wherever your host keeps them. The instructions sit directly above the schedule form rather than in a manual.</p></div></div>
        </details>
  
        <details>
          <summary>Does it work with variable products?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>Yes, and variations are a first-class target rather than an afterthought. A variable product keeps its price, sale price and stock on its variations, not on the parent — so a filter over parents would pass straight over the whole catalogue it was meant to find. You switch the scope to Variations and edit those.</p></div></div>
        </details>
  
        <details>
          <summary>Does it work with WPML?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>Yes, on every plan, the free one included. CatalogOps works in whichever language you are currently in and touches nothing outside it — knowing which products you are about to change is not a feature, it is the job.</p></div></div>
        </details>
  
        <details>
          <summary>Does it work with ACF?</summary>
          <div class="faq-body"><div class="faq-body-inner"><p>On the Studio plan you can filter by your own ACF fields: text, number, true/false, choices, dates, and a repeater's sub-field — so <code>specs_1_label</code> is a question you can ask, not a row index you have to guess.</p>
          <p>A field whose storage cannot be determined from its definition is refused with a stated reason rather than guessed at, because a filter that guesses wrong returns a plausible set of the wrong products.</p></div></div>
        </details>
      </div>
    <?php endif; ?>
  </div>
</section>

<section id="compatibility">
  <div class="wrap">
    <div class="section-head measure reveal">
      <span class="eyebrow"><?php catalogops_the_text( 'compat_eyebrow', 'Compatibility' ); ?></span>
      <h2><?php catalogops_the_text( 'compat_heading', 'What it runs on' ); ?></h2>
    </div>

    <?php $catalogops_rows = catalogops_entries( 'co_compat' ); ?>
    <?php if ( array() !== $catalogops_rows ) : ?>
      <dl class="compat reveal">
        <?php foreach ( $catalogops_rows as $catalogops_post ) : ?>
          <div class="compat-row">
            <dt><?php echo esc_html( get_the_title( $catalogops_post ) ); ?></dt>
            <?php
            /*
             * Through `the_content` like the FAQ, so a row written in the block
             * editor renders — and so a row that wants a link may have one.
             * The paragraph it wraps the sentence in costs nothing: this
             * stylesheet sets `p { margin: 0 }`, which is why the shipped rows
             * and an edited one sit at the same height.
             */
            ?>
            <dd><?php echo wp_kses_post( apply_filters( 'the_content', $catalogops_post->post_content ) ); ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    <?php else : ?>
      <dl class="compat reveal">
        <div class="compat-row">
          <dt>WooCommerce</dt>
          <dd>9.0 and later. Tested up to 11.0, including High-Performance Order Storage.</dd>
        </div>
        <div class="compat-row">
          <dt>WordPress</dt>
          <dd>Tested up to 7.1. No page builder, no theme requirements.</dd>
        </div>
        <div class="compat-row">
          <dt>ACF</dt>
          <dd>Filter by your own fields — text, number, true/false, choices, dates, and a repeater's sub-field. A field whose storage cannot be determined is refused with a reason, never guessed at.</dd>
        </div>
        <div class="compat-row">
          <dt>WPML</dt>
          <dd>CatalogOps works in whichever language you are currently in, and touches nothing outside it. On every plan, the free one included — knowing which products you are about to change is not a feature, it is the job.</dd>
        </div>
        <div class="compat-row">
          <dt>Scheduling</dt>
          <dd>Runs on your host's cron. Setup is one line, and the instructions sit above the schedule form rather than in a manual.</dd>
        </div>
      </dl>
    <?php endif; ?>
  </div>
</section>

</main>

<?php
get_footer();
