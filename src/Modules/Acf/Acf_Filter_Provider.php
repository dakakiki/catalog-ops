<?php
/**
 * ACF fields, offered to the filter.
 *
 * @package CatalogOps\Modules\Acf
 */

namespace CatalogOps\Modules\Acf;

use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Filter_Provider;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use wpdb;

/*
 * Sniff exclusions for this file, with their reasons:
 *
 * - This code reads ACF's `acf-field` posts as DURABLE state rather than through
 *   ACF's own readers, which run filter chains — see the class docblock. `get_posts()`
 *   would go back through the caches and hooks this is deliberately avoiding, and
 *   there is nothing to cache the result in: a clause must be built from whatever the
 *   database holds at the moment it is built, in this request and in the cron tick
 *   that freezes the same filter days later.
 * - Every exception raised here is a refusal shown to a shop manager, assembled from
 *   translated strings and an ACF field key, and escaped where it is rendered.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The first real provider, and the first thing that has ever made the filter's
 * module section appear on screen.
 *
 * Most of ACF already filtered before this existed, through the generic `meta:` key —
 * text, number, true_false, a single select, a date and a repeater's *fixed* row all
 * answer that way. What the generic path cannot do is know what it is looking at: it
 * compares every value as text, so a number sorts as `'9' > '10'`, a multi-select
 * matches `%eco%` and so also matches `ecology`, and a repeater is reachable only at
 * `meta:co_specs_0_value` — row zero, by hand, one row at a time. This module's whole
 * value is that the *definition* decides: NUMERIC_TEXT casts, SERIALIZED_LIST probes
 * the quoted token `%"eco"%`, and a repeater sub-field becomes the whole
 * `co_specs_%_value` family in one clause.
 *
 * Keys are ACF's own field keys — `acf:field_co_cost`, not `acf:co_cost`. The name is
 * the friendlier choice and it is the wrong one: names are not unique (two groups may
 * both hold a `title`), and ACF guarantees the key is unique and immutable, which is
 * exactly what a string persisted in filter_json for the life of every saved filter
 * and schedule has to be.
 */
final class Acf_Filter_Provider implements Filter_Provider {

	/**
	 * The namespace every key this provider owns begins with.
	 */
	public const PREFIX = 'acf:';

	/**
	 * The licensed module these fields belong to.
	 *
	 * See {@see \CatalogOps\Licensing\License::has_module()}: every module rides the
	 * single Studio boundary today, so this string does not yet select a plan. It is
	 * the honest name for the gate the moment one exists, and it is what the UI shows
	 * when a field is listed but locked.
	 */
	private const MODULE = 'acf';

	/**
	 * Operators each control offers, always a subset of what the value kind allows.
	 *
	 * A control that could not produce a condition is worse than an absent one, so
	 * these are chosen by what the user can actually express: a toggle asks equality
	 * and nothing else, a value set asks membership rather than `=`, and a date is
	 * offered the closed bounds a date picker can produce rather than the strict ones
	 * it cannot show the difference for.
	 *
	 * @var array<string, list<Operator>>
	 */
	private const OPERATORS = array(
		'text'      => array( Operator::EQUALS, Operator::NOT_EQUALS, Operator::CONTAINS, Operator::EXISTS, Operator::NOT_EXISTS ),
		'number'    => array(
			Operator::EQUALS,
			Operator::NOT_EQUALS,
			Operator::GREATER_THAN,
			Operator::GREATER_OR_EQUAL,
			Operator::LESS_THAN,
			Operator::LESS_OR_EQUAL,
			Operator::BETWEEN,
			Operator::EXISTS,
			Operator::NOT_EXISTS,
		),
		'toggle'    => array( Operator::EQUALS, Operator::EXISTS, Operator::NOT_EXISTS ),
		'value_set' => array( Operator::IN, Operator::NOT_IN, Operator::EXISTS, Operator::NOT_EXISTS ),
		'date'      => array(
			Operator::EQUALS,
			Operator::GREATER_OR_EQUAL,
			Operator::LESS_OR_EQUAL,
			Operator::BETWEEN,
			Operator::EXISTS,
			Operator::NOT_EXISTS,
		),
	);

