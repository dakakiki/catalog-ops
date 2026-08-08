<?php
/**
 * Per-change application state.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The status column of the changes table (CONTEXT §3), an int-backed enum so the
 * tinyint values are named. A change is seeded `PENDING`; the runner flips it to
 * `APPLIED`, `FAILED`, or `SKIPPED`. The flip is guarded on the current value
 * being `PENDING`, which is what makes a re-run of a partial chunk idempotent.
 */
enum Change_Status: int {
	case PENDING = 0;
	case APPLIED = 1;
	case FAILED  = 2;
	case SKIPPED = 3;
}
