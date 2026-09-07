<?php
/**
 * Turns a storage descriptor, an operator and an operand into plan-safe SQL.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * The whole plan authority, and the reason {@see Field_Storage} can be handed to a
 * stranger. A provider names where a value lives; every decision that can be
 * silently wrong is made here:
 *
 *   - whether positive membership becomes an INNER JOIN over a DISTINCT derived
 *     table or an uncorrelated `IN (SELECT …)`;
 *   - whether a negation becomes `NOT IN` or `NOT EXISTS`;
 *   - where the negation sits — outside the subquery, always;
 *   - which placeholder each value binds to, and which argument list it goes in;
 *   - which object column the subquery matches on.
 *
 * Every one of those is a way to return the *correct answer* on any catalogue a
 * provider author can build and the wrong one on a real shop.
 *
 * **The negative row is deliberately not uniform**, and that is a correction to the
 * obvious design. Postmeta and foreign tables take the uncorrelated `NOT IN` —
 * measured on the 18.5k catalogue at 2.5s against 13.6s for the correlated
 * `NOT EXISTS` on a lone brand exclusion, converging to 568ms vs 594ms once any
 * positive condition narrows the candidates. Taxonomy keeps `NOT EXISTS`, because
 * that is the measured choice {@see \CatalogOps\Query\Query_Engine::taxonomy_clause()}
 * already made and it is NULL-safe by construction. `RELATED_ROWS` adds
 * `IS NOT NULL` to its object column because a third-party column may be nullable
 * where `post_id` and `object_id` are not, and an uncorrelated `NOT IN` over a
 * nullable column returns UNKNOWN for every row and matches nothing — which a user
 * reads as "my filter is too narrow", not as a bug. That is not defensive: WPML's
 * `icl_translations.element_id` is `BIGINT NULL`, verified in its own DDL.
 */
final class Storage_Compiler {

	/**
	 * The cast a number-stored-as-text is compared through.
	 */
	private const NUMERIC_CAST = 'DECIMAL(20,4)';

	/**
	 * Mapped operands, keyed by field, operator, operand and scope.
	 *
	 * A single preview renders its filter 2+N+W times — once for the count, once per
	 * requirement, once per warning, once for the sample — and freeze_edit() resolves
	 * again. Without this a {@see Value_Map} that reads the database runs every one
	 * of those times, and worse, the preview's `applicable - remaining` differencing
	 * is only coherent if every render of one condition sees identical operands.
	 *
	 * @var array<string, list<int|float|string>>
	 */
	private array $mapped = array();

