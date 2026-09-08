<?php
/**
 * Which storage shape a Field_Storage describes.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Internal to {@see Storage_Compiler}'s dispatch. It is public only because
 * {@see Field_Storage} is a readonly value object; providers never name a case.
 */
enum Storage_Kind: string {
	case LOOKUP_COLUMN  = 'lookup_column';
	case POST_META      = 'post_meta';
	case POST_META_ROWS = 'post_meta_rows';
	case TAXONOMY       = 'taxonomy';
	case RELATED_ROWS   = 'related_rows';
}
