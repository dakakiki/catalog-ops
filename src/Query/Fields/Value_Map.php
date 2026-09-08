<?php
/**
 * Turns the values a user picked into the values the storage actually holds.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Resolution that runs **before** any SELECT is built, once per condition per
 * request. It is not an optimisation, it is the mechanism that keeps a membership
 * subquery down to a single table.
 *
 * The term_relationships table is keyed by term_taxonomy_id, so asking it about term ids
 * means joining term_taxonomy inside the subquery — and that two-table subquery is
 * what mis-plans. Measured on an 18.5k-product catalogue: three categories answered
 * in 1s, a fourth flipped the plan, `p` lost its primary-key path for a range scan
 * with a block-nested-loop join, and the same query took **four minutes**. DISTINCT,
 * GROUP BY and a wrapped derived table all still timed out. Resolving term ids to
 * term_taxonomy_ids first — one small indexed lookup — brought the same case to
 * 992ms and kept it flat from there (see
 * {@see \CatalogOps\Query\Query_Engine::term_taxonomy_ids()}).
 *
 * An implementation must be a pure function of its input and durable database
 * state. Preview counts in one HTTP request and {@see
 * \CatalogOps\Operations\Operation_Service::queue()} freezes in another, sometimes
 * days later on a cron tick with no user present; anything read here that can change
 * between the two silently makes the run a different size than the preview promised.
 * {@see Storage_Compiler} memoises the result for the life of the request, so a
 * single preview — which renders its filter 2+N+W times — pays for it once.
 */
interface Value_Map {

	/**
	 * Map user-supplied operands onto stored ones.
	 *
	 * Returning an empty list is a real answer, not a failure: it means nothing in
	 * the catalogue can hold this value. The compiler turns that into match-nothing
	 * for a positive test and match-everything for a negative one, which is what
	 * "exclude a brand that no longer exists" has to mean.
	 *
	 * @param list<int|float|string> $values Operands, already cast to the field's
	 *                                       value kind and capped in length.
	 * @return list<int|float|string> Stored operands; may be empty.
	 */
	public function map( array $values ): array;
}
