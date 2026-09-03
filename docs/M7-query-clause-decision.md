# M7 — The filter-side provider contract

## 1. THE CALL

**Ship a declarative storage descriptor, not a SQL seam.** A provider implements `Filter_Provider::storage_for( string $key, Query_Scope $scope ): Field_Storage` — it names *where a value lives* (a lookup column, a postmeta key, a family of postmeta keys, a taxonomy, or one foreign table with an object-id column) and nothing else. It never sees the operator, never sees the operand, never sees the join slot, and never emits a character of SQL. A new engine-internal `Storage_Compiler` turns (descriptor + `Operator` + value + scope + join slot) into the one plan-safe shape the engine already measured: positive membership becomes `INNER JOIN ( SELECT DISTINCT … )` when the relation is AND and an uncorrelated `IN (SELECT …)` when it is OR; every negative operator is mapped to its positive twin and negated by the keyword *outside* the subquery; the alias is minted from the slot; every value is bound; secondary-key resolution runs before the SELECT through a `Value_Map`. There is **no raw-SQL escape hatch in v1**, and the vocabulary is designed to grow additively — a new named constructor on `Field_Storage` breaks no existing provider.

The single most important reason: **three of the four candidate designs shipped a *worked example* that silently edits products the user explicitly excluded**, and all three did it in the same place — the provider was handed the operand and the polarity. Design 1's and Design 4's ACF walkthroughs cast a list operand with `(string) $value`, producing `meta_value = 'Array'`, so an exclusion matches an empty set and `NOT IN (empty)` is true for every row; Design 3's ANDs its LIKE tokens, so negating the conjunction widens the exclusion. Not one of those is detectable by any lexical guard, any conformance kit, or the preview-equals-run promise itself, because preview and run agree perfectly on the wrong catalogue. Taking the operand and the operator away from the provider is the only move that deletes that entire bug class, and it is worth the closed vocabulary.

---

## 2. THE CONTRACT

All new files live under `src/Query/Fields/`. The three enums, two value objects and one interface below are the **public** surface; `Clause` and `Storage_Compiler` (§3) are engine-internal and are never handed to a provider.

```php
<?php
/**
 * How the stored value is compared, which decides the placeholder and the cast.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Operator;

/**
 * The distinction that matters here is between a value the database stores in its
 * own type and one stored as text. `l.min_price` is DECIMAL and compares directly;
 * a postmeta number is a string, so `'9' > '10'` is true unless the comparison
 * casts — and the cast is also what makes it unindexed. Naming the two separately
 * forces a provider to answer the question up front rather than discover it on a
 * customer's catalogue, and lets {@see is_indexed()} tell the preview when a
 * condition is going to scan.
 */
enum Value_Kind: string {

	/** A string column or meta value. Bound as `%s`. */
	case TEXT = 'text';

	/** A native integer column, e.g. `l.stock_quantity`. Bound as `%d`. */
	case INTEGER = 'integer';

	/** A native decimal column, e.g. `l.min_price`. Bound as `%f`. */
	case DECIMAL = 'decimal';

	/**
	 * A number stored as text — every postmeta number, every ACF number field.
	 * Compared as `CAST( … AS DECIMAL(20,4) )`, which is correct and which no index
	 * can serve, so it is reported unindexed.
	 */
	case NUMERIC_TEXT = 'numeric_text';

	/**
	 * A zero-padded big-endian date string — `Ymd` or `Y-m-d H:i:s` — which sorts
	 * and ranges correctly as text, so BETWEEN needs no cast.
	 *
	 * ACF's modern date fields store exactly those two formats, but its legacy
	 * `save_format` setting (kept for ACF < 5.0.0) offers `d/m/Y`, which a
	 * lexicographic range answers plausibly and wrongly — no error, no empty
	 * result, just the wrong products. A provider that cannot prove its format is
	 * big-endian and zero-padded must throw {@see Filter_Field_Unavailable} rather
	 * than declare this kind.
	 */
	case DATE_TEXT = 'date_text';

	/**
	 * A PHP-serialised list of strings, probed for containment.
	 *
	 * This is how ACF stores every multi-value field, and it works only because ACF
	 * casts each element to a string on save for exactly this purpose — its own
	 * source comments "Parse each value as string for SQL LIKE queries". The engine
	 * emits the quoted-token probe `%"12"%`; a bare `%12%` would also match 112 and
	 * 2012-12-01. Two consequences the provider accepts by choosing this kind:
	 * `meta_value` is an unindexed LONGTEXT so the probe scans the whole meta_key
	 * partition, and a field migrated from single to multiple stores its older rows
	 * bare, which the quoted-token pattern never matches — those products vanish
	 * from an inclusive filter with no signal.
	 */
	case SERIALIZED_LIST = 'serialized_list';

	/**
	 * The operators this kind can answer. A {@see Filter_Field} may declare a
	 * subset; it may never declare an operator outside this set.
	 *
	 * @return list<Operator>
	 */
	public function operators(): array {
		$presence = array( Operator::EXISTS, Operator::NOT_EXISTS );
		$equality = array( Operator::EQUALS, Operator::NOT_EQUALS, Operator::IN, Operator::NOT_IN );
		$ordered  = array(
			Operator::GREATER_THAN,
			Operator::GREATER_OR_EQUAL,
			Operator::LESS_THAN,
			Operator::LESS_OR_EQUAL,
			Operator::BETWEEN,
		);

		return match ( $this ) {
			self::TEXT            => array( ...$equality, Operator::CONTAINS, ...$presence ),
			self::INTEGER,
			self::DECIMAL,
			self::NUMERIC_TEXT    => array( ...$equality, ...$ordered, ...$presence ),
			self::DATE_TEXT       => array( ...$equality, ...$ordered, ...$presence ),
			self::SERIALIZED_LIST => array( Operator::IN, Operator::NOT_IN, ...$presence ),
		};
	}

	/**
	 * Whether a comparison of this kind can use an index. False for NUMERIC_TEXT
	 * (the cast defeats it) and SERIALIZED_LIST (an unindexed LONGTEXT LIKE).
	 */
	public function is_indexed(): bool {
		return self::NUMERIC_TEXT !== $this && self::SERIALIZED_LIST !== $this;
	}
}
```

```php
<?php
/**
 * Whose row carries the value.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Under the product scope both cases resolve to `l.product_id`, which is exactly
 * why this cannot be inferred from {@see \CatalogOps\Query\Query_Scope}: a wrong
 * anchor passes every product-scope test a provider author will write, and returns
 * a plausible, non-empty, wrong set the moment a user flips the Products/Variations
 * toggle.
 *
 * The question is not "which scope am I in" but "does this value live on the row
 * being selected, or on its parent?" — a fact about the data. A variation carries
 * its own price, stock, SKU, post meta and `attribute_{taxonomy}` meta, so those are
 * SELF. `product_cat`, `product_tag` and WooCommerce's `product_brand` are
 * registered on `product` only and never on `product_variation`, so a taxonomy is
 * PARENT and there is no other correct reading.
 */
enum Object_Anchor: string {
	case SELF   = 'self';
	case PARENT = 'parent';
}
```

