<?php
/**
 * Reading ACF's field definitions the way a clause is allowed to read them.
 *
 * @package CatalogOps\Modules\Acf
 */

namespace CatalogOps\Modules\Acf;

use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Object_Anchor;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter_Field_Unavailable;
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
 * Everything that has to know how ACF stores things, in one place.
 *
 * **This class never calls an ACF function.** `Filter_Provider::storage_for()` must
 * be a pure function of its arguments and durable database state, and ACF's readers
 * are neither: `acf_get_field_groups()` runs the `acf/load_field_group` filter chain,
 * `acf_get_field()` runs `acf/load_field`, and both consult a request-scoped store
 * that local JSON sync and `acf_add_local_field_group()` write into. A preview counts
 * in one HTTP request and the queue freezes in another — sometimes days later on a
 * cron tick with nobody present — and both rebuild from the same persisted
 * filter_json. Anything that can answer differently between those two moments is a
 * filter that silently changes what it targets.
 *
 * So this reads the `acf-field` posts directly. That is durable state: ACF's own
 * admin writes one post per field, `post_name` holding the immutable field key,
 * `post_excerpt` the meta key it writes to, and `post_content` the serialised
 * settings.
 *
 * **The cost of that choice, stated plainly:** a field group registered only in PHP
 * (`acf_add_local_field_group()`) or synced from `acf-json` without ever being saved
 * in the admin has no post row, so this module does not offer its fields at all. That
 * is a real gap and it is the right side to err on — offering them would mean reading
 * them through the filter chain, and a field whose storage is decided by a filter is
 * a field whose meaning can change between preview and run.
 */
final class Acf_Fields {

	/**
	 * The post type ACF stores one field definition per row in.
	 */
	private const FIELD_POST_TYPE = 'acf-field';

	/**
	 * The post type ACF stores a field group in.
	 */
	private const GROUP_POST_TYPE = 'acf-field-group';

	/**
	 * Scalar types stored as one ordinary postmeta row, mapped to how the stored
	 * text compares and to the control that collects it.
	 *
	 * `number` and `range` earn their place by being the only fields that can be
	 * asked an ordered question — `>`, `<`, `between` — which is what a real
	 * numeric field on a product is for: lead time in days, warranty months,
	 * pieces per box. `true_false` earns its place by describing a lifecycle a
	 * shop acts on in bulk: "discontinued" is exactly the set you set stock to
	 * zero on. `textarea`, `email` and `url` ride along because they are the same
	 * postmeta string as `text` and cost no code to allow.
	 *
	 * `true_false` is TEXT rather than INTEGER on purpose: ACF writes the strings
	 * `'1'` and `'0'`, and INTEGER would bind `%d`, which is a different comparison
	 * against a text column and defeats the meta_key index for no gain.
	 *
	 * @var array<string, array{0: Value_Kind, 1: Filter_Control}>
	 */
	private const SCALAR_TYPES = array(
		'text'       => array( Value_Kind::TEXT, Filter_Control::TEXT ),
		'textarea'   => array( Value_Kind::TEXT, Filter_Control::TEXT ),
		'email'      => array( Value_Kind::TEXT, Filter_Control::TEXT ),
		'url'        => array( Value_Kind::TEXT, Filter_Control::TEXT ),
		'number'     => array( Value_Kind::NUMERIC_TEXT, Filter_Control::NUMBER ),
		'range'      => array( Value_Kind::NUMERIC_TEXT, Filter_Control::NUMBER ),
		'true_false' => array( Value_Kind::TEXT, Filter_Control::TOGGLE ),
	);

	/**
	 * Types whose value is one of a fixed set of choices, and whose storage depends
	 * on the `multiple` setting rather than on the type alone.
	 *
	 * @var array<string, bool> type => whether it is always multi-valued.
	 */
	private const CHOICE_TYPES = array(
		'select'       => false,
		'radio'        => false,
		'button_group' => false,
		'checkbox'     => true,
	);

	/**
	 * Date types and the format ACF stores them in, verified against ACF Pro 6.8.9.
	 *
	 * Both are zero-padded and big-endian, so a lexical BETWEEN orders them
	 * correctly and {@see Value_Kind::DATE_TEXT} is honest. `time_picker` is
	 * deliberately absent — `H:i:s` also sorts correctly, but a date control cannot
	 * collect it and offering a text box for a time is worse than not offering it.
	 *
	 * @var array<string, string>
	 */
	private const DATE_TYPES = array(
		'date_picker'      => 'Ymd',
		'date_time_picker' => 'Y-m-d H:i:s',
	);

