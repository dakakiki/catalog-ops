<?php
/**
 * Where a filterable field's value lives — described, never queried.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use InvalidArgumentException;

/**
 * A provider builds one of these and hands it back. {@see Storage_Compiler} decides
 * everything else: whether the clause becomes an INNER JOIN over a DISTINCT derived
 * table, an uncorrelated `IN (SELECT …)`, a `NOT EXISTS` anti-join or a plain column
 * predicate; which alias it gets; which object column it matches on; where the
 * negation sits; which placeholder each value binds to; and which argument bucket
 * that placeholder's value goes in.
 *
 * None of those decisions is expressible here, and that is the whole design. Each of
 * them is a way to be silently wrong that cost measured hours to find, and the wrong
 * form returns the *correct answer* on any catalogue a provider author can build —
 * the largest fixture in this repository is 201 products.
 *
 * Every identifier accepted here is validated at construction against a bare-name
 * pattern with **no dot**, and emitted backtick-quoted. The missing dot is
 * load-bearing: it is what makes a second table unnameable inside a membership
 * subquery, so the four-minute plan is unreachable rather than merely documented. It
 * also forbids a quote, a percent sign, a comment marker, a semicolon and a space.
 * Every *value* is bound. That leaves no path by which a provider can reach the SQL
 * text even deliberately — which matters, because condition values and field-key
 * suffixes arrive verbatim from the REST body under `manage_woocommerce` (a shop
 * manager, not an administrator) with no schema, and replay later from persisted
 * filter_json on an unattended schedule tick.
 */
final class Field_Storage {

	/**
	 * The most operands one condition may carry.
	 *
	 * The filter body has no REST schema, so this list arrives at whatever length
	 * the caller sends. Ten thousand placeholders is a statement measured in
	 * megabytes, a max_allowed_packet failure, and a plan the optimiser has never
	 * seen — and it is re-rendered 2+N+W times per preview.
	 */
	public const MAX_OPERANDS = 1000;

	/**
	 * Constant column tests ANDed into the row predicate.
	 *
	 * @var list<array{column: string, value: int|float|string, kind: Value_Kind}>
	 */
	public array $constants = array();

	/**
	 * Build a storage. Private: a storage exists only through a named constructor,
	 * so an incoherent combination — a taxonomy anchored to SELF, a key pattern with
	 * no literal prefix — is not expressible.
	 *
	 * @param Storage_Kind       $kind          Which shape this is.
	 * @param Value_Kind         $value_kind    How the stored value is compared.
	 * @param Object_Anchor      $anchor        Whose row carries it.
	 * @param string             $table         Table name, or '' for a lookup column.
	 * @param string             $object_column Column holding the object id.
	 * @param string             $value_column  Column the condition tests.
	 * @param Lookup_Column|null $column        The lookup column, for that kind only.
	 * @param string             $key_prefix    Literal meta-key prefix, or ''.
	 * @param string             $key_suffix    Literal meta-key suffix, or ''.
	 * @param Value_Map|null     $value_map     Operand pre-resolution, if any.
	 */
	private function __construct(
		public readonly Storage_Kind $kind,
		public readonly Value_Kind $value_kind,
		public readonly Object_Anchor $anchor,
		public readonly string $table = '',
		public readonly string $object_column = '',
		public readonly string $value_column = '',
		public readonly ?Lookup_Column $column = null,
		public readonly string $key_prefix = '',
		public readonly string $key_suffix = '',
		public readonly ?Value_Map $value_map = null,
	) {}

	/**
	 * A column of wc_product_meta_lookup, already in the statement.
	 *
	 * No membership, no join, no subquery — and the only kind where a negative
	 * operator is a plain comparison rather than an anti-join, because a column has
	 * exactly one value per row.
	 *
	 * @param Lookup_Column $column The indexed column.
	 */
	public static function lookup_column( Lookup_Column $column ): self {
		return new self(
			Storage_Kind::LOOKUP_COLUMN,
			$column->value_kind(),
			Object_Anchor::SELF,
			column: $column
		);
	}