```php
<?php
/**
 * The columns of wc_product_meta_lookup a field may be declared to be.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * A closed enum rather than a column name, because the alternative is a
 * provider-supplied identifier interpolated into the projection's own FROM clause.
 * WooCommerce keeps these indexed and they are already in every statement the
 * engine builds, so a field declared here costs no join and no subquery — the
 * cheapest shape available.
 */
enum Lookup_Column: string {
	case MIN_PRICE      = 'min_price';
	case MAX_PRICE      = 'max_price';
	case STOCK_QUANTITY = 'stock_quantity';
	case STOCK_STATUS   = 'stock_status';
	case SKU            = 'sku';
	case ON_SALE        = 'onsale';

	/**
	 * The value kind this column holds.
	 */
	public function value_kind(): Value_Kind {
		return match ( $this ) {
			self::MIN_PRICE, self::MAX_PRICE => Value_Kind::DECIMAL,
			self::STOCK_QUANTITY, self::ON_SALE => Value_Kind::INTEGER,
			self::STOCK_STATUS, self::SKU => Value_Kind::TEXT,
		};
	}

	/**
	 * Whether the column is nullable, so the compiler knows to guard a negative
	 * comparison with `IS NULL OR NOT ( … )`. `stock_quantity` is NULL on every
	 * product that does not manage stock, and `col != 5` silently excludes those.
	 */
	public function is_nullable(): bool {
		return self::STOCK_QUANTITY === $this;
	}
}
```

```php
<?php
/**
 * Turns the values a user picked into the values the storage actually holds.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Resolution that runs **before** any SELECT is built, once per condition per
 * request. It is not an optimisation, it is the mechanism that keeps a membership
 * subquery down to a single table.
 *
 * term_relationships is keyed by term_taxonomy_id, so asking it about term ids
 * means joining term_taxonomy inside the subquery — and that two-table subquery is
 * what mis-plans. Measured on an 18.5k-product catalogue: three categories answered
 * in 1s, a fourth flipped the plan, `p` lost its primary-key path for a range scan
 * with a block-nested-loop join, and the same query took **four minutes**. DISTINCT,
 * GROUP BY and a wrapped derived table all still timed out. Resolving term ids to
 * term_taxonomy_ids first — one small indexed lookup — brought the same case to
 * 992ms and kept it flat from there (see
 * {@see \CatalogOps\Query\Query_Engine::term_taxonomy_ids()}).
 *
 * An implementation must be a pure function of its input and durable database
 * state. Preview counts in one HTTP request and {@see
 * \CatalogOps\Operations\Operation_Service::queue()} freezes in another, sometimes
 * days later on a cron tick with no user present; anything read here that can change
 * between the two silently makes the run a different size than the preview promised.
 * The compiler memoises the result for the life of the request, so a single
 * preview — which renders its filter 2+N+W times — pays for it once.
 */
interface Value_Map {

	/**
	 * Map user-supplied operands onto stored ones.
	 *
	 * Returning an empty list is a real answer, not a failure: it means nothing in
	 * the catalogue can hold this value. The compiler turns that into match-nothing
	 * for a positive test and match-everything for a negative one, which is what
	 * "exclude a brand that no longer exists" has to mean.
	 *
	 * @param list<int|float|string> $values Operands, already cast to the field's
	 *                                       value kind and capped in length.
	 * @return list<int|float|string> Stored operands; may be empty.
	 */
	public function map( array $values ): array;
}
```

```php
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
	 * @param Storage_Kind   $kind          Which shape this is.
	 * @param Value_Kind     $value_kind    How the stored value is compared.
	 * @param Object_Anchor  $anchor        Whose row carries it.
	 * @param string         $table         Table name, or '' for a lookup column.
	 * @param string         $object_column Column holding the object id.
	 * @param string         $value_column  Column the condition tests.
	 * @param Lookup_Column|null $column    The lookup column, for that kind only.
	 * @param string         $key_prefix    Literal meta-key prefix, or ''.
	 * @param string         $key_suffix    Literal meta-key suffix, or ''.
	 * @param Value_Map|null $value_map     Operand pre-resolution, if any.
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
	 * inside the key string rather than in a column. See §6.
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
	 * and must declare two fields or narrow the question; see §6.
	 *
	 * @param string           $column Column name; validated as a bare identifier.
	 * @param int|float|string $value  Constant; bound, never interpolated.
	 * @param Value_Kind       $kind   How to bind it.
	 *
	 * @throws InvalidArgumentException When the column is not a bare identifier.
	 */
	public function matching( string $column, int|float|string $value, Value_Kind $kind = Value_Kind::TEXT ): self {
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
			throw new InvalidArgumentException(
				sprintf( '"%s" is not a usable table name; take it from $wpdb rather than assembling it.', $table )
			);
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
			throw new InvalidArgumentException(
				sprintf(
					'"%s" is not a bare column name. Column names are literals you write in your own source; values belong in the condition.',
					$column
				)
			);
		}
	}
}
```

```php
<?php
/**
 * Which storage shape a Field_Storage describes.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Internal to the compiler's dispatch. It is public only because
 * {@see Field_Storage} is a readonly value object; providers never name a case.
 */
enum Storage_Kind: string {
	case LOOKUP_COLUMN  = 'lookup_column';
	case POST_META      = 'post_meta';
	case POST_META_ROWS = 'post_meta_rows';
	case TAXONOMY       = 'taxonomy';
	case RELATED_ROWS   = 'related_rows';
}
```

```php
<?php
/**
 * How the filter UI collects a value for a field.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Deliberately distinct from {@see \CatalogOps\Operations\Fields\Field_Type}, which
 * records *where a written value is stored* so undo can route a recorded delta back
 * without the original UI key. That enum answers "how do I write this back"; this one
 * answers "what does the user type".
 *
 * The two were conflated once already: `Field_Provider::get_fields()` is documented
 * as returning a UI `type`, and {@see \CatalogOps\Operations\Fields\Core_Fields}
 * fills it with `Field_Type::POST_FIELD` for `stock_status`, `name`, `sku` and
 * `regular_price` alike — the same value for a select, a text box and a money box —
 * which is why it could never have driven a control. M7 is the last moment that can
 * be separated, because after it the meaning is public.
 */
enum Filter_Control: string {
	case NUMBER    = 'number';
	case MONEY     = 'money';
	case TEXT      = 'text';
	case ENUM      = 'enum';
	case TERM_SET  = 'term_set';
	case VALUE_SET = 'value_set';
	case DATE      = 'date';
	case TOGGLE    = 'toggle';
}
```

