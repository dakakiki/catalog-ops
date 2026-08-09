<?php
/**
 * How one object's pending change rows are turned into staged writes.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use WC_Product;

/**
 * The seam that keeps CONTEXT §2's "one pipeline" promise: a normal edit and an
 * undo differ only in how each object's new value is decided, so that decision is
 * a plan the runner is handed, not a branch baked into it.
 *
 * {@see Apply_Plan} derives each new value from the operation's actions;
 * {@see Revert_Plan} derives it from the parent operation's recorded deltas,
 * checking drift and applying the conflict policy first. Both stage their writes
 * onto the loaded product and report the outcome; the runner does the shared work
 * around them — load once, save once, claim rows, adapt the batch, heartbeat.
 */
interface Chunk_Plan {

	/**
	 * Evaluate one object's pending change rows against a loaded product: stage
	 * the writes that should happen and return the per-row outcome. Must not save
	 * — the runner saves once after evaluation so a safe-mode object refreshes the
	 * lookup and transients a single time (CONTEXT §3).
	 *
	 * @param WC_Product $product The loaded product.
	 * @param Change[]   $rows    The object's pending change rows.
	 */
	public function evaluate( WC_Product $product, array $rows ): Chunk_Outcome;
}
