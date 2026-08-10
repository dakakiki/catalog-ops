<?php
/**
 * Lifecycle state of a schedule.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * A schedule's own lifecycle, distinct from the operations it spawns. `active`
 * schedules are eligible to fire; `paused` are kept but skipped by the
 * supervisor; `completed` is the terminal state a one-shot reaches after its
 * single run.
 */
enum Schedule_Status: string {
	case ACTIVE    = 'active';
	case PAUSED    = 'paused';
	case COMPLETED = 'completed';
}
