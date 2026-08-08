<?php
/**
 * The kind of storage a field lives in.
 *
 * @package CatalogOps\Operations\Fields
 */

namespace CatalogOps\Operations\Fields;

/**
 * The field_type column of the changes table (CONTEXT §3). It records where a
 * changed value is stored so that undo (M3) knows how to write it back without
 * re-deriving the routing from the field key.
 */
enum Field_Type: string {
	case POST_FIELD = 'post_field';
	case META       = 'meta';
	case TERM       = 'term';
	case ATTRIBUTE  = 'attribute';
}