	/**
	 * Types this module refuses, each with the reason a user sees.
	 *
	 * Every one of these is refused because its **storage cannot be decided from
	 * the field definition**, which is the one thing `storage_for()` is allowed to
	 * read. A clause built on a guess here does not fail — it returns a plausible,
	 * non-empty, wrong set, and preview and run agree on it perfectly.
	 *
	 * @var array<string, string>
	 */
	private const REFUSED_TYPES = array(
		// `load_terms` and `save_terms` invert the source of truth independently:
		// with save_terms on, the real answer is in term_relationships; with it off,
		// it is a postmeta row holding term ids; and either flag can be switched
		// after values have been written, leaving one catalogue storing both ways.
		'taxonomy'         => 'an ACF taxonomy field can store its value as terms or as meta, and the setting can change after products are saved',

		// ACF writes a bare id when one thing is selected and a serialised array
		// when several are, choosing per value at save time. The definition does not
		// say which a given product holds, so no single clause is right for all of
		// them.
		'post_object'      => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'relationship'     => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'user'             => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'file'             => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'image'            => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'gallery'          => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'page_link'        => 'ACF stores one selection as a plain id and several as a serialised list, decided per product when it was saved',
		'link'             => 'an ACF link field stores a serialised array of parts, with no single value to compare',

		// The layout name lives positionally in a serialised list on the parent key
		// while the row's values live under indexed keys that carry no layout name.
		// Correlating them needs PHP, and an approximation that ignores the layout
		// matches the wrong rows.
		'flexible_content' => 'a flexible content field keeps its layout names apart from its values, so a row cannot be matched to its layout in SQL',

		// A container's own key holds a row count or a layout list, never a value.
		// Its sub-fields are offered separately.
		'repeater'         => 'a repeater holds no value of its own — filter on one of its sub-fields instead',
		'group'            => 'a group holds no value of its own — filter on one of its sub-fields instead',
		'clone'            => 'a clone field has no storage of its own',

		// The column holds HTML, not prose. `contains "price"` would match the
		// word inside an attribute or an entity as readily as inside a sentence,
		// and a shop owner reading the filter has no way to know which they got.
		'wysiwyg'          => 'a rich text field stores HTML, so searching it matches the markup as well as the words',

		'google_map'       => 'a map field stores a serialised address, with no single value to compare',
		'color_picker'     => 'a colour is stored as free text and comparing it is rarely what anyone means',
		'oembed'           => 'an oEmbed field stores a URL whose meaning is the embed, not the text',
		'password'         => 'a password field is not something to filter on',
		'message'          => 'a message field holds no value',
		'tab'              => 'a tab holds no value',
		'accordion'        => 'an accordion holds no value',
	);

