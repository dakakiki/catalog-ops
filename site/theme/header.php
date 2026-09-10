<?php
/**
 * The frame every page opens with.
 *
 * The inline SVG below is the brand mark, defined once and referenced with
 * <use> wherever it appears. It is inline rather than a file for the same
 * reason the fonts are self-hosted: this site loads nothing from anybody, and a
 * second request is a second thing that can be logged somewhere else.
 *
 * @package CatalogOps_Theme
 */

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="co-grad" x1="0" y1="0" x2="40" y2="40" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#5b57ee"/>
      <stop offset="1" stop-color="#3f2fc4"/>
    </linearGradient>
    <symbol id="co-mark" viewBox="0 0 40 40">
      <rect width="40" height="40" rx="9" fill="url(#co-grad)"/>
      <path d="M20 9 31 15 20 21 9 15Z" fill="#ffffff"/>
      <path d="M9 20 20 26 31 20" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="0.72"/>
      <path d="M9 25 20 31 31 25" fill="none" stroke="#ffffff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="0.45"/>
    </symbol>
  </defs>
</svg>

<?php
/*
 * The anchor for "back to top" sits HERE, not on the masthead. The masthead is
 * position: sticky and is therefore already pinned to the top of the viewport,
 * so scrolling to it is a no-op and the link did nothing.
 */
?>
<span id="top" aria-hidden="true"></span>

<header class="masthead">
  <div class="wrap">
    <a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
      <svg class="brand-mark" role="img" aria-label="CatalogOps"><use href="#co-mark"/></svg>
      <span class="brand-text">
        <span class="brand-name">Catalog<em>Ops</em></span>
        <span class="brand-tag">Bulk catalog operations</span>
      </span>
    </a>
    <nav>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'how' ) ); ?>" class="hide-sm">How it works</a>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'formulas' ) ); ?>" class="hide-sm">Formulas</a>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'scheduling' ) ); ?>" class="hide-sm">Scheduling</a>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'pricing' ) ); ?>">Pricing</a>
      <a href="<?php echo esc_url( catalogops_page_url( 'contact' ) ); ?>" class="hide-sm">Contact</a>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'faq' ) ); ?>" class="hide-sm">FAQ</a>
      <a href="<?php echo esc_url( catalogops_home_anchor( 'compatibility' ) ); ?>" class="hide-sm">Compatibility</a>
    </nav>
  </div>
</header>
