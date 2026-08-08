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
}
