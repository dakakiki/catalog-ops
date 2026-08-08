<?php
/**
 * Where an operation originated.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The source column of the operations table (CONTEXT §3): which entry point
 * created the operation. `undo` marks the inverse operation an M3 revert enqueues
 * back through the same pipeline.
 */
enum Operation_Source: string {
	case UI       = 'ui';
	case CLI      = 'cli';
	case SCHEDULE = 'schedule';
	case UNDO     = 'undo';
}