```php
<?php
/**
 * Everything the plugin needs to know about one filterable field.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;

/**
 * One descriptor answers four questions that are answered by four hardcoded lists
 * today: which control the filter UI renders and with which operators (hardcoded
 * twice in the React bundle, in `emptyForm()` and `buildFilter()`); whether the
 * results table shows a column for it (hardcoded in
 * {@see \CatalogOps\Rest\Query_Controller::meta_columns()}); whether an Adjust on it
 * needs a presence requirement so the preview stays exact (hardcoded in
 * {@see \CatalogOps\Operations\Write_Rules}); and, with {@see Field_Storage}, how the
 * clause is built.
 *
 * The operator list is load-bearing in both directions: the UI offers nothing outside
 * it, and the registry refuses a persisted condition whose operator is not in it
 * *before* calling the provider. That is what makes "I do not support this operator"
 * a hard error rather than the empty fragment that silently widens an AND filter.
 *
 * The scope list is the same promise for scopes. A field omitted from a scope is
 * refused there by name — never answered wrongly, which is the only other option.
 */
final class Filter_Field {

	/**
	 * Describe a filterable field.
	 *
	 * @param string            $key                Stable key, persisted in filter_json for the
	 *                                              life of every saved filter and schedule.
	 *                                              Namespace it (`acf:approved`, `wpml:language`)
	 *                                              and never change it.
	 * @param string            $label              Translated label for the filter row.
	 * @param Filter_Control    $control            How the UI collects a value.
	 * @param list<Operator>    $operators          Operators offered and accepted. Must be a
	 *                                              subset of the storage's value kind.
	 * @param list<Query_Scope> $scopes             Scopes this field answers correctly in.
	 * @param string            $options_route      REST route serving options for a set control,
	 *                                              or '' for free entry.
	 * @param string|null       $column_label       Header for a results-table column showing this
	 *                                              field, or null for none. 0.7.1 added the brand
	 *                                              column precisely because filtering by something
	 *                                              the results do not show was a defect; leaving
	 *                                              this null reintroduces it.
	 * @param bool              $required_for_write True when an Adjust or Formula targeting this
	 *                                              field needs a presence requirement to keep the
	 *                                              preview exact (see §5).
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly Filter_Control $control,
		public readonly array $operators,
		public readonly array $scopes,
		public readonly string $options_route = '',
		public readonly ?string $column_label = null,
		public readonly bool $required_for_write = false,
	) {}

	/**
	 * Whether this field answers the given operator.
	 *
	 * @param Operator $operator The operator asked for.
	 */
	public function supports( Operator $operator ): bool {
		return in_array( $operator, $this->operators, true );
	}

	/**
	 * Whether this field means anything in the given scope.
	 *
	 * @param Query_Scope $scope The object type being queried.
	 */
	public function applies_in( Query_Scope $scope ): bool {
		return in_array( $scope, $this->scopes, true );
	}
}
```

```php
<?php
/**
 * Contract for a module that adds filterable fields.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Query_Scope;

/**
 * The read half of the M7 public API, and a deliberate sibling of
 * {@see \CatalogOps\Operations\Fields\Field_Provider} rather than more methods on it.
 *
 * Three reasons, all load-bearing. Writing and filtering are different capabilities:
 * WPML can tell you a product's language and has nothing to write; a theme provider
 * can write a field with no SQL-expressible question. Adding a method to
 * `Field_Provider` would break `Core_Fields`, `Meta_Fields` and every third-party
 * write-only provider on upgrade. And the two key spaces are already separate —
 * `price` filters `l.min_price` while `regular_price` writes `_regular_price`, and
 * neither key exists on the other side. A module doing both registers on both hooks.
 *
 * The original design (CONTEXT §3) specified
 * `query_clause( string $key, string $op, $value ): array`. That signature cannot
 * ship: it carries no scope, so it is wrong under the variation scope; no join slot,
 * so it is unsafe under OR; a string operator where the persisted JSON round-trips an
 * enum; and an `array` return whose slots a stranger can transpose into an injection.
 * What replaced it keeps the idea and moves every one of those decisions to the side
 * of the seam that measured them.
 */
interface Filter_Provider {

	/**
	 * The licensed module these fields belong to, or '' when they are free.
	 *
	 * Returned as a constant, never computed: the registry asks once per condition
	 * and the answer must not depend on anything that can change between the preview
	 * request and the queue request.
	 *
	 * The gate is enforced at the clause seam, never by declining to register. A
	 * provider that vanishes when a licence lapses turns every saved filter and every
	 * recurring schedule naming its fields into a *wider* filter — and `modules` is
	 * the one capability that goes false the instant a Studio plan lapses, because
	 * {@see \CatalogOps\Licensing\License::resolve()} draws `premium` from
	 * `can_use_premium_code()` (true after expiry by policy) but `modules` from
	 * `is_plan_or_trial( 'studio' )`. Registered-and-refused is the only safe shape.
	 */
	public function module(): string;

	/**
	 * The filterable fields this provider offers.
	 *
	 * @return list<Filter_Field>
	 */
	public function filter_fields(): array;

	/**
	 * Whether this provider owns the given filter key.
	 *
	 * Must be cheap and pure: it is asked once per condition per rendered statement,
	 * and one preview renders its filter 2+N+W times.
	 *
	 * @param string $key Filter key as persisted in filter_json.
	 */
	public function handles_filter( string $key ): bool;

	/**
	 * Where this field's value lives, for this scope.
	 *
	 * Note what this method is *not* given: the operator, the operand, the join slot,
	 * the relation, or a database handle for building SQL. Those are the four places a
	 * provider could be careful and still be wrong, and they belong to the engine.
	 * What is left is a question about one storage location.
	 *
	 * The scope is a parameter, not a hint — a field's storage may genuinely differ.
	 * A parent product's attribute is a taxonomy term; the same attribute on a
	 * variation is the variation's own `attribute_{taxonomy}` post meta holding a term
	 * slug. Only ever called for a scope the field declared.
	 *
	 * Must be a pure function of ( $key, $scope ) and durable database state. Preview
	 * counts in one HTTP request; queue freezes in another, sometimes days later on a
	 * cron tick with no user present, rebuilding from the same persisted filter_json.
	 * No clock, no current user, no current admin language, no request state, no
	 * `switch_to_blog()`, no remote call. The compiler memoises the compiled clause per
	 * request, so a lookup here is paid once per preview rather than 2+N+W times.
	 *
	 * @param string      $key   A key {@see handles_filter()} claimed.
	 * @param Query_Scope $scope The object type being queried.
	 *
	 * @throws Filter_Field_Unavailable When the field cannot be expressed right now —
	 *                                  an ACF field that was deleted, a legacy date
	 *                                  format no lexical range can order, a dependency
	 *                                  that is inactive. Refuse; there is no way to
	 *                                  return "no constraint", because that is exactly
	 *                                  how a filter silently widens.
	 */
	public function storage_for( string $key, Query_Scope $scope ): Field_Storage;
}
```

