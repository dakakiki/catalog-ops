<?php
/**
 * Comparison operators a filter condition can use.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

/**
 * The set of operators the query engine understands. The backing string is the
 * stable token used in filter JSON (persisted in the operations table).
 */
enum Operator: string {
	case EQUALS           = '=';
	case NOT_EQUALS       = '!=';
	case GREATER_THAN     = '>';
	case GREATER_OR_EQUAL = '>=';
	case LESS_THAN        = '<';
	case LESS_OR_EQUAL    = '<=';
	case BETWEEN          = 'between';
	case IN               = 'in';
	case NOT_IN           = 'not_in';
	case CONTAINS         = 'contains';
	case EXISTS           = 'exists';
	case NOT_EXISTS       = 'not_exists';

	/**
	 * Whether this operator asks the negative form of its question.
	 *
	 * One answer, in one place, because the clause builders used to each carry
	 * their own list and the lists drifted. `taxonomy_clause()` and
	 * `variation_attribute_clause()` both negated `NOT_IN` and `NOT_EXISTS` and
	 * neither remembered `NOT_EQUALS`, so `category != 5` fell through to the
	 * *positive* membership branch and returned exactly the products it had been
	 * asked to exclude — while `meta_clause()`, which kept a third copy of the
	 * same knowledge, got it right. Nothing catches that but a reader noticing,
	 * and four copies of a rule is four chances to be the one that is wrong.
	 */
	public function is_negative(): bool {
		return self::NOT_EQUALS === $this
			|| self::NOT_IN === $this
			|| self::NOT_EXISTS === $this;
	}

	/**
	 * The positive form of this operator, or itself when it is already positive.
	 *
	 * Negation belongs outside the subquery, never pushed in beside the value: a
	 * meta test asked as `IN ( … meta_value != x )` answers "has some *other*
	 * value for this key", which silently drops every object with no such row at
	 * all — so an exclusion loses the unbranded products, which are definitively
	 * not that brand. Building the value test from the positive twin and negating
	 * the keyword outside is what keeps them.
	 */
	public function positive_twin(): self {
		return match ( $this ) {
			self::NOT_EQUALS => self::EQUALS,
			self::NOT_IN     => self::IN,
			self::NOT_EXISTS => self::EXISTS,
			default          => $this,
		};
	}
}
