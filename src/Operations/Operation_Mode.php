<?php
/**
 * Write strategy for an operation.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * The mode column of the operations table (CONTEXT §3).
 *
 * `safe` writes through WooCommerce CRUD: every hook fires and the product meta
 * lookup and transients stay correct, at ~40–80 objects/s. `fast` is the direct
 * `$wpdb` path — declared here for completeness but **not implemented until
 * v1.1** (CONTEXT §3, closed decision #4); v1.0 always runs `safe`.
 */
enum Operation_Mode: string {
	case SAFE = 'safe';
	case FAST = 'fast';
}
