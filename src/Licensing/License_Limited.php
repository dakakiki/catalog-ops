<?php
/**
 * Raised when the active plan does not permit an attempted action.
 *
 * @package CatalogOps\Licensing
 */

namespace CatalogOps\Licensing;

use RuntimeException;

/**
 * Signals that a free-tier limit was hit — too many objects for one operation, or
 * a paid-only feature (undo, formulas, scheduling) invoked without a paid plan
 * (CONTEXT §5). The REST layer maps this to HTTP 402 (Payment Required) so the UI
 * can surface the upgrade prompt rather than a generic error.
 */
final class License_Limited extends RuntimeException {}
