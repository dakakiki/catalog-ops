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
 *
 * **The language is a sibling of the scope, never a condition.** Both answer
 * "which objects are we even talking about" before any condition is asked, and
 * neither is something the user picks here: the scope is the Products/Variations
 * toggle and the language is simply whichever one they are working in. Putting
 * the language on the filter rather than in it is what lets a cron tick — which
 * has no admin language of its own, and under WPML's own rules cannot have one —
 * replay the user's question three days later and get the user's answer.
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
	 * The WPML language this filter is confined to, or null for no confinement.
	 *
	 * @var string|null
	 */
	private ?string $language;

	/**
	 * Build a filter from conditions, a relation, a target scope, and a language.
	 *
	 * @param Condition[]      $conditions Conditions to combine.
	 * @param string           $relation   AND (default) or OR.
	 * @param Query_Scope|null $scope      Products (default) or variations.
	 * @param string|null      $language   WPML language code, or null for every object.
	 */
	public function __construct( array $conditions = array(), string $relation = self::RELATION_AND, ?Query_Scope $scope = null, ?string $language = null ) {
		$this->conditions = array_values( $conditions );
		$this->relation   = self::RELATION_OR === strtoupper( $relation ) ? self::RELATION_OR : self::RELATION_AND;
		$this->scope      = $scope ?? Query_Scope::default_scope();
		$this->language   = self::normalize_language( $language );
	}

	/**
	 * Return a new filter with an added condition (immutable).
	 *
	 * @param Condition $condition The condition to append.
	 */
	public function with( Condition $condition ): self {
		return new self( array( ...$this->conditions, $condition ), $this->relation, $this->scope, $this->language );
	}

	/**
	 * Return the same filter aimed at a different object type (immutable).
	 *
	 * Asking the same conditions of the other scope is how the empty-result hint
	 * knows whether the products the user is looking for are variations.
	 *
	 * The language is carried across, and it has to be: the hint's whole claim is
	 * "the same question, asked of variations". Dropping the confinement would
	 * count objects in languages the user is not working in and offer them a
	 * number they can never reach.
	 *
	 * @param Query_Scope $scope The object type to target.
	 */
	public function for_scope( Query_Scope $scope ): self {
		return new self( $this->conditions, $this->relation, $scope, $this->language );
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
	 * The WPML language this filter is confined to, or null for no confinement.
	 *
	 * Null is the only way to say "every object", and it says it in one voice:
	 * a site with no WPML, a filter written before this existed, and a user
	 * working on WPML's "All languages" all produce exactly the same null and so
	 * exactly the same statement. A second spelling of "no confinement" — 'all',
	 * or the empty string — would oblige the engine, the history and the
	 * schedules list each to know both, and one of them would eventually know
	 * only one.
	 */
	public function language(): ?string {
		return $this->language;
	}

	/**
	 * Collapse every spelling of "no confinement" onto null.
	 *
	 * WPML's "All languages" really is the string `all` — {@see SitePress::get_current_language()}
	 * returns it, and its own guards accept it only under `is_admin()`. It reaches
	 * here as a language code like any other and must not: the owner's rule is that
	 * "All languages" places no constraint at all, which is what null already means.
	 * The empty string arrives from JSON that carried the key without a value.
	 *
	 * @param string|null $language The captured language, if any.
	 */
	private static function normalize_language( ?string $language ): ?string {
		$language = null === $language ? '' : trim( $language );

		return ( '' === $language || 'all' === strtolower( $language ) ) ? null : $language;
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
	 * A filter persisted before the language existed carries no `language` key, and
	 * an absent key, a null and an empty string all rebuild as null — the same
	 * unconfined filter it was when it was written. That is what makes every saved
	 * filter, every schedule and every frozen operation from before this change go
	 * on meaning exactly what it meant.
	 *
	 * @param array{relation?: string, scope?: string, language?: string|null, conditions?: array<int, array<string, mixed>>} $data Serialized filter.
	 */
	public static function from_array( array $data ): self {
		$conditions = array_map(
			static fn( array $condition ): Condition => Condition::from_array( $condition ),
			$data['conditions'] ?? array()
		);

		$scope = isset( $data['scope'] )
			? ( Query_Scope::tryFrom( (string) $data['scope'] ) ?? Query_Scope::default_scope() )
			: Query_Scope::default_scope();

		$language = isset( $data['language'] ) ? (string) $data['language'] : null;

		return new self( $conditions, (string) ( $data['relation'] ?? self::RELATION_AND ), $scope, $language );
	}

	/**
	 * Serialize to a JSON-friendly array (for filter_json).
	 *
	 * The language is written even when it is null. An operation's filter_json is
	 * the record of what a run was told to do, and "this run was not confined to a
	 * language" is a different statement from "this run predates languages" — the
	 * key being present says which.
	 *
	 * @return array{relation: string, scope: string, language: string|null, conditions: list<array<string, mixed>>}
	 */
	public function to_array(): array {
		return array(
			'relation'   => $this->relation,
			'scope'      => $this->scope->value,
			'language'   => $this->language,
			'conditions' => array_map(
				static fn( Condition $condition ): array => $condition->to_array(),
				$this->conditions
			),
		);
	}
}
