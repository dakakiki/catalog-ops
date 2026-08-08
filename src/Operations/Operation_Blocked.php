<?php
/**
 * Raised when an operation cannot be queued because another is writing.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use RuntimeException;

/**
 * Signals that the single-writer lock (CONTEXT §3) is held by another operation.
 * The REST layer maps this to HTTP 409 so the UI can tell the user to wait for
 * the running operation to finish.
 */
final class Operation_Blocked extends RuntimeException {}
