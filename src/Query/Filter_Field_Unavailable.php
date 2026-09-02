<?php
/**
 * Raised when a filter asks a question the query engine cannot answer.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

use InvalidArgumentException;

/**
 * The read side's twin of the write side's refusal in
 * {@see \CatalogOps\Operations\Operation_Service::assert_fields_supported()}, and
 * the reason the read path can now fail loudly: the write path always has, and the
 * asymmetry between them was the whole safety problem.
 *
 * {@see Query_Engine::clause_for()} used to end in `return array( '', array() )`
 * for a field it did not recognise, {@see Query_Engine::build_where()} skipped the
 * empty fragment, and a filter reading "brand Acme and in stock" quietly became
 * "in stock" — a strictly larger set, previewed and applied identically, so every
 * check the plugin makes agreed it had done the right thing. A -10% price edit
 * landed on the whole shop, and on a schedule with nobody watching.
 *
 * Extending InvalidArgumentException is load-bearing rather than tidy. The REST
 * controllers' existing `catch ( InvalidArgumentException )` blocks map it to HTTP
 * 400 with no change to any of them, exactly as {@see
 * \CatalogOps\Operations\Formula\Formula_Error} already travels; and a throw from
 * `queue()` lands inside {@see \CatalogOps\Operations\Schedule_Runner::fire()}'s
 * catch, which pauses that schedule rather than firing it against a wider set.
 *
 * A licence refusal is deliberately *not* this exception — it stays
 * {@see \CatalogOps\Licensing\License_Limited} so the client can still answer 402
 * and offer the upgrade rather than a dead end.
 */
final class Filter_Field_Unavailable extends InvalidArgumentException {}