	/**
	 * Build the compiler.
	 *
	 * @param wpdb $wpdb Database handle, for table names and esc_like().
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * Compile one condition.
	 *
	 * @param Filter_Field  $field     The descriptor, for its key in error messages.
	 * @param Field_Storage $storage   Where the value lives.
	 * @param Operator      $operator  The comparison asked for.
	 * @param mixed         $value     The operand, as it arrived.
	 * @param Query_Scope   $scope     The object type being queried.
	 * @param int|null      $join_slot Alias number, or null when the clause must stay
	 *                                 in the WHERE (an OR filter).
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	public function compile(
		Filter_Field $field,
		Field_Storage $storage,
		Operator $operator,
		mixed $value,
		Query_Scope $scope,
		?int $join_slot = null
	): Clause {
		// 1. Polarity. The value test is built only from the positive twin, so
		// pushing a negation inside a subquery is not expressible anywhere in this
		// class. Asked as `IN ( … value != x )` a membership test answers "has some
		// *other* value", which drops every object with no row at all — so an
		// exclusion loses exactly the objects that are definitively not the thing
		// being excluded.
		$negative = $operator->is_negative();
		$positive = $operator->positive_twin();

		$presence = Operator::EXISTS === $positive;

		// 2 and 3. Operands, normalised then mapped.
		$operands = $presence
			? array()
			: $this->operands( $field, $storage, $positive, $value );

		if ( ! $presence && null !== $storage->value_map ) {
			$operands = $this->map( $field, $storage, $positive, $value, $scope, $operands );
		}

		// 4. An empty operand set is a real answer, not a failure: nothing in the
		// catalogue can hold this value. "Exclude a brand that was deleted" must keep
		// every product.
		if ( ! $presence && array() === $operands && $this->takes_a_list( $positive ) ) {
			return Clause::where( $negative ? '1 = 1' : '1 = 0' );
		}

		// 5. Object column.
		$object_column = ( Object_Anchor::PARENT === $storage->anchor && $scope->is_variation() )
			? 'p.post_parent'
			: 'l.product_id';

		// 6. Shape.
		return match ( $storage->kind ) {
			Storage_Kind::LOOKUP_COLUMN => $this->lookup_clause( $field, $storage, $positive, $negative, $operands ),
			Storage_Kind::TAXONOMY      => $this->taxonomy_clause( $storage, $negative, $operands, $object_column, $join_slot ),
			Storage_Kind::POST_META,
			Storage_Kind::POST_META_ROWS => $this->post_meta_clause( $field, $storage, $positive, $negative, $operands, $object_column, $join_slot ),
			Storage_Kind::RELATED_ROWS  => $this->related_clause( $field, $storage, $positive, $negative, $operands, $object_column, $join_slot ),
		};
	}

	/**
	 * Normalise the operands: cast each to the storage's value kind, refuse
	 * non-scalars, and cap the list.
	 *
	 * @param Filter_Field  $field    The descriptor, to name the field in a refusal.
	 * @param Field_Storage $storage  Where the value lives.
	 * @param Operator      $operator The positive operator.
	 * @param mixed         $value    The operand, as it arrived.
	 * @return list<int|float|string>
	 *
	 * @throws Filter_Field_Unavailable When an operand is not a single value, the
	 *                                  list is too long, or a range is one-ended.
	 */
	private function operands( Filter_Field $field, Field_Storage $storage, Operator $operator, mixed $value ): array {
		$raw = $this->takes_a_list( $operator ) || Operator::BETWEEN === $operator
			? array_values( (array) $value )
			: array( $value );

		if ( count( $raw ) > Field_Storage::MAX_OPERANDS ) {
			$this->unavailable(
				sprintf(
					'The filter on "%1$s" carries more than %2$d values, which is more than one condition can ask.',
					$field->key,
					Field_Storage::MAX_OPERANDS
				),
				$field->key
			);
		}

		$cast = $this->numeric_comparison( $storage->value_kind, $operator )
			? Value_Kind::DECIMAL
			: $storage->value_kind;

		$operands = array();

		foreach ( $raw as $one ) {
			if ( ! is_scalar( $one ) ) {
				$this->unavailable(
					sprintf( 'The filter on "%s" was given a value that is not a single value.', $field->key ),
					$field->key
				);
			}

			$operands[] = $cast->cast( $one );
		}

		if ( Operator::BETWEEN === $operator && count( $operands ) < 2 ) {
			$this->unavailable(
				sprintf( 'A "between" filter on "%s" needs both a low and a high value.', $field->key ),
				$field->key
			);
		}

		return $operands;
	}

	/**
	 * Run the storage's value map, once per (field, operator, operand, scope) for the
	 * life of the request.
	 *
	 * @param Filter_Field           $field    The descriptor.
	 * @param Field_Storage          $storage  Where the value lives.
	 * @param Operator               $operator The positive operator.
	 * @param mixed                  $value    The raw operand, for the memo key.
	 * @param Query_Scope            $scope    The scope, for the memo key.
	 * @param list<int|float|string> $operands The normalised operands.
	 * @return list<int|float|string>
	 */
	private function map(
		Filter_Field $field,
		Field_Storage $storage,
		Operator $operator,
		mixed $value,
		Query_Scope $scope,
		array $operands
	): array {
		$memo = md5( $field->key . '|' . $operator->value . '|' . (string) wp_json_encode( $value ) . '|' . $scope->value );

		if ( ! isset( $this->mapped[ $memo ] ) ) {
			$this->mapped[ $memo ] = array_values( $storage->value_map->map( $operands ) );
		}

		return $this->mapped[ $memo ];
	}

