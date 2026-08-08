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
	 * Build a filter from conditions and a relation.
	 *
	 * @param Condition[] $conditions Conditions to combine.
	 * @param string      $relation   AND (default) or OR.
	 */
	public function __construct( array $conditions = array(), string $relation = self::RELATION_AND ) {
		$this->conditions = array_values( $conditions );
		$this->relation   = self::RELATION_OR === strtoupper( $relation ) ? self::RELATION_OR : self::RELATION_AND;
	}

	/**
	 * Return a new filter with an added condition (immutable).
	 *
	 * @param Condition $condition The condition to append.
	 */
	public function with( Condition $condition ): self {
		return new self( array( ...$this->conditions, $condition ), $this->relation );
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
	 * Whether the filter has no conditions (matches the whole catalog).
	 */
	public function is_empty(): bool {
		return array() === $this->conditions;
	}

	/**
	 * Rebuild a filter from its array form.
	 *
	 * @param array{relation?: string, conditions?: array<int, array<string, mixed>>} $data Serialized filter.
	 */
	public static function from_array( array $data ): self {
		$conditions = array_map(
			static fn( array $condition ): Condition => Condition::from_array( $condition ),
			$data['conditions'] ?? array()
		);

		return new self( $conditions, (string) ( $data['relation'] ?? self::RELATION_AND ) );
	}

	/**
	 * Serialize to a JSON-friendly array (for filter_json).
	 *
	 * @return array{relation: string, conditions: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'relation'   => $this->relation,
			'conditions' => array_map(
				static fn( Condition $condition ): array => $condition->to_array(),
				$this->conditions
			),
		);
	}
}
