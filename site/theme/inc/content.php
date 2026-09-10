<?php
/**
 * Reading editable content, with the agreed copy as the default.
 *
 * @package CatalogOps_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether ACF is available to read fields from.
 *
 * The theme works without it. Every template asks {@see catalogops_text()} for
 * its content and gets the agreed copy back when nothing has been edited, so a
 * fresh install renders the site as designed rather than a page of empty
 * headings — and deactivating ACF later takes the editing away, never the page.
 */
function catalogops_has_acf(): bool {
	return function_exists( 'get_field' );
}

/**
 * One editable string, in the language of the templates: ask for it by name.
 *
 * **The default is not a placeholder, it is the shipped copy.** Every line of
 * this site was written and argued over before it was a theme, and losing it to
 * an empty database would be losing the work. So a field nobody has touched
 * answers with what the prototype said, and editing a field is what replaces it.
 *
 * `$post_id` defaults to the page being rendered, so a template need not know
 * which page it is on.
 *
 * @param string   $name    Field name, e.g. `hero_heading`.
 * @param string   $default The agreed copy for this field.
 * @param int|null $post_id Page to read from; the current one by default.
 */
function catalogops_text( string $name, string $default = '', ?int $post_id = null ): string {
	if ( ! catalogops_has_acf() ) {
		return $default;
	}

	$value = get_field( $name, $post_id ?? get_the_ID() );

	if ( is_string( $value ) && '' !== trim( $value ) ) {
		return $value;
	}

	return $default;
}

/**
 * The same, printed and escaped for text content.
 *
 * @param string   $name    Field name.
 * @param string   $default The agreed copy.
 * @param int|null $post_id Page to read from.
 */
function catalogops_the_text( string $name, string $default = '', ?int $post_id = null ): void {
	echo esc_html( catalogops_text( $name, $default, $post_id ) );
}

/**
 * The same, for a field whose copy carries markup.
 *
 * A few lines on this site are written with emphasis inside them — the problem
 * strip, the FAQ answers — and stripping it would change what the sentence
 * says. `wp_kses_post()` is what an editor may write, and no more: no scripts,
 * no iframes, nothing that could put a third party back on a page whose whole
 * position is that it loads nothing from anybody.
 *
 * @param string   $name    Field name.
 * @param string   $default The agreed copy, with markup.
 * @param int|null $post_id Page to read from.
 */
function catalogops_the_rich( string $name, string $default = '', ?int $post_id = null ): void {
	echo wp_kses_post( catalogops_text( $name, $default, $post_id ) );
}

/**
 * One value out of an ACF Group, with a default.
 *
 * Groups are how this theme says "these five fields belong to that one thing"
 * without the Repeater field, which is Pro-only. The design fixes the counts —
 * three plans, three promises, five stages — so a group per item is not a
 * workaround so much as an honest description: adding a fourth pricing column
 * is a change to the layout, not data entry.
 *
 * @param string   $group   Group field name, e.g. `plan_solo`.
 * @param string   $key     Key inside the group, e.g. `price`.
 * @param string   $default The agreed copy.
 * @param int|null $post_id Page to read from.
 */
function catalogops_group( string $group, string $key, string $default = '', ?int $post_id = null ): string {
	if ( ! catalogops_has_acf() ) {
		return $default;
	}

	$values = get_field( $group, $post_id ?? get_the_ID() );

	if ( is_array( $values ) && isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] ) ) {
		return $values[ $key ];
	}

	return $default;
}

/**
 * A list written one item per line, as a list.
 *
 * The plan features and the seat counts are lists of short phrases, and a
 * textarea with one per line is a better editor for that than five numbered
 * text fields — it reorders by dragging a line, and it does not cap the count.
 *
 * @param string $value Raw textarea value.
 * @return list<string>
 */
function catalogops_lines( string $value ): array {
	$lines = preg_split( '/\R/', $value ) ?: array();
	$lines = array_map( 'trim', $lines );

	return array_values( array_filter( $lines, static fn( string $line ): bool => '' !== $line ) );
}