	/**
	 * Build the reader.
	 *
	 * @param wpdb $wpdb WordPress database handle.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * One field definition, read from its `acf-field` post.
	 *
	 * Returns the settings array with the four columns ACF keeps outside
	 * `post_content` merged back in, matching what `acf_get_field()` would return
	 * minus the filter chain.
	 *
	 * @param string $field_key ACF's immutable field key, e.g. `field_co_cost`.
	 * @return array<string, mixed>|null The definition, or null when there is no
	 *                                   published `acf-field` post with that key.
	 */
	public function definition( string $field_key ): ?array {
		$post = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT ID, post_content, post_title, post_excerpt, post_parent
				 FROM {$this->wpdb->posts}
				 WHERE post_type = %s AND post_name = %s AND post_status = 'publish'
				 LIMIT 1",
				self::FIELD_POST_TYPE,
				$field_key
			),
			ARRAY_A
		);

		if ( null === $post ) {
			return null;
		}

		$settings = maybe_unserialize( $post['post_content'] );

		if ( ! is_array( $settings ) ) {
			return null;
		}

		$settings['ID']     = (int) $post['ID'];
		$settings['key']    = $field_key;
		$settings['label']  = (string) $post['post_title'];
		$settings['name']   = (string) $post['post_excerpt'];
		$settings['parent'] = (int) $post['post_parent'];

		return $settings;
	}

	/**
	 * The chain of ancestor fields between a definition and its field group,
	 * outermost first. Empty for a top-level field.
	 *
	 * A sub-field's meta key is assembled from its ancestors' names, so this is what
	 * turns `field_co_specs_value` into the `co_specs_%_value` key family.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 * @return list<array<string, mixed>>
	 */
	public function ancestors( array $definition ): array {
		$chain  = array();
		$parent = (int) ( $definition['parent'] ?? 0 );

		// Bounded: ACF nests repeaters, and a cycle in post_parent would otherwise
		// spin forever on a corrupted install.
		for ( $depth = 0; $depth < 10 && $parent > 0; $depth++ ) {
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					"SELECT post_type, post_name, post_content, post_title, post_excerpt, post_parent
					 FROM {$this->wpdb->posts} WHERE ID = %d LIMIT 1",
					$parent
				),
				ARRAY_A
			);

			if ( null === $row || self::FIELD_POST_TYPE !== $row['post_type'] ) {
				break;
			}

			$settings = maybe_unserialize( $row['post_content'] );

			if ( ! is_array( $settings ) ) {
				break;
			}

			$settings['key']    = (string) $row['post_name'];
			$settings['label']  = (string) $row['post_title'];
			$settings['name']   = (string) $row['post_excerpt'];
			$settings['parent'] = (int) $row['post_parent'];

			array_unshift( $chain, $settings );
			$parent = (int) $row['post_parent'];
		}

		return $chain;
	}

	/**
	 * The scopes a field group's location rules put it on.
	 *
	 * ACF location rules are a disjunction of conjunctions. Only `post_type` rules
	 * with `==` are read: every other rule narrows *which* products show the field
	 * in the editor, which is a question about the admin screen rather than about
	 * where the value is stored, and a filter that answers "this product has no such
	 * meta row" is already correct for a product the group does not apply to.
	 *
	 * @param int $group_id The `acf-field-group` post id.
	 * @return list<Query_Scope>
	 */
	public function scopes_for_group( int $group_id ): array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT post_content FROM {$this->wpdb->posts}
				 WHERE ID = %d AND post_type = %s AND post_status = 'publish' LIMIT 1",
				$group_id,
				self::GROUP_POST_TYPE
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return array();
		}

		$settings = maybe_unserialize( $row['post_content'] );
		$scopes   = array();

		foreach ( (array) ( $settings['location'] ?? array() ) as $group ) {
			foreach ( (array) $group as $rule ) {
				if ( ! is_array( $rule ) || 'post_type' !== ( $rule['param'] ?? '' ) || '==' !== ( $rule['operator'] ?? '' ) ) {
					continue;
				}

				if ( 'product' === ( $rule['value'] ?? '' ) ) {
					$scopes[] = Query_Scope::PRODUCT;
				} elseif ( 'product_variation' === ( $rule['value'] ?? '' ) ) {
					$scopes[] = Query_Scope::VARIATION;
				}
			}
		}

		return array_values( array_unique( $scopes, SORT_REGULAR ) );
	}

	/**
	 * How a definition's value compares, and which control collects it.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 * @return array{0: Value_Kind, 1: Filter_Control}
	 *
	 * @throws Filter_Field_Unavailable When the type's storage cannot be decided.
	 */
	public function value_kind( array $definition ): array {
		$type = (string) ( $definition['type'] ?? '' );
		$key  = (string) ( $definition['key'] ?? '' );

		if ( isset( self::REFUSED_TYPES[ $type ] ) ) {
			throw new Filter_Field_Unavailable( self::REFUSED_TYPES[ $type ], $key );
		}

		if ( isset( self::SCALAR_TYPES[ $type ] ) ) {
			return self::SCALAR_TYPES[ $type ];
		}

		if ( isset( self::CHOICE_TYPES[ $type ] ) ) {
			$multiple = self::CHOICE_TYPES[ $type ] || ! empty( $definition['multiple'] );

			return array(
				$multiple ? Value_Kind::SERIALIZED_LIST : Value_Kind::TEXT,
				Filter_Control::VALUE_SET,
			);
		}

		if ( isset( self::DATE_TYPES[ $type ] ) ) {
			// ACF 4's `save_format` survives on fields migrated from it, and it
			// offers `d/m/Y` — which a lexical range answers plausibly and wrongly,
			// with no error and no empty result. Refuse rather than declare
			// DATE_TEXT over a format we cannot prove is big-endian.
			$legacy = (string) ( $definition['save_format'] ?? '' );

			if ( '' !== $legacy && ! str_starts_with( $legacy, 'Y' ) ) {
				throw new Filter_Field_Unavailable(
					sprintf(
						/* translators: %s: the date format ACF stores this field in. */
						__( 'this date field is stored as "%s", which cannot be put in order without reading every product', 'catalogops' ),
						$legacy
					),
					$key
				);
			}

			return array( Value_Kind::DATE_TEXT, Filter_Control::DATE );
		}

		throw new Filter_Field_Unavailable(
			sprintf(
				/* translators: %s: the ACF field type, e.g. "google_map". */
				__( 'CatalogOps does not know how ACF stores a "%s" field', 'catalogops' ),
				$type
			),
			$key
		);
	}

	/**
	 * Where a definition's value lives.
	 *
	 * A top-level field is one postmeta row under its own name. A field inside a
	 * repeater is a family of rows whose keys carry the row index in the middle —
	 * `co_specs_0_value`, `co_specs_1_value` — which is exactly the shape
	 * {@see Field_Storage::post_meta_rows()} exists for. Membership there means
	 * "some row matches", and that is all it can mean: the row index lives in the
	 * key string rather than in a column, so two sub-fields cannot be required to
	 * match in the *same* row.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 * @return Field_Storage
	 *
	 * @throws Filter_Field_Unavailable When the field has no usable storage.
	 */
	public function storage( array $definition ): Field_Storage {
		list( $value_kind ) = $this->value_kind( $definition );

		$name = (string) ( $definition['name'] ?? '' );
		$key  = (string) ( $definition['key'] ?? '' );

		if ( '' === $name ) {
			throw new Filter_Field_Unavailable(
				__( 'this ACF field has no name, so it writes nothing to find', 'catalogops' ),
				$key
			);
		}

		$ancestors = $this->ancestors( $definition );

		if ( array() === $ancestors ) {
			return Field_Storage::post_meta( $name, $value_kind, Object_Anchor::SELF );
		}

		// Only a single repeater level is expressible. Deeper nesting puts a second
		// index in the middle of the key, and a LIKE pattern with a wildcard on both
		// sides of a literal is still one pattern — but the prefix would then end at
		// the outer repeater's name and the suffix start at the inner field's, so a
		// row of a *different* inner repeater with the same sub-name would match too.
		if ( count( $ancestors ) > 1 ) {
			throw new Filter_Field_Unavailable(
				__( 'this field is nested more than one repeater deep, and its rows cannot be told apart in SQL', 'catalogops' ),
				$key
			);
		}

		$parent      = $ancestors[0];
		$parent_type = (string) ( $parent['type'] ?? '' );
		$parent_name = (string) ( $parent['name'] ?? '' );

		if ( 'repeater' !== $parent_type || '' === $parent_name ) {
			throw new Filter_Field_Unavailable(
				sprintf(
					/* translators: %s: the ACF field type holding this one. */
					__( 'CatalogOps cannot read a field held inside a "%s"', 'catalogops' ),
					$parent_type
				),
				$key
			);
		}

		return Field_Storage::post_meta_rows(
			$parent_name . '_',
			'_' . $name,
			$value_kind,
			Object_Anchor::SELF
		);
	}

	/**
	 * The PHP date format a definition's value is stored in, or '' when the field
	 * is not a date.
	 *
	 * Verified against ACF Pro 6.8.9: `date_picker` writes `Ymd` and
	 * `date_time_picker` writes `Y-m-d H:i:s`, whatever `return_format` and
	 * `display_format` say — those two govern what ACF hands to a template and what
	 * the editor shows, never what lands in the column. A filter that trusted
	 * `display_format` would build `08.07.2024` and match nothing.
	 *
	 * A legacy `save_format` overrides both, and {@see value_kind()} has already
	 * refused the field by then unless that format is big-endian — so anything
	 * reaching here can be ordered and compared as text.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 */
	public function storage_format( array $definition ): string {
		$type = (string) ( $definition['type'] ?? '' );

		if ( ! isset( self::DATE_TYPES[ $type ] ) ) {
			return '';
		}

		$legacy = (string) ( $definition['save_format'] ?? '' );

		return '' !== $legacy ? $legacy : self::DATE_TYPES[ $type ];
	}

	/**
	 * The choices a value-set control offers, as value => label.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 * @return array<string, string>
	 */
	public function choices( array $definition ): array {
		$choices = $definition['choices'] ?? array();

		if ( ! is_array( $choices ) ) {
			return array();
		}

		$out = array();

		foreach ( $choices as $value => $label ) {
			$out[ (string) $value ] = (string) $label;
		}

		return $out;
	}

	/**
	 * Whether this module can express a definition at all — used by the field list,
	 * which must not throw for one unusable field among twenty usable ones.
	 *
	 * @param array<string, mixed> $definition A definition from {@see definition()}.
	 */
	public function is_expressible( array $definition ): bool {
		try {
			$this->storage( $definition );

			return true;
		} catch ( Filter_Field_Unavailable $e ) {
			unset( $e );

			return false;
		}
	}
}