```php
<?php
/**
 * Raised when a filter names a field that cannot be answered.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use InvalidArgumentException;

/**
 * The read-side twin of the write side's refusal in
 * {@see \CatalogOps\Operations\Operation_Service::assert_fields_supported()}, and the
 * reason the read path can now fail loudly: the write path already does, and the
 * asymmetry between them was the whole safety problem of the seam.
 *
 * Before this, {@see \CatalogOps\Query\Query_Engine::clause_for()} ended in
 * `return array( '', array() )` for an unrecognised field, `build_where()` skipped the
 * empty fragment, and `select()` appended no WHERE at all when every fragment was
 * empty. Under an AND relation that turned "brand Acme, in stock" into "in stock" — a
 * strictly larger set, previewed and applied identically, so every check the plugin
 * makes agreed it had done the right thing. A deactivated module, a lapsed Studio
 * plan, a typo in a saved filter or a fatal in a module's boot all produced the same
 * outcome: a catalogue-wide edit nobody asked for, and on a schedule with nobody
 * watching.
 *
 * It extends InvalidArgumentException so the REST controllers' existing
 * `catch ( InvalidArgumentException )` blocks map it to HTTP 400 with no change, and
 * so a throw from `queue()` lands inside {@see
 * \CatalogOps\Operations\Schedule_Runner::fire()}'s try, which pauses that schedule
 * rather than firing it against a wider set. A licence refusal is deliberately *not*
 * this exception — it is {@see \CatalogOps\Licensing\License_Limited}, so the client
 * answers 402 and can offer the upgrade instead of a dead end.
 */
final class Filter_Field_Unavailable extends InvalidArgumentException {

	/**
	 * Build the refusal.
	 *
	 * @param string $message Why, naming the field.
	 * @param string $field   The filter key that could not answer.
	 */
	public function __construct( string $message, public readonly string $field = '' ) {
		parent::__construct( $message );
	}
}
```

```php
<?php
/**
 * Routes a filter key to the provider that owns it, and enforces the module gate.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Query_Scope;

/**
 * The single choke point every path shares — the results table, the previewed count,
 * the incremental requirement counts, the warning counts, the sample, the
 * other-scope hint, and the one freezing resolve — which is why the licence gate
 * lives here and not in a REST permission callback.
 *
 * {@see \CatalogOps\Operations\Schedule_Runner} rehydrates a stored filter and queues
 * it on a cron tick without touching a controller, and
 * {@see \CatalogOps\Rest\Query_Controller} is not even constructed with a License. A
 * REST-only gate looks correct in every manual test — the UI hides the control, the
 * endpoint answers 402 — and is bypassed entirely by the first unattended run under a
 * lapsed licence.
 */
final class Filter_Providers {

	/**
	 * Filter keys the engine answers itself; a provider may not claim one.
	 *
	 * Resolution is first-claim-wins, so without this a module registering `price` or
	 * `category` would silently redefine what a core filter means.
	 *
	 * @var list<string>
	 */
	private const RESERVED = array( 'price', 'stock_quantity', 'stock_status', 'sku', 'category', 'tag' );

	/**
	 * Reserved key prefixes, matched with `str_starts_with()`.
	 *
	 * @var list<string>
	 */
	private const RESERVED_PREFIXES = array( 'attribute:', 'meta:' );

	/**
	 * Registered providers, in resolution order.
	 *
	 * @var list<Filter_Provider>
	 */
	private array $providers;

	/**
	 * Build the registry.
	 *
	 * @param License            $license      The resolved licence.
	 * @param Filter_Provider ...$providers    Providers, tried in order.
	 */
	public function __construct( private readonly License $license, Filter_Provider ...$providers ) {
		$this->providers = array_values( $providers );
	}

	/**
	 * The provider owning a key, or null when none does.
	 *
	 * @param string $key Filter key.
	 */
	public function for( string $key ): ?Filter_Provider {
		if ( in_array( $key, self::RESERVED, true ) ) {
			return null;
		}

		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return null;
			}
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->handles_filter( $key ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Every filterable field across all providers, for the filter UI. Fields whose
	 * module the licence does not permit are still listed and flagged unavailable, so
	 * the UI can show them locked rather than silently dropping a field a saved filter
	 * already uses.
	 *
	 * @return list<array{field: Filter_Field, module: string, available: bool}>
	 */
	public function all_fields(): array {
		$fields = array();

		foreach ( $this->providers as $provider ) {
			$module    = $provider->module();
			$available = '' === $module || $this->license->has_module( $module );

			foreach ( $provider->filter_fields() as $field ) {
				$fields[] = array(
					'field'     => $field,
					'module'    => $module,
					'available' => $available,
				);
			}
		}

		return $fields;
	}

	/**
	 * Check every condition in a filter before any SQL is built, so a refusal names
	 * the field at the operation boundary rather than mid-statement.
	 *
	 * Core keys are skipped: the engine answers them itself.
	 *
	 * @param Filter $filter The filter to check.
	 *
	 * @throws Filter_Field_Unavailable When a field is unknown, dropped, out of scope,
	 *                                  or asked an operator it does not declare.
	 * @throws License_Limited          When the owning module is not licensed.
	 */
	public function assert_supported( Filter $filter ): void {
		foreach ( $filter->conditions() as $condition ) {
			if ( $this->is_core_key( $condition->field ) ) {
				continue;
			}

			$this->field_for( $condition, $filter->scope() );
		}
	}

	/**
	 * Resolve a condition to the {@see Filter_Field} that may answer it, refusing
	 * with a named field for every reason it cannot.
	 *
	 * @param Condition   $condition The condition.
	 * @param Query_Scope $scope     The object type being queried.
	 *
	 * @throws Filter_Field_Unavailable When the field cannot answer this question.
	 * @throws License_Limited          When the owning module is not licensed.
	 */
	public function field_for( Condition $condition, Query_Scope $scope ): Filter_Field {
		$key      = $condition->field;
		$provider = $this->for( $key );

		if ( null === $provider ) {
			throw new Filter_Field_Unavailable(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing; sanitized at the REST boundary.
				sprintf(
					'This filter uses the field "%s", which no active plugin provides. Reactivate the plugin that supplies it or remove the condition — CatalogOps will not run an edit against a wider set than the filter names.',
					$key
				),
				$key
			);
		}

		$module = $provider->module();

		if ( '' !== $module && ! $this->license->has_module( $module ) ) {
			throw new License_Limited(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing; sanitized at the REST boundary.
				sprintf(
					'This filter uses the field "%s", which needs the Studio plan. Running the edit without the condition would target more products than the filter names, so it is refused rather than ignored.',
					$key
				)
			);
		}

		$field = null;

		foreach ( $provider->filter_fields() as $candidate ) {
			if ( $candidate->key === $key ) {
				$field = $candidate;
				break;
			}
		}

		if ( null === $field ) {
			throw new Filter_Field_Unavailable(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing; sanitized at the REST boundary.
				sprintf( 'The field "%s" is no longer offered by the module that provided it.', $key ),
				$key
			);
		}

		if ( ! $field->applies_in( $scope ) ) {
			throw new Filter_Field_Unavailable(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing; sanitized at the REST boundary.
				sprintf( 'The field "%1$s" does not apply to %2$ss.', $key, $scope->value ),
				$key
			);
		}

		if ( ! $field->supports( $condition->operator ) ) {
			throw new Filter_Field_Unavailable(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing; sanitized at the REST boundary.
				sprintf(
					'The comparison "%1$s" is not available for the field "%2$s".',
					$condition->operator->value,
					$key
				),
				$key
			);
		}

		return $field;
	}

	/**
	 * Whether a key belongs to the engine's own dispatch.
	 *
	 * @param string $key Filter key.
	 */
	private function is_core_key( string $key ): bool {
		if ( in_array( $key, self::RESERVED, true ) ) {
			return true;
		}

		foreach ( self::RESERVED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
```

---

## 3. HOW IT COMPOSES INTO `Query_Engine`

