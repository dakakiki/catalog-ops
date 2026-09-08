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
	 * ACF's modern date fields store exactly those two formats, verified against
	 * ACF Pro 6.8.9, but its legacy `save_format` setting (kept for ACF < 5.0.0)
	 * offers `d/m/Y`, which a lexicographic range answers plausibly and wrongly —
	 * no error, no empty result, just the wrong products. A provider that cannot
	 * prove its format is big-endian and zero-padded must throw
	 * {@see \CatalogOps\Query\Filter_Field_Unavailable} rather than declare this
	 * kind.
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
	 * bare, which the quoted-token pattern never matches — those objects vanish
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
	 * The wpdb placeholder a value of this kind binds to.
	 *
	 * Text kinds bind as `%s` even when they hold digits: a postmeta number is a
	 * string in the column, and the comparison casts the *column*, not the operand.
	 */
	public function placeholder(): string {
		return match ( $this ) {
			self::INTEGER => '%d',
			self::DECIMAL => '%f',
			default       => '%s',
		};
	}

	/**
	 * Cast one operand to the PHP type this kind binds.
	 *
	 * The wpdb layer replaces a non-scalar argument with '' after a `_doing_it_wrong` nobody
	 * sees, so a nested array arriving from the schemaless REST body would
	 * otherwise quietly become `= ''` — a condition that matches nothing and looks
	 * like an over-narrow filter.
	 *
	 * @param mixed $value The operand as it arrived.
	 * @return int|float|string The operand as it will bind.
	 */
	public function cast( mixed $value ): int|float|string {
		return match ( $this ) {
			self::INTEGER => (int) $value,
			self::DECIMAL => (float) $value,
			default       => (string) $value,
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
