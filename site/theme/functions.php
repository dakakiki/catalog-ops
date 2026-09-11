<?php
/**
 * Theme setup.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

define( 'CATALOGOPS_THEME_VERSION', '0.1.0' );

require_once get_template_directory() . '/inc/content.php';
require_once get_template_directory() . '/inc/fields.php';
require_once get_template_directory() . '/inc/post-types.php';
require_once get_template_directory() . '/inc/contact-form.php';
require_once get_template_directory() . '/inc/seed.php';

/**
 * Theme supports.
 */
function catalogops_setup(): void {
	load_theme_textdomain( 'catalogops', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );

	// The masthead and footer menus are written in the templates rather than
	// registered as nav menus, and deliberately: they point at sections of one
	// page, and a menu editor that lets somebody delete "Pricing" from the
	// masthead while the section stays on the page is an editor offering a way
	// to break the site with no way to see that it is broken.
	register_nav_menus( array() );
}
add_action( 'after_setup_theme', 'catalogops_setup' );

/**
 * Load the stylesheet and the little script there is.
 *
 * The stylesheet is `style.css` in the theme root, which webpack builds from
 * `assets/src/scss/main.scss` — see webpack.config.js for why it lands there
 * rather than in assets/dist.
 */
function catalogops_assets(): void {
	// Versioned by the file's own modification time, not by the theme version.
	// The theme version changes when somebody remembers to change it; the
	// stylesheet changes on every build, and a browser holding yesterday's copy
	// of a file whose URL never moved is a design that looks broken for reasons
	// no amount of staring at the source will explain.
	$stylesheet = get_template_directory() . '/style.css';

	wp_enqueue_style(
		'catalogops',
		get_stylesheet_uri(),
		array(),
		file_exists( $stylesheet ) ? (string) filemtime( $stylesheet ) : CATALOGOPS_THEME_VERSION
	);

	$script = get_template_directory() . '/assets/dist/main.js';

	if ( file_exists( $script ) ) {
		wp_enqueue_script(
			'catalogops',
			get_template_directory_uri() . '/assets/dist/main.js',
			array(),
			(string) filemtime( $script ),
			true
		);
	}
}
add_action( 'wp_enqueue_scripts', 'catalogops_assets' );

/**
 * Take off everything WordPress adds that this site does not use.
 *
 * **This is not tidiness, it is the site's privacy position.** The promise made
 * on the cookie page is that the site sets no cookies and loads nothing from
 * anybody — that is what removes the consent banner, and it only stays true if
 * nothing quietly adds a request back. The emoji script fetches from
 * s.w.org; oEmbed discovery invites a third party to be embedded later; the
 * block library's stylesheet is 60 kB this theme uses none of.
 */
function catalogops_trim_head(): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );

	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );

	// Feeds for a three-page marketing site with comments off.
	remove_action( 'wp_head', 'feed_links', 2 );
	remove_action( 'wp_head', 'feed_links_extra', 3 );
}
add_action( 'init', 'catalogops_trim_head' );

/**
 * Drop the block library's front-end CSS.
 *
 * Kept out of {@see catalogops_trim_head()} because it has to run late — the
 * block library enqueues itself on `wp_enqueue_scripts`, after `init`.
 */
function catalogops_drop_block_css(): void {
	wp_dequeue_style( 'wp-block-library' );
	wp_dequeue_style( 'wp-block-library-theme' );
	wp_dequeue_style( 'wc-blocks-style' );
	wp_dequeue_style( 'global-styles' );
	wp_dequeue_style( 'classic-theme-styles' );
}
add_action( 'wp_enqueue_scripts', 'catalogops_drop_block_css', 100 );

/**
 * Comments are off, everywhere, permanently.
 *
 * A marketing site with a comment form is a site with a spam problem and a
 * cookie WordPress sets on anyone who comments — the second of which would
 * quietly break the promise the cookie page makes.
 */
function catalogops_no_comments( bool $open, int $post_id ): bool {
	unset( $open, $post_id );

	return false;
}
add_filter( 'comments_open', 'catalogops_no_comments', 10, 2 );
add_filter( 'pings_open', 'catalogops_no_comments', 10, 2 );

/**
 * The URL of one of the site's own pages, by slug.
 *
 * Resolved by slug rather than stored as an id, so the theme works on a fresh
 * install the moment the pages exist and does not need a settings screen to be
 * told where its own legal page is. An absent page falls back to a path, which
 * 404s honestly rather than linking to the home page and pretending.
 *
 * @param string $slug   Page slug — `legal` or `contact`.
 * @param string $anchor Optional fragment, without the hash.
 */
function catalogops_page_url( string $slug, string $anchor = '' ): string {
	$page = get_page_by_path( $slug );
	$url  = $page instanceof WP_Post ? get_permalink( $page ) : home_url( '/' . $slug . '/' );

	return '' === $anchor ? (string) $url : $url . '#' . $anchor;
}

/**
 * The id of one of the site's own pages, by slug.
 *
 * The sibling of {@see catalogops_page_url()}, for the places that need the id
 * rather than the address. Zero when the page does not exist.
 *
 * @param string $slug Page slug.
 */
function catalogops_page_id( string $slug ): int {
	$page = get_page_by_path( $slug );

	return $page instanceof WP_Post ? (int) $page->ID : 0;
}

/**
 * A link to a section of the landing page, from any page.
 *
 * On the landing page itself these stay bare fragments, so the browser scrolls
 * rather than navigates. Anywhere else they have to carry the home URL, or
 * "Pricing" in the masthead is a dead link on two pages out of three.
 *
 * @param string $anchor Section id, without the hash.
 */
function catalogops_home_anchor( string $anchor ): string {
	return is_front_page() ? '#' . $anchor : home_url( '/#' . $anchor );
}

/**
 * Where the free download lives.
 *
 * A filter rather than a constant because this is one of the values that is not
 * settled yet: until wp.org approves the listing it is a direct zip from this
 * site, and afterwards it is a wp.org URL.
 */
function catalogops_download_url(): string {
	/**
	 * Filters the download URL used across the site.
	 *
	 * @param string $url Absolute URL to the free download.
	 */
	return (string) apply_filters( 'catalogops_download_url', home_url( '/download/' ) );
}

/**
 * Print the Plausible snippet, when a domain has been set.
 *
 * Plausible rather than Google Analytics, and the reason is written down: GA
 * would force a consent banner, consent logging and a US-transfer disclosure —
 * more work than the analytics are worth. Plausible sets no cookies, which is
 * what keeps the promise on the cookie page true.
 *
 * Nothing is printed until somebody sets the domain, so a development copy of
 * this site never reports anything.
 */
function catalogops_analytics(): void {
	/**
	 * Filters the domain reported to Plausible. Empty prints nothing at all.
	 *
	 * @param string $domain The site's domain as configured in Plausible.
	 */
	$domain = (string) apply_filters( 'catalogops_plausible_domain', (string) get_option( 'catalogops_plausible_domain', '' ) );

	if ( '' === $domain ) {
		return;
	}

	printf(
		'<script defer data-domain="%1$s" src="%2$s"></script>' . "\n",
		esc_attr( $domain ),
		esc_url( 'https://plausible.io/js/script.js' )
	);
}
add_action( 'wp_head', 'catalogops_analytics' );