	/**
	 * A post-meta key on the object itself, or on its parent.
	 *
	 * The commonest shape by a distance. ACF stores every scalar product field as an
	 * ordinary postmeta row — `acf_update_value()` calls `update_metadata()` with the
	 * field's name — so roughly two thirds of ACF needs nothing but this.
	 *
	 * The key is bound as `%s`, never interpolated, even though it is the provider's
	 * own: keys reach this method from saved filters and REST bodies as readily as
	 * values do.
	 *
	 * @param string        $meta_key   The exact meta key.
	 * @param Value_Kind    $value_kind How the stored text is compared.
	 * @param Object_Anchor $anchor     Whose row carries it.
	 *
	 * @throws InvalidArgumentException When the meta key is empty.
	 */
	public static function post_meta(
		string $meta_key,
		Value_Kind $value_kind,
		Object_Anchor $anchor = Object_Anchor::SELF
	): self {
		if ( '' === $meta_key ) {
			throw new InvalidArgumentException( 'A meta key must not be empty.' );
		}

		return new self(
			Storage_Kind::POST_META,
			$value_kind,
			$anchor,
			key_prefix: $meta_key
		);
	}

	/**
	 * A family of post-meta rows whose keys share a literal prefix and suffix — an
	 * ACF repeater's sub-field, stored as `items_0_sku`, `items_1_sku`, …
	 *
	 * Compiled to `meta_key LIKE %s` with the pattern assembled by the engine from
	 * two `esc_like()`d literals and bound as an argument. The prefix must be
	 * non-empty: postmeta carries a `meta_key(191)` index, and a leading wildcard
	 * turns a range scan into a full scan of a table holding 20-30 rows per product.
	 *
	 * Membership semantics: the object matches when **some** row in the family
	 * satisfies the test. "Some row has sku = X" is expressible; "some row has
	 * sku = X *and* qty > 3 in that same row" is not, because the row index lives
	 * inside the key string rather than in a column.
	 *
	 * @param string        $key_prefix Literal prefix, e.g. `items_`. Required.
	 * @param string        $key_suffix Literal suffix, e.g. `_sku`. Required.
	 * @param Value_Kind    $value_kind How the stored text is compared.
	 * @param Object_Anchor $anchor     Whose rows carry it.
	 *
	 * @throws InvalidArgumentException When either half is empty.
	 */
	public static function post_meta_rows(
		string $key_prefix,
		string $key_suffix,
		Value_Kind $value_kind,
		Object_Anchor $anchor = Object_Anchor::SELF
	): self {
		if ( '' === $key_prefix || '' === $key_suffix ) {
			throw new InvalidArgumentException(
				'A meta key pattern needs a literal prefix and suffix; a leading wildcard cannot use the meta_key index.'
			);
		}

		return new self(
			Storage_Kind::POST_META_ROWS,
			$value_kind,
			$anchor,
			key_prefix: $key_prefix,
			key_suffix: $key_suffix
		);
	}

	/**
	 * Membership in a taxonomy's terms.
	 *
	 * The anchor is forced to PARENT and cannot be overridden — `product_cat`,
	 * `product_tag` and `product_brand` are registered on `product` only, so under
	 * the variation scope there is no other correct reading. The term_id →
	 * term_taxonomy_id map is supplied by the engine for the reason in
	 * {@see Value_Map}.
	 *
	 * Descendants are **not** expanded, matching how `product_cat` already behaves.
	 * A provider wanting "in Acme, including its sub-brands" passes its own
	 * {@see Value_Map} that expands term ids first; the engine then maps the expanded
	 * set to tt_ids in the same single indexed read. MySQL 5.7 has no CTEs, so a
	 * recursive expansion in SQL is not available and is not wanted.
	 *
	 * @param string         $taxonomy Taxonomy name, e.g. `product_brand`.
	 * @param Value_Map|null $expand   Optional term-id expansion applied first.
	 *
	 * @throws InvalidArgumentException When the taxonomy name is empty.
	 */
	public static function taxonomy( string $taxonomy, ?Value_Map $expand = null ): self {
		if ( '' === $taxonomy ) {
			throw new InvalidArgumentException( 'A taxonomy storage needs a taxonomy name.' );
		}

		return new self(
			Storage_Kind::TAXONOMY,
			Value_Kind::INTEGER,
			Object_Anchor::PARENT,
			key_prefix: $taxonomy,
			value_map: $expand
		);
	}

