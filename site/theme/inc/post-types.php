<?php
/**
 * The two things on this site that genuinely grow.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * `co_faq` and `co_compat` are registered by ACF, from `acf-json/`.
 * ---------------------------------------------------------------------------
 *
 * There is no `register_post_type()` here any more. The definitions live in
 * `acf-json/post_type_co_faq.json` and `post_type_co_compat.json`, which is the
 * same decision the field groups took and for the same reason: a definition in
 * PHP is in version control but nowhere a person can look at it, while one in
 * `acf-json/` is in version control AND listed under ACF > Post Types, where it
 * can be read, reviewed and changed. Editing one writes the file back.
 *
 * **Why these two are post types at all**, when everything else on the landing
 * page is a group of fields: the rest is fixed by its own layout — three pricing
 * columns, five pipeline stages — so adding to it means drawing something new.
 * These two are different. A question gets asked and answered; a WooCommerce
 * version ships; the list is longer than it was. That is a list of things, and
 * WordPress already has an editor for a list of things — one that sorts by
 * dragging, searches, keeps revisions, and does not put twenty answers into a
 * single database row. It also happens to be what the free ACF can do, since
 * the Repeater field is Pro; reaching for it here would have bought a worse
 * editor for the money.
 *
 * Both are `publicly_queryable => false`: they are read on the landing page and
 * have no page of their own, so a URL for one would be a thin page competing
 * with the real one in search results.
 *
 * What stays here is the behaviour the JSON cannot describe — the admin
 * ordering, and the reader the templates use.
 */

/**
 * Sort both lists by their manual order in the admin, not by date.
 *
 * A FAQ read newest-first is a FAQ nobody can follow: the questions were written
 * to be read in an order, and the drag handle is how that order is set. ACF's
 * post type screen has no setting for this, which is why it is code.
 *
 * @param WP_Query $query The query.
 */
function catalogops_admin_order( WP_Query $query ): void {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( in_array( $query->get( 'post_type' ), array( 'co_faq', 'co_compat' ), true ) ) {
		$query->set( 'orderby', 'menu_order' );
		$query->set( 'order', 'ASC' );
	}
}
add_action( 'pre_get_posts', 'catalogops_admin_order' );

/**
 * The entries of one of those lists, in the order they were arranged.
 *
 * @param string $post_type `co_faq` or `co_compat`.
 * @return list<WP_Post>
 */
function catalogops_entries( string $post_type ): array {
	return get_posts(
		array(
			'post_type'        => $post_type,
			'post_status'      => 'publish',
			'numberposts'      => 100,
			'orderby'          => array( 'menu_order' => 'ASC', 'date' => 'ASC' ),
			'suppress_filters' => false,
		)
	);
}
