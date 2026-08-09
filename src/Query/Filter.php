<?php
/**
 * A set of conditions combined with one boolean relation.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

/**
 * An immutable collection of {@see Condition}s joined by AND or OR. This is the
 * structure the query engine resolves and that gets persisted as filter_json.
 *
 * V1 keeps a single flat relation across all conditions; nested groups can come
 * later without changing the persisted shape (the relation lives at the top).
 */
final class Filter {

	public const RELATION_AND = 'AND';
	public const RELATION_OR  = 'OR';

	/**
	 * The conditions to combine.
	 *
	 * @var list<Condition>
	 */
	private array $conditions;

	/**
	 * Either {@see self::RELATION_AND} or {@see self::RELATION_OR}.
	 *
	 * @var string
	 */
	private string $relation;

	/**
	 * Whether this filter targets products or their variations.
	 *
	 * @var Query_Scope
	 */
	private Query_Scope $scope;

	/**
	 * Build a filter from conditions, a relation, and a target scope.
	 *
	 * @param Condition[]      $conditions Conditions to combine.
	 * @param string           $relation   AND (default) or OR.
	 * @param Query_Scope|null $scope      Products (default) or variations.
	 */
	public function __construct( array $conditions = array(), string $relation = self::RELATION_AND, ?Query_Scope $scope = null ) {
		$this->conditions = array_values( $conditions );
		$this->relation   = self::RELATION_OR === strtoupper( $relation ) ? self::RELATION_OR : self::RELATION_AND;
		$this->scope      = $scope ?? Query_Scope::default_scope();
	}

	/**
	 * Return a new filter with an added condition (immutable).
	 *
	 * @param Condition $condition The condition to append.
	 */
	public function with( Condition $condition ): self {
		return new self( array( ...$this->conditions, $condition ), $this->relation, $this->scope );
	}

	/**
	 * The conditions in this filter.
	 *
	 * @return list<Condition>
	 */
	public function conditions(): array {
		return $this->conditions;
	}

	/**
	 * The boolean relation joining the conditions.
	 */
	public function relation(): string {
		return $this->relation;
	}

	/**
	 * The object type this filter targets.
	 */
	public function scope(): Query_Scope {
		return $this->scope;
	}

	/**
	 * Whether the filter has no conditions (matches the whole catalog).
	 */
	public function is_empty(): bool {
		return array() === $this->conditions;
	}

	/**
	 * Rebuild a filter from its array form.
	 *
	 * @param array{relation?: string, scope?: string, conditions?: array<int, array<string, mixed>>} $data Serialized filter.
	 */
	public static function from_array( array $data ): self {
		$conditions = array_map(
			static fn( array $condition ): Condition => Condition::from_array( $condition ),
			$data['conditions'] ?? array()
		);

		$scope = isset( $data['scope'] )
			? ( Query_Scope::tryFrom( (string) $data['scope'] ) ?? Query_Scope::default_scope() )
			: Query_Scope::default_scope();

		return new self( $conditions, (string) ( $data['relation'] ?? self::RELATION_AND ), $scope );
	}

	/**
	 * Serialize to a JSON-friendly array (for filter_json).
	 *
	 * @return array{relation: string, scope: string, conditions: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'relation'   => $this->relation,
			'scope'      => $this->scope->value,
			'conditions' => array_map(
				static fn( Condition $condition ): array => $condition->to_array(),
				$this->conditions
			),
		);
	}
}