Three edits and two new engine-internal classes. **The five existing core clause builders are not touched.** `numeric_clause()` (:285-323), `string_clause()` (:332-359), `taxonomy_clause()` (:406-469), `variation_attribute_clause()` (:511-574) and `meta_clause()`/`meta_value_test()` (:631-723) stay exactly as measured. That is a deliberate reversal of the "make the built-ins the reference implementations" argument: the reason that argument had force was that a stranger reading `meta_clause()` would copy its positive `l.product_id IN (SELECT …)` semi-join in defiance of the docs — and under this contract a provider *cannot copy any SQL at all*, so the contradiction is inexpressible. The reference implementations become the first-party module providers, shipped in-repo, written against the public vocabulary.

**Constructor (:37-39).** Gains a nullable second parameter, `?Filter_Providers $providers = null`. Nullable because thirteen sites construct `new Query_Engine( $wpdb )` — twelve integration tests plus `docker/mysql8/generate-explain-sql.php:37` — and a required argument would edit every one of them for no behavioural reason and bury the real diff. Only `src/Plugin.php:276` changes.

**`clause_for()` (:230-275).** The eight-branch core chain at :233-271 is untouched. The fall-through at :273-274 —

```php
// Unknown field: ignore rather than produce broken SQL.
return array( '', array() );
```

— becomes `return $this->provider_clause( $condition, $scope, $join_slot );`.

**New `provider_clause()`.** Resolves the condition through `Filter_Providers::field_for()` (which throws `Filter_Field_Unavailable` for an unknown/dropped/out-of-scope/undeclared-operator field, and `License_Limited` for an ungated module), calls `storage_for( $key, $scope )` inside a `try` that converts any other `Throwable` into `Filter_Field_Unavailable` — a module that fatals must not become a 500 on the results table or a wedged cron tick — and hands the descriptor to `Storage_Compiler::compile()`. The compiler returns a `Clause` value object with named `where` / `where_args` / `join` / `join_args`; `provider_clause()` unwraps it into the existing four-slot tuple at the boundary. The tuple stays private and unpublished; only the compiler ever fills it, so its slots cannot be transposed by anyone.

**`Storage_Compiler::compile( Filter_Field $field, Field_Storage $storage, Operator $operator, mixed $value, Query_Scope $scope, ?int $join_slot ): Clause`** — the whole plan authority, in order:

1. **Polarity split.** `NOT_EQUALS → EQUALS`, `NOT_IN → IN`, `NOT_EXISTS → EXISTS`, plus a `$negative` flag. The value test is built only from the positive twin, so pushing a negation inside a subquery is not expressible anywhere in the system. This is the `$positive` mapping at Query_Engine.php:641-646 promoted from a local in one builder to the only path.
2. **Operand normalisation.** Cast per `Value_Kind` (`intval`/`floatval`/`strval`), reject non-scalars, cap the list at `Field_Storage::MAX_OPERANDS`. wpdb replaces a non-scalar argument with `''` after a `_doing_it_wrong` nobody sees, so a nested array from the schemaless REST body would otherwise quietly become `= ''`.
3. **Value map**, memoised on `md5( $field->key . '|' . $operator->value . '|' . wp_json_encode( $value ) . '|' . $scope->value )` in a per-request array. This kills the 2+N+W multiplier — `preview()` counts at :217, once per requirement at :228, once per warning at :324, resolves the sample at :280, and `freeze_edit()` resolves again at :566 — and it makes the preview's `applicable - remaining` differencing coherent, because every render of one condition now sees identical operands.
4. **Empty-set resolution.** An empty mapped set, or an empty `IN` list, is `1 = 0` positively and `1 = 1` negatively — the convention already at :426-431, applied uniformly. An empty operand list is *never* overloaded with "any value"; `EXISTS`/`NOT_EXISTS` carry that question.
5. **Object column.** `Object_Anchor::PARENT` under the variation scope → `p.post_parent`; everything else → `l.product_id`. Identical to :435.
6. **Shape selection**, per kind:

| kind | positive, slot given | positive, slot null (OR) | negative |
|---|---|---|---|
| `LOOKUP_COLUMN` | `( l.col <cmp> %x )` | same | `( l.col IS NULL OR NOT ( … ) )` when nullable, else `NOT ( … )` |
| `POST_META`, `POST_META_ROWS` | `INNER JOIN ( SELECT DISTINCT pm.post_id FROM postmeta pm WHERE … ) co_f{n} ON co_f{n}.post_id = <obj>` | `<obj> IN ( SELECT pm.post_id … )` | `<obj> NOT IN ( SELECT pm.post_id … )` |
| `TAXONOMY` | `INNER JOIN ( SELECT DISTINCT tr.object_id … ) co_f{n} ON …` | `<obj> IN ( SELECT tr.object_id … )` | `NOT EXISTS ( SELECT 1 FROM term_relationships tr WHERE tr.object_id = <obj> AND … )` |
| `RELATED_ROWS` | `INNER JOIN ( SELECT DISTINCT r.<id> … ) co_f{n} ON …` | `<obj> IN ( SELECT r.<id> … )` | `<obj> NOT IN ( SELECT r.<id> … WHERE r.<id> IS NOT NULL AND … )` |

The negative row is deliberately **not** uniform, and that is a correction to two of the four candidate designs. Postmeta and foreign tables take the uncorrelated `NOT IN` — measured on the 18.5k catalogue at **2.5s** against **13.6s** for the correlated `NOT EXISTS` on a lone brand exclusion, converging to 568ms vs 594ms once any positive condition narrows the candidates (Query_Engine.php:612-620). Taxonomy keeps `NOT EXISTS`, because that is the deliberate, measured choice at :438-448 and it is NULL-safe by construction. `RELATED_ROWS` adds `r.<id> IS NOT NULL` because a third-party column may be nullable where `post_id` and `object_id` are not, and an uncorrelated `NOT IN` over a nullable column returns UNKNOWN for every row and matches nothing — which a user reads as "my filter is too narrow", not as a bug. On a `NOT NULL` column MySQL removes the predicate, so the measured shape is preserved.

`SERIALIZED_LIST` with `IN` is the one place the compiler emits a disjunction: `( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )` inside the subquery, one `%s` per operand, each bound as `'%"' . esc_like( $v ) . '"%'`. Because the *engine* expands the operand list, the "is one of" that becomes "is all of" — Design 3's own worked-example bug, and a widening exclusion once negated — cannot be written.

**`build_where()` (:181-216).** Two changes. `$fragments[]` at :205 becomes `'( ' . $fragment . ' )'`, so a fragment carrying a top-level OR cannot be glued with `' AND '` into something that makes the whole filter true. Everything else — the `$joinable ? $index : null` at :190, the join collection at :196-199, the `$glue` at :213 — is untouched.

**`select()` (:90-167).** The requirement append at :154 gains the same parentheses (`' AND ( ' . $fragment . ' )'`), closing the identical hole on the existing, currently-unparenthesised Requirement path. The join splice at :135 and the argument ordering at :140 (`array( ...$join_args, $scope->post_type() )`) are unchanged and are the reason a provider join must never carry its values in the WHERE list — placeholders bind by textual position through one `vsprintf`, and the FROM clause precedes the WHERE. Because `Clause::join()` and `Clause::where()` hold physically separate argument lists and the compiler is the only caller of either, that mistake has nowhere to happen. One new assertion goes immediately before the `prepare()` at :166 (see §4).

