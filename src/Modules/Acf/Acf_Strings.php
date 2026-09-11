<?php
/**
 * The ACF strings WPML holds, asked for one named language.
 *
 * @package CatalogOps\Modules\Acf
 */

namespace CatalogOps\Modules\Acf;

use wpdb;

/**
 * ACFML registers an ACF field group's human text with WPML String Translation —
 * every field's label, its instructions, and the label of every choice a select,
 * radio or checkbox offers. A shop that has translated those in WPML has the
 * answers sitting there; this only asks for them.
 *
 * **One class because two places need it.** The filter's field list and the value
 * picker behind it both show text a shop may have translated, and the naming
 * convention below is the kind of rule that goes wrong quietly when it is written
 * out twice. It has already cost this codebase once: three clause builders each
 * keeping their own list of negative operators, two of which had drifted.
 *
 * **It asks for a LANGUAGE, never for "the current one", and that is the whole
 * design.** ACF's own translation resolves against whatever language the request
 * is in, and a REST call from wp-admin resolves as the site's default — so asking
 * ACF here would hand English back to a user working in Serbian and look exactly
 * as though nothing had been translated. The same trap that returned English term
 * ids from `get_term()`.
 *
 * Nothing here is on the SQL path. Labels and choice names are shown to a person;
 * the value a condition carries is ACF's stored key and never passes through this
 * class — `co_origin` holds `rs`, and `rs` is what filter_json must keep, whatever
 * language the shop reads it in.
 */
final class Acf_Strings {

	/**
	 * WordPress database handle.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * WPML context by ACF field key, once worked out.
	 *
	 * @var array<string, string>
	 */
	private array $contexts = array();

	/**
	 * Every `acf-field` row by id, loaded on first use.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $fields = null;

	/**
	 * Every `acf-field-group` key by id, loaded on first use.
	 *
	 * @var array<int, string>|null
	 */
	private array|null $groups = null;

	/**
	 * Build the reader over a database handle.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * One ACF string, in the language asked for.
	 *
	 * The string's name is ACFML's own convention, `field-{key}-{kind}-{md5}`, the
	 * md5 being of the ORIGINAL text — which is why it is computed from the text
	 * rather than stored anywhere. Verified against a live site: md5( 'Season' ) is
	 * `6c7a445aa85245a31c24d3e8ee5408b9` and md5( 'On sale' ) is
	 * `588907ab2d492aca0b07b5bf9c931eea`, the names WPML holds.
	 *
	 * If ACFML ever changes the convention the lookup simply misses and the
	 * original text comes back: the list stays correct and merely stops being
	 * translated, which is the right way for this to fail.
	 *
	 * @param string      $field_key The ACF field key (`field_cops_season`).
	 * @param string      $kind      ACFML's slot — `label`, `choices`, `instructions`.
	 * @param string      $text      The text as ACF stores it.
	 * @param string|null $language  Language to translate into, or null for none.
	 */
	public function translate( string $field_key, string $kind, string $text, ?string $language ): string {
		if ( null === $language || '' === $text || '' === $field_key ) {
			return $text;
		}

		$context = $this->context_for( $field_key );

		if ( '' === $context ) {
			return $text;
		}

		$translated = apply_filters(
			'wpml_translate_single_string',
			$text,
			$context,
			'field-' . $field_key . '-' . $kind . '-' . md5( $text ),
			$language
		);

		return is_string( $translated ) && '' !== $translated ? $translated : $text;
	}

	/**
	 * The WPML context a field's strings are filed under, or '' when there is none.
	 *
	 * ACFML files them under the field GROUP, so a sub-field of a repeater is
	 * walked up to the group it ultimately hangs from rather than to its parent
	 * field.
	 *
	 * @param string $field_key The ACF field key.
	 */
	public function context_for( string $field_key ): string {
		if ( isset( $this->contexts[ $field_key ] ) ) {
			return $this->contexts[ $field_key ];
		}

		$this->contexts[ $field_key ] = '';

		$fields = $this->fields();
		$id     = 0;

		foreach ( $fields as $field_id => $row ) {
			if ( $field_key === (string) $row['post_name'] ) {
				$id = (int) $field_id;
				break;
			}
		}

		if ( 0 === $id ) {
			return '';
		}

		$parent = (int) $fields[ $id ]['post_parent'];

		for ( $depth = 0; $depth < 10 && $parent > 0; $depth++ ) {
			if ( ! isset( $fields[ $parent ] ) ) {
				// Not another field, so it is the group — or nothing.
				break;
			}

			$parent = (int) $fields[ $parent ]['post_parent'];
		}

		$group_key = $this->groups()[ $parent ] ?? '';

		if ( '' !== $group_key ) {
			$this->contexts[ $field_key ] = 'acf-field-group-' . $group_key;
		}

		return $this->contexts[ $field_key ];
	}

	/**
	 * Every `acf-field` row by id.
	 *
	 * Read from the posts table for the same reason every other read in this module
	 * is: `acf_get_field_groups()` runs filter chains and consults a request-scoped
	 * store, so it can answer differently in two requests that should agree.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fields(): array {
		if ( null !== $this->fields ) {
			return $this->fields;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_name, post_parent FROM {$this->wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				'acf-field'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->fields = array();

		foreach ( (array) $rows as $row ) {
			$this->fields[ (int) $row['ID'] ] = $row;
		}

		return $this->fields;
	}

	/**
	 * Every `acf-field-group` key by id.
	 *
	 * @return array<int, string>
	 */
	private function groups(): array {
		if ( null !== $this->groups ) {
			return $this->groups;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_name FROM {$this->wpdb->posts} WHERE post_type = %s",
				'acf-field-group'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->groups = array();

		foreach ( (array) $rows as $row ) {
			$this->groups[ (int) $row['ID'] ] = (string) $row['post_name'];
		}

		return $this->groups;
	}
}