	/**
	 * Rows in any table that carries an object-id column — WPML's icl_translations,
	 * an agency's own lookup table, a plugin's custom storage.
	 *
	 * The table is joined to nothing: the engine reads it alone, matching
	 * `$object_column` against the product (or its parent) and testing
	 * `$value_column`. Any further narrowing is a constant added with
	 * {@see matching()}. That restriction *is* the single-table invariant, and it is
	 * why this shape is safe to hand to a stranger.
	 *
	 * @param string        $table         Table name including the site prefix.
	 * @param string        $object_column Column holding the product/parent id.
	 * @param string        $value_column  Column the condition tests.
	 * @param Value_Kind    $value_kind    How that column is compared.
	 * @param Object_Anchor $anchor        Whose id `$object_column` holds.
	 *
	 * @throws InvalidArgumentException When any identifier is not a bare name.
	 */
	public static function related_rows(
		string $table,
		string $object_column,
		string $value_column,
		Value_Kind $value_kind,
		Object_Anchor $anchor = Object_Anchor::SELF
	): self {
		self::assert_table( $table );
		self::assert_column( $object_column );
		self::assert_column( $value_column );

		return new self(
			Storage_Kind::RELATED_ROWS,
			$value_kind,
			$anchor,
			table: $table,
			object_column: $object_column,
			value_column: $value_column
		);
	}

	/**
	 * Return a copy with one more constant test ANDed into the row predicate —
	 * `element_type = 'post_product'`, `is_active = 1`.
	 *
	 * Constants are ANDed only. A provider needing a disjunction cannot say it here
	 * and must declare two fields or narrow the question. That is less of a
	 * restriction than it reads: `storage_for()` receives the scope, and a query is
	 * always in exactly one scope, so a per-scope constant covers the case that
	 * looks like it needs a disjunction.
	 *
	 * @param string           $column Column name; validated as a bare identifier.
	 * @param int|float|string $value  Constant; bound, never interpolated.
	 * @param Value_Kind       $kind   How to bind it.
	 *
	 * @throws InvalidArgumentException When the column is not a bare identifier.
	 */
	public function matching( string $column, int|float|string $value, Value_Kind $kind = Value_Kind::TEXT ): self {
		// Refused rather than ignored, and the difference is the whole rule: a
		// narrowing that is silently dropped leaves a WIDER clause than the provider
		// asked for, which is the one direction nothing downstream can notice.
		//
		// A lookup column cannot carry one because the constant's column would be a
		// provider-supplied name interpolated beside `l.` — reopening the very set
		// {@see Lookup_Column} is a closed enum in order to close. A taxonomy cannot
		// because term_relationships has no column to constrain: object_id and
		// term_taxonomy_id are the whole row, and the taxonomy is already carried by
		// the resolved tt_ids.
		if ( Storage_Kind::LOOKUP_COLUMN === $this->kind || Storage_Kind::TAXONOMY === $this->kind ) {
			$refusal = sprintf(
				'A %s storage cannot carry a constant test; there is no column on it to constrain.',
				$this->kind->value
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message for a provider author; never reaches a page.
			throw new InvalidArgumentException( $refusal );
		}

		self::assert_column( $column );

		$copy              = clone $this;
		$copy->constants[] = array(
			'column' => $column,
			'value'  => $value,
			'kind'   => $kind,
		);

		return $copy;
	}

	/**
	 * A bare table name — letters, digits and underscores, no dot.
	 *
	 * @param string $table Candidate.
	 *
	 * @throws InvalidArgumentException When it is not one.
	 */
	private static function assert_table( string $table ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/', $table ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message for a provider author; never reaches a page.
			throw new InvalidArgumentException( sprintf( '"%s" is not a usable table name; take it from $wpdb rather than assembling it.', $table ) );
		}
	}

	/**
	 * A bare column name. The absence of a dot is deliberate: a qualified reference
	 * is how a second table gets into a membership subquery, and that two-table
	 * subquery is the measured four-minute plan.
	 *
	 * @param string $column Candidate.
	 *
	 * @throws InvalidArgumentException When it is not one.
	 */
	private static function assert_column( string $column ): void {
		if ( 1 !== preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $column ) ) {
			$message = sprintf(
				'"%s" is not a bare column name. Column names are literals you write in your own source; values belong in the condition.',
				$column
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message for a provider author; never reaches a page.
			throw new InvalidArgumentException( $message );
		}
	}
}