**Under an OR filter.** `build_where()` already passes `null` for the slot when `Filter::RELATION_OR === $filter->relation()`. The compiler branches on it internally; the provider never sees it, so "I forgot the null check" is not expressible, and a join can never turn `A OR B` into `A AND B`. The cost is real and known: under OR, positive membership degrades to the `IN (SELECT …)` semi-join that is the documented plan hazard, and several of them stacked in one statement is untested. This is survivable for M7 because the shipped React app hardcodes `relation: 'AND'` and offers no relation control — the OR path is contract-tested but unreachable by users. If a relation control ever ships, the semi-join stacking must be EXPLAINed on the 18.5k catalogue first, and the preview should warn above two.

---

## 4. WHAT THE ENGINE ENFORCES AT RUNTIME

**Placeholder/argument parity over the whole assembled statement**, immediately before `prepare()` at :166: count `%s`/`%d`/`%f` with WordPress's own format grammar and compare against `count( $args )`; reject any other percent sign; reject a non-scalar argument. **On failure: throw, refusing the operation.** This is the highest-value check available because both failure modes are silent. Too few arguments makes `prepare()` return the empty string (class-wpdb.php:1710-1723), `get_var('')` returns null, `count()` casts it to 0, and the preview says "0 products match" — a wrong answer the user believes, adjusts their filter around, and never reports. Too many, and `vsprintf` binds the tail one slot off: a query that runs and answers a different question. The check also covers the Requirement path, which is still hand-written SQL, and it catches a compiler bug of mine as readily as a provider's. WordPress's escape pass permits a space as a printf padding character, so `'100% dry'` inside a fragment parses as a real placeholder; the check is stricter than wpdb's on purpose.

**Identifier validation at `Field_Storage` construction** — bare names, no dot. Fails at build time with a message naming the identifier, which is a `Filter_Field_Unavailable` by the time it reaches the caller. This is what mechanically enforces the single-table subquery rule: `tt.taxonomy` will not compile, so `term_taxonomy` cannot be joined inside a membership subquery, so the four-minute plan is unreachable rather than warned about.

**Operator, scope and module checks in `Filter_Providers::field_for()`, before the provider is called.** An undeclared operator, an undeclared scope, an unknown field or a dropped field is `Filter_Field_Unavailable` (HTTP 400); an unlicensed module is `License_Limited` (HTTP 402). Called from `Operation_Service::create()` (:170), `preview()` (:214) and `freeze_edit()` (:554) as `assert_supported( $filter )`, alongside the existing `assert_fields_supported( $actions )`, and again from `Query_Controller::query()` and `other_scope()`.

**Operand cap and scalar check** in the compiler. A list over 1000 entries is refused with the field named; a non-scalar operand is refused.

**Filter signature.** `Query_Engine` gains `signature( Filter $filter, array $requirements = array() ): string` — `sha1` of the rendered statement plus its argument vector. `preview()` returns it; the client posts it back on apply; `queue()` recomputes it before the freezing `resolve()` at :566 and throws on mismatch, which the REST layer maps to **409** with "the catalogue or a module changed since you previewed — re-preview to see the new number". For schedules the signature is computed and stored when the schedule is saved, and compared in `Schedule_Runner::fire()`; a mismatch **pauses the schedule with a recorded reason** rather than firing it. Today nothing links preview to apply — no token, no hash, no id, just the same JSON posted twice — so "preview equals run" is an emergent property of clause building happening to be deterministic. This makes it checked, and it is the only mechanism that reaches the unattended path.

### Where the operation must refuse to run

Any condition whose field cannot be resolved to a storage — unknown, dropped, out of scope, undeclared operator, unlicensed module, provider threw. Refusing is not a preference here: a dropped condition under an AND relation strictly *widens* the target set, preview and run agree on the wider set, and every downstream check reports success. A `-10%` price edit filtered on `acf:clearance` becomes a `-10%` edit on the whole shop. The same applies to a placeholder/argument mismatch and a signature mismatch at freeze: in every one of those cases the id set the run would freeze is possibly wrong in the widening direction, and there is no safe narrower thing to substitute.

### Where refusing would be overreach

**A slow or unindexed clause.** `Value_Kind::is_indexed()` returning false, a `SERIALIZED_LIST` probe, a short `post_meta_rows` prefix — these are surfaced as a preview warning ("this condition scans; it may take a while"), never blocked. Some questions have no indexed form, and a plugin that refuses to answer them is worse than one that answers them slowly with a warning.

**An empty mapped set.** That is a real answer — `1 = 0` positively, `1 = 1` negatively — not a failure. "Exclude a brand that was deleted" must keep every product.

**A missing results-table column.** `column_label` being null is a documentation lint and a conformance-kit warning, not a refusal.

**Repairing a filter after a licence lapse.** Offering to remove the offending condition and re-preview is friendlier than a dead end, but the rewritten filter matches a *larger* set, so it must never happen automatically, by default, or without the user seeing the new count. The recovery path is: refuse, name the field, offer "remove this condition and preview again" as an explicit action the user takes.

**A signature mismatch on the interactive path** is a 409 asking for a re-preview, not a hard failure — the catalogue legitimately changes between two requests.

---

## 5. THE OTHER SEAMS THIS FORCES

**The filterable-field list and operator advertisement — BEFORE.** Nothing in PHP serves the filter's field list today: it is hardcoded twice in `assets/src/admin/index.js`, once in `emptyForm()` and once in `buildFilter()`, with the field key, operator and value coercion written by hand per field. `Field_Providers::all_fields()` is dead code with no caller. A conformant provider with perfect SQL is invisible without a `/fields/filterable` route serving `Filter_Providers::all_fields()` and a React section that renders from it. This is the single largest cost in M7 and the one most likely to slip the milestone. The de-risking step: keep the eight hardcoded controls exactly as they are and append one generic "module fields" section below them, rendered from the descriptor. That unblocks the modules without rewriting 220 lines of tested JSX; collapsing the four bespoke option endpoints into a generic per-field options route can wait.

**The value control the UI renders — BEFORE, and it is the same work.** `Filter_Control` is why the descriptor exists as a separate enum from `Field_Type`. The client needs the control kind, the operator set, the value type it must send (int term ids versus strings — `buildFilter()` hardcodes `.map(Number)` for category/tag/attribute and raw strings for brand) and an options route. Four control kinds cover the three M7 modules: `TERM_SET`, `VALUE_SET`, `TEXT`, `NUMBER`.

**Saved filters and schedules naming a vanished provider or lapsed licence — BEFORE, and it is cheap.** Three parts. (a) `Schedule_Runner::fire()` calls `$this->service->create(...)` at line 119, **outside** the `try` that begins at line 127, and `run_due()` (:68-85) has no per-schedule guard — so a throw during create escapes `run_due()` and starves every other due schedule on that tick. This is already broken for an unsupported *action* field; adding a condition check to `create()` makes it reachable a second way. Move the create call inside the try: two lines, disproportionate consequence. (b) `Schedules::set_status()` takes only a `Schedule_Status`, so "paused because the ACF module is gone" has nowhere to be recorded — add a `paused_reason` column following the existing skip-reason-column migration precedent, and notify. (c) Fail closed at the clause seam, never by declining to register the provider.

