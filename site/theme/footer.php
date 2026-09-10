
<footer>
  <div class="wrap">
    <a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
      <svg class="brand-mark brand-mark--lg" role="img" aria-label="CatalogOps"><use href="#co-mark"/></svg>
      <span class="brand-text">
        <span class="brand-name">Catalog<em>Ops</em></span>
        <span class="brand-tag">Bulk catalog operations</span>
      </span>
    </a>
    <div class="footer-side">
      <nav class="footer-nav">
        <a href="<?php echo esc_url( catalogops_home_anchor( 'how' ) ); ?>">How it works</a>
        <a href="<?php echo esc_url( catalogops_home_anchor( 'formulas' ) ); ?>">Formulas</a>
        <a href="<?php echo esc_url( catalogops_home_anchor( 'scheduling' ) ); ?>">Scheduling</a>
        <a href="<?php echo esc_url( catalogops_home_anchor( 'pricing' ) ); ?>">Pricing</a>
        <a href="<?php echo esc_url( catalogops_home_anchor( 'faq' ) ); ?>">FAQ</a>
        <a href="<?php echo esc_url( catalogops_home_anchor( 'compatibility' ) ); ?>">Compatibility</a>
        <a href="<?php echo esc_url( catalogops_download_url() ); ?>" target="_blank" rel="noopener">Download</a>
      </nav>
      <nav class="footer-meta">
        <a href="<?php echo esc_url( catalogops_page_url( 'contact' ) ); ?>">Contact</a>
        <a href="<?php echo esc_url( catalogops_page_url( 'legal', 'privacy' ) ); ?>">Privacy</a>
        <a href="<?php echo esc_url( catalogops_page_url( 'legal', 'cookies' ) ); ?>">Cookies</a>
        <a href="<?php echo esc_url( catalogops_page_url( 'legal', 'terms' ) ); ?>">Terms</a>
        <a href="<?php echo esc_url( catalogops_page_url( 'legal', 'refunds' ) ); ?>">Refunds</a>
        <a href="<?php echo esc_url( catalogops_page_url( 'legal', 'licence' ) ); ?>">Licence</a>
      </nav>
      <p>catalog-ops.app · Bulk operations for WooCommerce</p>
    </div>
  </div>
</footer>

<a class="to-top" href="#top" aria-label="Back to top" title="Back to top">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <path d="M12 19V5"/><path d="M5 12l7-7 7 7"/>
  </svg>
</a>

<?php wp_footer(); ?>
</body>
</html>
