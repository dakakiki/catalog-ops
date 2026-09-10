<?php
/**
 * The two things on this site that genuinely grow.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the FAQ and compatibility post types.
 *
 * **Why post types rather than repeated fields.** Everything else on the
 * landing page is fixed by its own layout — three pricing columns, three
 * promises, five pipeline stages — and those are groups of fields, because
 * adding a fourth column is a change to the design and ought to feel like one.
 * These two are different: a question gets asked and answered, a WooCommerce
 * version ships, and the list is longer than it was. That is a list of things,
 * and WordPress already has an editor for a list of things — one that sorts by
 * dragging, searches, keeps revisions, and does not put twenty answers into a
 * single database row.
 *
 * It also happens to be what the free ACF can do. The Repeater field is Pro,
 * and reaching for it here would have bought a worse editor for the money.
 *
 * Both are `publicly_queryable => false`: they are read on the landing page and
 * have no page of their own, so a URL for one would be a thin page that
 * competes with the real one in search results.
 */
function catalogops_post_types(): void {
	register_post_type(
		'co_faq',
		array(
			'labels'              => array(
				'name'               => __( 'FAQ', 'catalogops' ),
				'singular_name'      => __( 'Question', 'catalogops' ),
				'add_new_item'       => __( 'Add question', 'catalogops' ),
				'edit_item'          => __( 'Edit question', 'catalogops' ),
				'search_items'       => __( 'Search questions', 'catalogops' ),
				'not_found'          => __( 'No questions yet.', 'catalogops' ),
				'menu_name'          => __( 'FAQ', 'catalogops' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-editor-help',
			'menu_position'       => 21,
			'hierarchical'        => false,
			// The title is the question; the editor is the answer. `page-attributes`
			// is what puts the order column on the list table, so the questions can
			// be dragged into the order they should be read in.
			'supports'            => array( 'title', 'editor', 'page-attributes' ),
			'has_archive'         => false,
			'rewrite'             => false,
		)
	);

	register_post_type(
		'co_compat',
		array(
			'labels'              => array(
				'name'               => __( 'Compatibility', 'catalogops' ),
				'singular_name'      => __( 'Compatibility row', 'catalogops' ),
				'add_new_item'       => __( 'Add row', 'catalogops' ),
				'edit_item'          => __( 'Edit row', 'catalogops' ),
				'not_found'          => __( 'No rows yet.', 'catalogops' ),
				'menu_name'          => __( 'Compatibility', 'catalogops' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'menu_icon'           => 'dashicons-yes-alt',
			'menu_position'       => 22,
			'hierarchical'        => false,
			// Title is the thing (WooCommerce, PHP, Multisite); the editor holds
			// what is said about it, which is a sentence rather than a version
			// number often enough to be worth a real field.
			'supports'            => array( 'title', 'editor', 'page-attributes' ),
			'has_archive'         => false,
			'rewrite'             => false,
		)
	);
}
add_action( 'init', 'catalogops_post_types' );

/**
 * Sort both lists by their manual order in the admin, not by date.
 *
 * A FAQ read newest-first is a FAQ nobody can follow: the questions were
 * written to be read in an order, and the drag handle is how that order is set.
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