**Applicability / `Requirement` / `Write_Rules` — split.** `Write_Rules` is `final` with no filter, its `READ_META_KEYS` covers four core keys, and `read_meta_keys()` maps anything else only when it starts with `meta:`. So an `Adjust` on a module-written field (`acf:price_floor`) yields no `Meta_Present` requirement: the preview promises every matched object and the run skips the valueless ones as `EMPTY_INPUT`. That is a live preview≠run break that exists *today*, before any of this. The blocking part is one line, not a seam: **`Operation_Service` must refuse an `Adjust` or `Formula` whose field is not covered by `Write_Rules`'s read-key map** — before the first module that offers such a field. Brands writes taxonomy terms and is never an Adjust target, so brands can ship without it; ACF cannot. The full seam — `Requirement::sql()` gaining a join channel and a slot, and `Write_Rules` deriving requirements from `Filter_Field::$required_for_write` — can wait for the module after brands, and it should, because it is the harder half: both existing requirements (`Meta_Present`, `Minimum_Meta`) emit the positive `l.product_id IN (SELECT pm.post_id …)` semi-join that `taxonomy_clause()` was rewritten away from, and they stack with whatever the filter contributed. Opening it to third parties without the join channel would multiply exactly the shape the four-minute measurement forbids.

**Undo routing — CAN WAIT, and should not change at all.** Undo works from `(field_type, storage_key)` through `Field_Providers::for_storage()` and `ui_key()`; the read seam touches none of it. The one obligation is documentation: a module that both writes and filters must implement `ui_key()` such that `storage_key( ui_key( $type, $k ) ) === $k`, or its deltas cannot be routed back. That belongs in the write-side docs, which already exist.

**A provider deactivated mid-operation — CAN WAIT, but it is a real hole and should be named in the release notes.** Once frozen, the filter is dead and this seam is irrelevant; but `Evaluator` drops actions whose field no provider claims, `Apply_Plan` skips unrouted rows without marking them applied or skipped, `Changes::pending_chunk()` selects `status = 0` with no offset so the same rows return forever, and `Chunk_Runner` re-enqueues while refreshing the heartbeat — the operation loops with the write lock held and the watchdog seeing a healthy run. Whatever fixes that must run at each chunk, not only at freeze, and it is a separate defect from the filter seam.

---

## 6. WHAT IS DELIBERATELY OUT OF SCOPE

There is **no raw-SQL tier**, in v1 or planned. A lexical audit can check balanced parentheses, comment markers, percent signs and placeholder parity, and it cannot see negation inside a subquery, a two-table membership subquery, a correlated `EXISTS`, or a value concatenated into the fragment instead of bound. Those are precisely the failures that cost hours to find and that return correct results on any catalogue the author can test against. A tier whose guarantees stop exactly where the danger starts is worse than an honest wall, because it looks like protection.

The wall is stated plainly, per shape:

**ACF flexible content, layout-scoped.** The layout name lives positionally in a serialised list on the parent key (`a:4:{i:0;s:12:"block_header";…}`) while the row's values live in `layouts_2_blocks_0_title`, with no layout name anywhere in the key. Correlating them requires unserialising in PHP. Not expressible, and it must not be approximated — an approximation that ignores the layout matches the wrong rows while preview and run agree.

**Cross-field predicates inside one repeater row.** `post_meta_rows` matches when *some* row satisfies the test; two tests can match two different rows. The grouping key is inside the meta_key string, not in a column, so no descriptor reaches it.

**WPML's sibling questions** — "has no French translation", "translation needs update". Siblings are found by a shared `trid`, so the question is two-hop, and `icl_translation_status` is keyed by `translation_id` rather than `element_id`, so "needs update" is a third hop. Neither fits one table. The WPML module ships two honest fields: `wpml:language` (`related_rows` over `icl_translations`, keyed by a UNIQUE `(element_type, element_id)` — the safest membership shape available) and `wpml:duplicate` (plain postmeta `_icl_lang_duplicate_of`, and arguably the right default exclusion for any bulk edit, since editing a duplicate is editing a copy). A sibling/grouped shape may be added in a later minor release *after* it EXPLAINs clean on the 18.5k catalogue — the vocabulary grows by adding a named constructor, which breaks nobody — and if the measurement goes badly the shape is cut rather than shipped slow.

**Language as a whole-set constraint.** Even the language field that *does* fit is unsafe as an ordinary condition under an OR relation: "German products OR anything under €10" silently readmits every language, and no per-field clause can defend against that. The correct home is the `Requirement` seam, which is unconditionally ANDed. Until that seam exists, `wpml:language` ships as a condition and the module documents that it must not be OR-ed — which is safe today only because the shipped UI emits AND, and is a reason not to ship a relation control before the requirement seam.

**Comparisons between two columns, computed expressions** (`CAST(… AS DATE)`, `CHAR_LENGTH`, `JSON_EXTRACT`), **aggregates over rows**, and **disjunctions among a storage's constants** (`element_type IN ('post_product','post_product_variation')` — constants are ANDed; declare two fields).

**Legacy ACF date formats.** A field whose `save_format` is `d/m/Y` cannot be ordered lexicographically, and a range over it returns a plausible wrong set with no error. The provider must throw `Filter_Field_Unavailable` rather than declare `DATE_TEXT`.

**ACF behind `acf/load_value` / `acf/update_value` filters, or the Custom Database Tables addon.** The stored bytes are not the value ACF returns; no clause over postmeta can detect this, and neither can the engine. The module documents the limitation and offers an opt-out list.

**Hierarchical descendant expansion is not automatic.** `product_brand` is hierarchical, but `product_cat` does not expand descendants today and consistency wins; a module that wants it passes a `Value_Map`.

**What a developer who needs one of these is told**, and this must be the most prominent page in the manual rather than an appendix: *materialise the answer into a queryable column*. Hook the save (`acf/save_post`, `save_post_product`, your own write path), compute the derived value in PHP where it is easy and correct, write it to a plain meta key, and declare `Field_Storage::post_meta( '_yourplugin_derived', Value_Kind::TEXT )`. That is a complete answer, and it is a better one than a raw clause would have been, because it is indexed and fast. An author who cannot find that page concludes the API is broken rather than that their question is out of scope.

---

## 7. THE ORDER OF WORK

**PR 1 — Engine hardening, no new API.** Parenthesise each condition fragment in `build_where()` (:205) and each requirement fragment in `select()` (:154). Add the placeholder/argument parity assertion before `prepare()` (:166). Move `$this->service->create(...)` inside `Schedule_Runner::fire()`'s existing `try` (:119 → :127). Add the `paused_reason` column and migration to `Schedules`, and notify on a pause. All behaviour-preserving except the schedule fixes, which are strict improvements. Green on the existing 284 tests plus new cases for the parity assertion.