	/**
	 * Build the provider.
	 *
	 * @param wpdb       $wpdb   WordPress database handle.
	 * @param Acf_Fields $fields The definition reader.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly Acf_Fields $fields
	) {}

	/**
	 * The licensed module these fields belong to.
	 */
	public function module(): string {
		return self::MODULE;
	}

	/**
	 * The heading these fields sit under.
	 *
	 * "ACF fields" rather than "Advanced Custom Fields": the plugin's own admin menu,
	 * its field-group screens and its documentation all say ACF, so that is the name
	 * the person who built these fields knows them by.
	 */
	public function label(): string {
		return __( 'ACF fields', 'catalogops' );
	}

	/**
	 * Whether this provider owns a key.
	 *
	 * Prefix test only, and that is a requirement rather than laziness: this is asked
	 * once per condition per rendered statement, and one preview renders its filter
	 * 2+N+W times. Whether the field behind the key still exists is
	 * {@see storage_for()}'s question, where a missing field is a refusal rather than
	 * a shrug — a key this provider disowns would fall through to "unknown field",
	 * which says the wrong thing about a field ACF simply deleted.
	 *
	 * @param string $key Filter key as persisted in filter_json.
	 */
	public function handles_filter( string $key ): bool {
		return str_starts_with( $key, self::PREFIX );
	}

	/**
	 * Every ACF field on products or variations that this module can express.
	 *
	 * Unlike {@see storage_for()} this may look things up, and it still does not call
	 * ACF: the same reader serves both, so the list can never offer a field the
	 * clause path would refuse. A field ACF holds only in PHP or in `acf-json` has no
	 * post row and is therefore not listed — see {@see Acf_Fields} for why that is
	 * the right side to be wrong on.
	 *
	 * @return list<Filter_Field>
	 */
	public function filter_fields(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT ID, post_name, post_title, post_excerpt, post_parent, post_content
				 FROM {$this->wpdb->posts}
				 WHERE post_type = %s AND post_status = 'publish'
				 ORDER BY post_parent ASC, menu_order ASC, ID ASC",
				'acf-field'
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			return array();
		}

		// Resolve each field to the group it ultimately hangs from, so the group's
		// location rules can decide the scopes. Sub-fields hang from a field, not
		// from the group, so this walks up.
		$by_id = array();

		foreach ( $rows as $row ) {
			$by_id[ (int) $row['ID'] ] = $row;
		}

		$scopes_by_group = array();
		$fields          = array();