	/**
	 * A lookup column: a plain predicate on a column already in the statement.
	 *
	 * The only kind where a negative operator is a plain comparison rather than an
	 * anti-join, because a column has exactly one value per row — and the only one
	 * that needs a NULL guard, because `col != 5` silently excludes every row where
	 * the column is NULL. `stock_quantity` is NULL on every product that does not
	 * manage stock, which is most of a real catalogue.
	 *
	 * @param Filter_Field           $field    The descriptor.
	 * @param Field_Storage          $storage  Where the value lives.
	 * @param Operator               $positive The positive operator.
	 * @param bool                   $negative Whether the original operator was negative.
	 * @param list<int|float|string> $operands The mapped operands.
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	private function lookup_clause(
		Filter_Field $field,
		Field_Storage $storage,
		Operator $positive,
		bool $negative,
		array $operands
	): Clause {
		$column = 'l.' . $storage->column->value;

		if ( Operator::EXISTS === $positive ) {
			return Clause::where( $negative ? "{$column} IS NULL" : "{$column} IS NOT NULL" );
		}

		list( $test, $args ) = $this->comparison( $field, $column, $storage->value_kind, $positive, $operands );

		if ( ! $negative ) {
			return Clause::where( $test, $args );
		}

		// A NULL column is not "different from 5"; it is unknown. Without the guard
		// the exclusion quietly drops every row that has no value at all, which is
		// the opposite of what excluding a value means.
		return Clause::where(
			$storage->column->is_nullable()
				? "( {$column} IS NULL OR NOT ( {$test} ) )"
				: "NOT ( {$test} )",
			$args
		);
	}

	/**
	 * Membership in a taxonomy's terms.
	 *
	 * Positive membership is a join over a DISTINCT derived table under AND, and the
	 * semi-join under OR. Exclusion stays a correlated `NOT EXISTS`: that is the
	 * measured choice for this table and it is NULL-safe by construction.
	 *
	 * The operands are term_taxonomy_ids by the time they arrive — the engine's
	 * {@see Value_Map} resolves them — so the subquery reads term_relationships
	 * alone. That single-table property is what {@see Field_Storage}'s no-dot rule
	 * exists to protect.
	 *
	 * @param Field_Storage          $storage   Where the value lives.
	 * @param bool                   $negative  Whether the operator was negative.
	 * @param list<int|float|string> $operands The mapped tt_ids.
	 * @param string                 $object_column    The object column.
	 * @param int|null               $join_slot Alias number, or null under OR.
	 */
	private function taxonomy_clause(
		Field_Storage $storage,
		bool $negative,
		array $operands,
		string $object_column,
		?int $join_slot
	): Clause {
		$relationships = $this->wpdb->term_relationships;
		$placeholders  = $this->placeholders( count( $operands ), '%d' );
		$test          = array() === $operands ? '' : " AND tr.term_taxonomy_id IN ( {$placeholders} )";

		if ( $negative ) {
			return Clause::where(
				"NOT EXISTS (
				SELECT 1 FROM {$relationships} tr
				WHERE tr.object_id = {$object_column}{$test}
			)",
				$operands
			);
		}