**PR 2 — Refuse the unknown field.** Replace `clause_for()`'s `return array( '', array() )` at :273-274 with `Filter_Field_Unavailable`. Add `Filter_Providers` as an empty registry, the nullable `Query_Engine` constructor parameter, `Operation_Service::assert_supported()` at the three boundaries, and error handling in `Query_Controller::query()`/`other_scope()` (which have no try/catch at all today, and where `Operator::from()` already throws an uncaught `\ValueError` — a 500, not a 400 — for a bad operator token). Behaviour change with no public API: this is the highest-value single PR in the milestone and it stands alone.

**PR 3 — The vocabulary and the compiler**, engine-internal, published to nobody. `Field_Storage`, the enums, `Value_Map`, `Clause`, `Storage_Compiler`, and a `Core_Filter_Fields` test-only provider that expresses `price`, `category`, `meta:*` and `attribute:*` through the vocabulary purely to prove the shapes carry the hard cases — **not** wired into `clause_for()`, which keeps its own builders. New test category: unit tests asserting emitted SQL text, which this repository does not have (284 tests, zero SQL-string assertions).

**PR 4 — The measurement harness. THIS IS THE RISKY ONE.** Rebuild `docker/mysql8/generate-explain-sql.php`: drive its case list from registered providers instead of the hardcoded `$cases` array (:92-185), remove the `ReflectionMethod` hack on the private `select()` (:37-60), **pass requirements** — it never has, so the one-to-three positive semi-joins `Write_Rules` contributes at preview and freeze have never been EXPLAINed on the real catalogue, and that is the actual headroom a provider clause competes for — and convert the README's eyeball checklist into assertions (`type != ALL` on `l`/`p`, `key != NULL`, no newly-appearing `Using temporary`/`filesort`, row estimate within N× of baseline). Then measure every shape the compiler emits that the engine does not already ship: the postmeta derived-table join, `SERIALIZED_LIST`, `post_meta_rows`, `related_rows`, and the OR-relation semi-join stack. Risky because it can invalidate a shape — and if it does, that shape is cut before the contract is public rather than after.

**PR 5 — The descriptor endpoint and the UI.** `/fields/filterable` serving `Filter_Providers::all_fields()`, plus the generic module-fields section appended below the eight existing controls, plus `Query_Controller::meta_columns()` reading `column_label` from the descriptor. Largest, least contained, and the one to start early in parallel.

**PR 6 — The signature.** `Query_Engine::signature()`, returned by `preview()`, verified in `queue()` (409) and in `Schedule_Runner::fire()` (pause with reason).

**PR 7 — Publish. THE POINT OF NO RETURN.** The `catalogops_filter_providers` filter, the documentation (three complete copyable providers first, then the storage decision tree, then the `Value_Kind` table, then the one page on what the vocabulary cannot express *with the materialise-on-save workaround*, then the purity contract), and `wp catalogops verify-provider <field-key>` shipped under `src/` — `bin/build.php`'s `$include` allowlist excludes `tests/` from every release, and the integration bootstrap `markTestSkipped`s everything and exits zero without `WP_TESTS_DIR`, so a kit that lives in `tests/` is both unreachable and capable of reporting green having asserted nothing. The command must run inside the developer's own WordPress, must refuse to report success if it could not execute SQL, and must assert: byte-identical SQL across two builds; correct under AND and under OR (the shipped UI emits only AND, so no manual test reaches that path); correct in both scopes; the same field used twice in one filter (alias collision); an object that has the value, one with a different value, and one with **no value at all** (the exclusion case); and `preview count == frozen target_count`, which is the house assertion `ApplicabilityTest` already uses. After this PR the interfaces, enums and named constructors are frozen.

**PR 8 — Brands.** `product_brand`, `Field_Storage::taxonomy()`, ~15 lines. It exercises the whole path — join under AND, semi-join under OR, `NOT EXISTS` under exclusion, PARENT anchor, tt_id pre-resolution, results-table column — and needs none of the requirement work.

**PR 9 — ACF**, plus the `Adjust`-on-an-uncovered-field refusal.

**PR 10 — WPML** (language + duplicate), plus the `Requirement` join channel if the sibling shape survived PR 4.

---

## 8. WHAT COULD STILL GO WRONG

**`Object_Anchor` is a judgement the engine cannot verify.** SELF and PARENT both resolve to `l.product_id` under the product scope, so a wrong anchor passes every test the author can write and returns a plausible, non-empty, wrong set the moment a user flips the Products/Variations toggle. The same is true of a dishonest `scopes` declaration, where the engine will then confidently offer the field. These are claims about where data lives, not about SQL, and no amount of construction closes them. They are the smallest residual on the table and they are real.

**`Value_Kind` is the same kind of claim.** `NUMERIC_TEXT` over free text casts every non-numeric value to 0, so `> 5` silently excludes everything and `< 5` silently includes it — a trap the engine inherits from today's `meta_value_test()` and does not fix by naming. `DATE_TEXT` over a non-big-endian format returns a plausible wrong range. `TEXT` over a serialised column matches nothing and looks like an over-narrow filter.

**Purity is narrowed, not closed.** `storage_for()` and `Value_Map::map()` are still provider PHP that can read an option, the clock, or a remote service. The signature converts a violation into a visible 409 or a paused schedule, which is more than any candidate design offered — but it has a false-positive mode: a legitimately new sub-brand changes a `Value_Map`'s output, changes the signature, and pauses a schedule that was working correctly. The message must say "the set this schedule targets has changed since you saved it — review and resume", and resuming must be one click, or the feature becomes an irritant people disable.

**`SERIALIZED_LIST` is unmeasured** at the time of writing, and it is an unindexed LIKE over LONGTEXT across a whole meta_key partition, run 2+N+W times per preview. Giving it an enum name makes it look as blessed as `INTEGER`. If PR 4 goes badly it must be gated behind an explicit opt-in or cut, and ACF's multi-value fields lose inclusive filtering.

**The closed vocabulary will block someone.** The first agency that needs a JSON path, a date arithmetic, a two-column comparison or a cross-row correlation files an issue and waits for a release. The materialise-on-save recipe covers most of them and is genuinely better; it does not cover a store that cannot modify its save path. Watch for the failure this creates: an author who resolves candidate ids in PHP and passes a large list. The 1000-operand cap makes that fail loudly instead of silently, but it fails.

**The React work can sink the milestone independently of everything above.** Every invariant here is enforced on a field no user can reach until PR 5 lands, and PR 5 is the least contained work in the plan.

**First-claim-wins registration is guarded for core keys and not between modules.** Two providers claiming `brand` resolve to whichever registered first, with no error. A registration-time collision check is worth adding before PR 7 freezes the surface.

**A module deactivated mid-operation still wedges the run** — `pending_chunk()` returns the same unroutable rows forever while the heartbeat stays healthy and the write lock is held. Untouched by this design, and it will be the first support ticket the first module generates if a customer deactivates ACF while an operation is running.

**The conformance kit cannot reach the regime the whole contract exists to avoid.** The largest fixture in the repository is 201 products at roughly 15ms each; 10,000 is 150 seconds of setup. Correctness is testable by anyone; the plan flip is testable only in `docker/mysql8` against a real catalogue, which is the plugin author's machine and nobody else's. That asymmetry is permanent, and it is the reason the provider is not allowed to choose a plan.