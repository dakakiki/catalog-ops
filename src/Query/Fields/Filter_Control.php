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