		foreach ( $rows as $row ) {
			$definition = $this->hydrate( $row );

			if ( null === $definition || ! $this->fields->is_expressible( $definition ) ) {
				continue;
			}

			$group_id = $this->group_of( (int) $row['ID'], $by_id );

			if ( 0 === $group_id ) {
				continue;
			}

			if ( ! isset( $scopes_by_group[ $group_id ] ) ) {
				$scopes_by_group[ $group_id ] = $this->fields->scopes_for_group( $group_id );
			}

			$scopes = $scopes_by_group[ $group_id ];

			if ( array() === $scopes ) {
				// The group is not on products at all — an ACF group for pages, or
				// for a post type this plugin never touches.
				continue;
			}

			$field = $this->describe( $definition, $scopes, $by_id );

			if ( null !== $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Where an ACF field's value lives.
	 *
	 * Pure, and reads only durable database state. Nothing here consults ACF, the
	 * clock, the current user or the current language — see {@see Acf_Fields}.
	 *
	 * @param string      $key   A key {@see handles_filter()} claimed.
	 * @param Query_Scope $scope The object type being queried.
	 *
	 * @throws Filter_Field_Unavailable When the field is gone or cannot be expressed.
	 */
	public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
		unset( $scope );

		$field_key  = substr( $key, strlen( self::PREFIX ) );
		$definition = $this->fields->definition( $field_key );

		if ( null === $definition ) {
			// Deleted, trashed, or a group that now lives only in PHP. A refusal, not
			// an empty clause: an empty fragment leaves the filter *wider* than the
			// user wrote, which is the one direction nothing downstream can notice.
			throw new Filter_Field_Unavailable(
				__( 'this ACF field no longer exists — remove the condition and preview again', 'catalogops' ),
				$key
			);
		}

		return $this->fields->storage( $definition );
	}

	/**
	 * Build the descriptor for one definition, or null when it cannot be described.
	 *
	 * @param array<string, mixed>             $definition Hydrated definition.
	 * @param Query_Scope[]                    $scopes     Scopes the owning group covers.
	 * @param array<int, array<string, mixed>> $by_id   Every acf-field row by id.
	 */
	private function describe( array $definition, array $scopes, array $by_id ): ?Filter_Field {
		try {
			list( $value_kind, $control ) = $this->fields->value_kind( $definition );
		} catch ( Filter_Field_Unavailable $e ) {
			unset( $e );

			return null;
		}

		unset( $value_kind );

		$operators = self::OPERATORS[ $control->value ] ?? array();

		if ( array() === $operators ) {
			return null;
		}

		return new Filter_Field(
			self::PREFIX . (string) $definition['key'],
			$this->field_label( $definition, $by_id ),
			$control,
			$operators,
			$scopes,
			Filter_Control::VALUE_SET === $control
				? Acf_Options_Controller::ROUTE . '?field=' . rawurlencode( (string) $definition['key'] )
				: '',
			$this->field_label( $definition, $by_id ),
			false,
			$this->fields->storage_format( $definition )
		);
	}

	/**
	 * A field label a user can tell apart from the other twenty.
	 *
	 * ACF labels are written per group and repeat freely — two groups each with a
	 * "Value", a repeater whose sub-field is "Label". Prefixing the ancestor chain is
	 * what makes the filter's field list readable, and it costs nothing here because
	 * this runs on the UI path.
	 *
	 * @param array<string, mixed>             $definition Hydrated definition.
	 * @param array<int, array<string, mixed>> $by_id      Every acf-field row by id.
	 */
	private function field_label( array $definition, array $by_id ): string {
		$parts  = array();
		$parent = (int) ( $definition['parent'] ?? 0 );

		for ( $depth = 0; $depth < 10 && isset( $by_id[ $parent ] ); $depth++ ) {
			array_unshift( $parts, (string) $by_id[ $parent ]['post_title'] );
			$parent = (int) $by_id[ $parent ]['post_parent'];
		}

		$parts[] = (string) ( $definition['label'] ?? $definition['name'] ?? '' );

		return implode( ' › ', array_filter( $parts ) );
	}

	/**
	 * The `acf-field-group` post a field ultimately belongs to, walking up through
	 * any repeaters. Zero when the chain does not end in a group.
	 *
	 * @param int                              $field_id The acf-field post id.
	 * @param array<int, array<string, mixed>> $by_id    Every acf-field row by id.
	 */
	private function group_of( int $field_id, array $by_id ): int {
		$parent = (int) ( $by_id[ $field_id ]['post_parent'] ?? 0 );

		for ( $depth = 0; $depth < 10 && $parent > 0; $depth++ ) {
			if ( ! isset( $by_id[ $parent ] ) ) {
				// Not another field, so it is the group — or nothing.
				return $parent;
			}

			$parent = (int) $by_id[ $parent ]['post_parent'];
		}

		return 0;
	}

	/**
	 * Turn an `acf-field` row into the settings array the reader works with.
	 *
	 * @param array<string, mixed> $row One row of the acf-field query.
	 * @return array<string, mixed>|null
	 */
	private function hydrate( array $row ): ?array {
		$settings = maybe_unserialize( (string) $row['post_content'] );

		if ( ! is_array( $settings ) ) {
			return null;
		}

		$settings['ID']     = (int) $row['ID'];
		$settings['key']    = (string) $row['post_name'];
		$settings['label']  = (string) $row['post_title'];
		$settings['name']   = (string) $row['post_excerpt'];
		$settings['parent'] = (int) $row['post_parent'];

		return $settings;
	}
}
