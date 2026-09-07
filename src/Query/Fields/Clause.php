<?php
/**
 * One compiled condition: its WHERE fragment, its join, and their arguments —
 * kept physically apart.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

/**
 * Engine-internal, and never handed to a provider.
 *
 * It exists to replace a four-slot array whose slots could be transposed. The
 * ordering it protects is not cosmetic: {@see \CatalogOps\Query\Query_Engine} binds
 * placeholders by textual position through one `vsprintf`, and the FROM clause
 * precedes the WHERE — so a join's values must reach the argument vector *before*
 * any WHERE value, and a join argument put in the WHERE list produces a statement
 * that runs, succeeds, and answers a different question.
 *
 * Because the two lists are separate properties and {@see Storage_Compiler} is the
 * only thing that fills either, that mistake has nowhere to happen.
 */
final class Clause {

	/**
	 * Build a clause.
	 *
	 * @param string  $where      Boolean fragment for the WHERE, or '' when the
	 *                            clause is expressed entirely as a join.
	 * @param mixed[] $where_args Values bound inside `$where`, in order.
	 * @param string  $join       JOIN clause, or '' when there is none.
	 * @param mixed[] $join_args  Values bound inside `$join`, in order.
	 */
	public function __construct(
		public readonly string $where = '',
		public readonly array $where_args = array(),
		public readonly string $join = '',
		public readonly array $join_args = array(),
	) {}

	/**
	 * A clause that is only a WHERE fragment.
	 *
	 * @param string  $where The fragment.
	 * @param mixed[] $args  Its values.
	 */
	public static function where( string $where, array $args = array() ): self {
		return new self( $where, $args );
	}

	/**
	 * A clause that is only a join — the shape positive membership takes under AND.
	 *
	 * @param string  $join The JOIN clause.
	 * @param mixed[] $args Its values.
	 */
	public static function join( string $join, array $args = array() ): self {
		return new self( '', array(), $join, $args );
	}
}