		if ( null === $join_slot ) {
			return Clause::where(
				"{$object_column} IN (
				SELECT tr.object_id FROM {$relationships} tr
				WHERE 1 = 1{$test}
			)",
				$operands
			);
		}

		$alias = $this->alias( $join_slot );

		return Clause::join(
			"INNER JOIN (
				SELECT DISTINCT tr.object_id FROM {$relationships} tr
				WHERE 1 = 1{$test}
			) {$alias} ON {$alias}.object_id = {$object_column}",
			$operands
		);
	}

	/**
	 * A post-meta key, or a family of them sharing a literal prefix and suffix.
	 *
	 * @param Filter_Field           $field     The descriptor.
	 * @param Field_Storage          $storage   Where the value lives.
	 * @param Operator               $positive  The positive operator.
	 * @param bool                   $negative  Whether the operator was negative.
	 * @param list<int|float|string> $operands The mapped operands.
	 * @param string                 $object_column    The object column.
	 * @param int|null               $join_slot Alias number, or null under OR.
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	private function post_meta_clause(
		Filter_Field $field,
		Field_Storage $storage,
		Operator $positive,
		bool $negative,
		array $operands,
		string $object_column,
		?int $join_slot
	): Clause {
		$postmeta = $this->wpdb->postmeta;

		if ( Storage_Kind::POST_META_ROWS === $storage->kind ) {
			// Assembled from two literals the provider wrote in its own source, then
			// bound as an argument — the pattern is never interpolated. The prefix is
			// required non-empty so the meta_key(191) index can still range-scan.
			$key_test = 'pm.meta_key LIKE %s';
			$key_args = array(
				$this->wpdb->esc_like( $storage->key_prefix ) . '%' . $this->wpdb->esc_like( $storage->key_suffix ),
			);
		} else {
			$key_test = 'pm.meta_key = %s';
			$key_args = array( $storage->key_prefix );
		}

		list( $value_test, $value_args ) = Operator::EXISTS === $positive
			? array( '', array() )
			: $this->comparison( $field, 'pm.meta_value', $storage->value_kind, $positive, $operands );

		$where = $key_test . ( '' === $value_test ? '' : ' AND ' . $value_test );
		$args  = array( ...$key_args, ...$value_args );

		list( $where, $args ) = $this->with_constants( $where, $args, $storage, 'pm' );

		if ( $negative ) {
			// Uncorrelated NOT IN rather than a correlated NOT EXISTS: measured at
			// 2.5s against 13.6s on a lone exclusion over the 18.5k catalogue.
			return Clause::where(
				"{$object_column} NOT IN (
				SELECT pm.post_id FROM {$postmeta} pm WHERE {$where}
			)",
				$args
			);
		}

		if ( null === $join_slot ) {
			return Clause::where(
				"{$object_column} IN (
				SELECT pm.post_id FROM {$postmeta} pm WHERE {$where}
			)",
				$args
			);
		}

		$alias = $this->alias( $join_slot );

		return Clause::join(
			"INNER JOIN (
				SELECT DISTINCT pm.post_id FROM {$postmeta} pm WHERE {$where}
			) {$alias} ON {$alias}.post_id = {$object_column}",
			$args
		);
	}

	/**
	 * Rows in a table carrying an object-id column.
	 *
	 * @param Filter_Field           $field     The descriptor.
	 * @param Field_Storage          $storage   Where the value lives.
	 * @param Operator               $positive  The positive operator.
	 * @param bool                   $negative  Whether the operator was negative.
	 * @param list<int|float|string> $operands The mapped operands.
	 * @param string                 $object_column    The object column.
	 * @param int|null               $join_slot Alias number, or null under OR.
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	private function related_clause(
		Filter_Field $field,
		Field_Storage $storage,
		Operator $positive,
		bool $negative,
		array $operands,
		string $object_column,
		?int $join_slot
	): Clause {
		$table  = '`' . $storage->table . '`';
		$id     = '`' . $storage->object_column . '`';
		$column = 'r.`' . $storage->value_column . '`';

		list( $value_test, $value_args ) = Operator::EXISTS === $positive
			? array( '1 = 1', array() )
			: $this->comparison( $field, $column, $storage->value_kind, $positive, $operands );

		list( $where, $args ) = $this->with_constants( $value_test, $value_args, $storage, 'r' );

		if ( $negative ) {
			// The IS NOT NULL is load-bearing, not defensive: an uncorrelated NOT IN
			// over a nullable column returns UNKNOWN for every row and matches
			// nothing, which reads as an over-narrow filter rather than as a bug.
			// WPML's icl_translations.element_id is BIGINT NULL. On a NOT NULL column
			// MySQL removes the predicate, so the measured shape is preserved.
			return Clause::where(
				"{$object_column} NOT IN (
				SELECT r.{$id} FROM {$table} r WHERE r.{$id} IS NOT NULL AND ( {$where} )
			)",
				$args
			);
		}

		if ( null === $join_slot ) {
			return Clause::where(
				"{$object_column} IN (
				SELECT r.{$id} FROM {$table} r WHERE {$where}
			)",
				$args
			);
		}

		$alias = $this->alias( $join_slot );

		return Clause::join(
			"INNER JOIN (
				SELECT DISTINCT r.{$id} FROM {$table} r WHERE {$where}
			) {$alias} ON {$alias}.{$id} = {$object_column}",
			$args
		);
	}

	/**
	 * AND the storage's constant column tests into a row predicate.
	 *
	 * @param string        $where   The predicate so far.
	 * @param mixed[]       $args    Its arguments.
	 * @param Field_Storage $storage The storage carrying the constants.
	 * @param string        $alias   The row alias inside the subquery.
	 * @return array{0: string, 1: list<mixed>}
	 */
	private function with_constants( string $where, array $args, Field_Storage $storage, string $alias ): array {
		foreach ( $storage->constants as $constant ) {
			$where .= sprintf(
				' AND %s.`%s` = %s',
				$alias,
				$constant['column'],
				$constant['kind']->placeholder()
			);

			$args[] = $constant['kind']->cast( $constant['value'] );
		}

		return array( $where, $args );
	}

	/**
	 * The value comparison, for a column that may be a real column or a meta value.
	 *
	 * @param Filter_Field           $field    The descriptor, to name the field in a refusal.
	 * @param string                 $column   The column expression being compared.
	 * @param Value_Kind             $kind     How the stored value compares.
	 * @param Operator               $operator The positive operator.
	 * @param list<int|float|string> $operands The mapped operands.
	 * @return array{0: string, 1: list<mixed>}
	 *
	 * @throws Filter_Field_Unavailable When the comparison cannot be expressed.
	 */
	private function comparison( Filter_Field $field, string $column, Value_Kind $kind, Operator $operator, array $operands ): array {
		// A serialised list is never compared, only probed. The quoted-token pattern
		// is what makes `%"12"%` not match 112 or 2012-12-01, and it works only
		// because ACF casts every element to a string on save.
		if ( Value_Kind::SERIALIZED_LIST === $kind ) {
			if ( Operator::IN !== $operator ) {
				return $this->refuse( $field, 'a multi-value field can only be asked "is one of".' );
			}

			$probes = array();
			$args   = array();

			foreach ( $operands as $operand ) {
				$probes[] = "{$column} LIKE %s";
				$args[]   = '%"' . $this->wpdb->esc_like( (string) $operand ) . '"%';
			}

			// The engine expands the list, so the "is one of" that becomes "is all of"
			// cannot be written by a provider. Parenthesised because it is the one
			// disjunction this class emits.
			return array( '( ' . implode( ' OR ', $probes ) . ' )', $args );
		}

		$compared    = $this->numeric_comparison( $kind, $operator )
			? sprintf( 'CAST( %s AS %s )', $column, self::NUMERIC_CAST )
			: $column;
		$placeholder = $this->numeric_comparison( $kind, $operator ) ? '%f' : $kind->placeholder();

		$ordered = array(
			Operator::GREATER_THAN->name     => '>',
			Operator::GREATER_OR_EQUAL->name => '>=',
			Operator::LESS_THAN->name        => '<',
			Operator::LESS_OR_EQUAL->name    => '<=',
		);

		if ( Operator::EQUALS === $operator ) {
			return array( "{$compared} = {$placeholder}", array( $operands[0] ) );
		}

		if ( Operator::CONTAINS === $operator ) {
			return array( "{$column} LIKE %s", array( '%' . $this->wpdb->esc_like( (string) $operands[0] ) . '%' ) );
		}

		if ( isset( $ordered[ $operator->name ] ) ) {
			return array( "{$compared} {$ordered[ $operator->name ]} {$placeholder}", array( $operands[0] ) );
		}

		if ( Operator::BETWEEN === $operator ) {
			return array(
				"{$compared} BETWEEN {$placeholder} AND {$placeholder}",
				array( $operands[0], $operands[1] ),
			);
		}

		if ( Operator::IN === $operator ) {
			return array(
				sprintf( '%s IN ( %s )', $compared, $this->placeholders( count( $operands ), $placeholder ) ),
				$operands,
			);
		}

		// Unreachable while Filter_Providers checks the operator against the field's
		// declared list first. Kept as a refusal so an operator added to the enum
		// without a branch here fails loudly rather than decaying the condition into
		// "has this key at all", which is how a filter silently widens.
		return $this->refuse(
			$field,
			sprintf( 'no "%s" comparison is available for it.', $operator->value )
		);
	}

	/**
	 * Whether this comparison has to cast the stored text to a number.
	 *
	 * Equality and IN are deliberately excluded: `'9' = '9'` is already right, and
	 * casting them would defeat the meta_key index for no gain. Ordering is the only
	 * place the cast is needed, and it is the only place it costs an index.
	 *
	 * @param Value_Kind $kind     How the stored value compares.
	 * @param Operator   $operator The positive operator.
	 */
	private function numeric_comparison( Value_Kind $kind, Operator $operator ): bool {
		if ( Value_Kind::NUMERIC_TEXT !== $kind ) {
			return false;
		}

		return in_array(
			$operator,
			array(
				Operator::GREATER_THAN,
				Operator::GREATER_OR_EQUAL,
				Operator::LESS_THAN,
				Operator::LESS_OR_EQUAL,
				Operator::BETWEEN,
			),
			true
		);
	}

	/**
	 * Whether an operator's operand is a list rather than a single value.
	 *
	 * @param Operator $operator The positive operator.
	 */
	private function takes_a_list( Operator $operator ): bool {
		return Operator::IN === $operator;
	}

	/**
	 * A comma-separated run of placeholders.
	 *
	 * @param int    $count       How many.
	 * @param string $placeholder Which placeholder.
	 */
	private function placeholders( int $count, string $placeholder ): string {
		return implode( ', ', array_fill( 0, max( 0, $count ), $placeholder ) );
	}

	/**
	 * The alias for a join slot.
	 *
	 * Minted from the slot the engine assigned, never from anything a provider
	 * supplies, so the same field used twice in one filter cannot collide with
	 * itself.
	 *
	 * @param int $slot The slot number.
	 */
	private function alias( int $slot ): string {
		return 'co_f' . $slot;
	}

	/**
	 * Refuse a comparison, naming the field.
	 *
	 * @param Filter_Field $field  The descriptor.
	 * @param string       $reason What cannot be expressed, one sentence.
	 *
	 * @throws Filter_Field_Unavailable Always.
	 */
	private function refuse( Filter_Field $field, string $reason ): never {
		$this->unavailable(
			sprintf( 'The filter condition on "%1$s" cannot be run: %2$s', $field->key, $reason ),
			$field->key
		);
	}

	/**
	 * Throw the refusal, naming the field.
	 *
	 * One place where the escaping decision is made, rather than wherever a throw
	 * happens to appear next — the message is assembled from a filter key that
	 * arrives verbatim from a REST body.
	 *
	 * @param string $message The assembled message.
	 * @param string $key     The filter key that could not answer.
	 *
	 * @throws Filter_Field_Unavailable Always.
	 */
	private function unavailable( string $message, string $key ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
		throw new Filter_Field_Unavailable( $message, $key );
	}
}
