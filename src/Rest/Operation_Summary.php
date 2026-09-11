<?php
/**
 * What an operation actually did, in words.
 *
 * @package CatalogOps\Rest
 */

namespace CatalogOps\Rest;

use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;
use wpdb;

/**
 * Turns an operation's frozen `filter_json` and `actions_json` back into
 * sentences a shop owner can read.
 *
 * **The history could not answer "what did this run do".** It carried the source,
 * the status, the counters and the time, and both halves of the actual answer —
 * which products, and what changed about them — sat unread in two `longtext`
 * columns. A record of a bulk edit that cannot say what the edit was is a
 * receipt, not an audit trail.
 *
 * Rendered on the server, and that is not incidental. Naming a condition needs
 * term names from the database and field labels from {@see Filter_Providers},
 * neither of which the admin bundle has or should have — the same reason a
 * module serves its own options rather than teaching the client about itself. It
 * also means a filter naming a module that has since been deactivated still
 * renders, because the raw key is right there to fall back to.
 *
 * **Nothing is ever hidden.** A term that has been deleted shows as its id, a
 * field whose provider is gone shows as its key, an operator nobody recognises
 * shows as its token. The frozen filter is what ran; a condition dropped from
 * this list because it could not be named would make the summary claim the run
 * targeted something wider than it did, which is the one error this whole plugin
 * is built to avoid.
 */
final class Operation_Summary {

	/**
	 * Taxonomy behind each core term-set key.
	 *
	 * @var array<string, string>
	 */
	private const TAXONOMIES = array(
		'category' => 'product_cat',
		'tag'      => 'product_tag',
		'brand'    => 'product_brand',
	);

	/**
	 * The language this summary is being written in, taken from the filter it
	 * describes. Null until describe() reads one, and null for every run that was
	 * not confined to a language.
	 *
	 * @var string|null
	 */
	private ?string $language = null;

	/**
	 * Build the describer.
	 *
	 * @param wpdb                  $wpdb      WordPress database handle.
	 * @param Filter_Providers|null $providers Module field registry, for their labels.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly ?Filter_Providers $providers = null
	) {}

	/**
	 * Describe one operation's filter and actions.
	 *
	 * @param array<string, mixed> $filter_data  Decoded filter_json.
	 * @param array<string, mixed> $actions_data Decoded actions_json.
	 * @return array{scope: string, conditions: list<array{label: string, operator: string, value: string}>, actions: list<array{label: string, change: string}>}
	 */
	public function describe( array $filter_data, array $actions_data ): array {
		// The language the run was aimed at, read off the frozen filter it is
		// describing — so a module's field is named the way it was named on the
		// screen the run was started from, rather than in whichever language this
		// request happens to resolve as.
		$this->language = isset( $filter_data['language'] ) && is_string( $filter_data['language'] ) && '' !== $filter_data['language']
			? $filter_data['language']
			: null;

		$scope = Query_Scope::tryFrom( (string) ( $filter_data['scope'] ?? '' ) )
			?? Query_Scope::default_scope();

		$conditions = array();

		foreach ( (array) ( $filter_data['conditions'] ?? array() ) as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$conditions[] = $this->condition( $condition );
		}

		$actions = array();

		foreach ( $actions_data as $action ) {
			if ( is_array( $action ) ) {
				$actions[] = $this->action( $action );
			}
		}

		return array(
			'scope'      => Query_Scope::VARIATION === $scope
				? __( 'Variations', 'catalogops' )
				: __( 'Products', 'catalogops' ),
			'relation'   => 'OR' === strtoupper( (string) ( $filter_data['relation'] ?? 'AND' ) )
				? __( 'any of these', 'catalogops' )
				: __( 'all of these', 'catalogops' ),
			'conditions' => $conditions,
			'actions'    => $actions,
		);
	}

	/**
	 * One condition as label, operator and value.
	 *
	 * @param array<string, mixed> $condition One entry of filter_json's conditions.
	 * @return array{label: string, operator: string, value: string}
	 */
	private function condition( array $condition ): array {
		$field    = (string) ( $condition['field'] ?? '' );
		$operator = (string) ( $condition['operator'] ?? '' );
		$value    = $condition['value'] ?? null;

		return array(
			'label'    => $this->field_label( $field ),
			'operator' => $this->operator_label( $operator ),
			// Presence carries no value at all, and printing an empty string beside
			// "has no value" reads as a value that failed to load.
			'value'    => in_array( $operator, array( 'exists', 'not_exists' ), true )
				? ''
				: $this->value_label( $field, $value ),
		);
	}

	/**
	 * A field's name as the filter offers it.
	 *
	 * @param string $field Filter key as persisted.
	 */
	private function field_label( string $field ): string {
		$core = array(
			'price'          => __( 'Price', 'catalogops' ),
			'stock_quantity' => __( 'Stock quantity', 'catalogops' ),
			'stock_status'   => __( 'Stock status', 'catalogops' ),
			'sku'            => __( 'SKU', 'catalogops' ),
			'category'       => __( 'Category', 'catalogops' ),
			'tag'            => __( 'Tag', 'catalogops' ),
			'brand'          => __( 'Brand', 'catalogops' ),
		);

		if ( isset( $core[ $field ] ) ) {
			return $core[ $field ];
		}

		if ( str_starts_with( $field, 'attribute:' ) ) {
			$taxonomy = substr( $field, strlen( 'attribute:' ) );
			$object   = get_taxonomy( $taxonomy );

			return false === $object
				? $taxonomy
				: html_entity_decode( $object->labels->singular_name, ENT_QUOTES, 'UTF-8' );
		}

		if ( str_starts_with( $field, 'meta:' ) ) {
			return substr( $field, strlen( 'meta:' ) );
		}

		// A module's field, named by the module that offers it. When the module is
		// gone the key is the honest answer — it is what the filter still says, and
		// it is what the user has to remove if they want the filter to run again.
		if ( null !== $this->providers ) {
			foreach ( $this->providers->all_fields( $this->language ) as $entry ) {
				if ( $entry['field']->key === $field ) {
					return $entry['field']->label;
				}
			}
		}

		return $field;
	}

	/**
	 * An operator in words.
	 *
	 * @param string $operator Operator token as persisted.
	 */
	private function operator_label( string $operator ): string {
		$words = array(
			'='          => __( 'is', 'catalogops' ),
			'!='         => __( 'is not', 'catalogops' ),
			'>'          => __( 'is more than', 'catalogops' ),
			'>='         => __( 'is at least', 'catalogops' ),
			'<'          => __( 'is less than', 'catalogops' ),
			'<='         => __( 'is at most', 'catalogops' ),
			'between'    => __( 'is between', 'catalogops' ),
			'in'         => __( 'is any of', 'catalogops' ),
			'not_in'     => __( 'is none of', 'catalogops' ),
			'contains'   => __( 'contains', 'catalogops' ),
			'exists'     => __( 'has any value', 'catalogops' ),
			'not_exists' => __( 'has no value', 'catalogops' ),
		);

		return $words[ $operator ] ?? $operator;
	}

	/**
	 * A condition's operand, with term ids resolved to names.
	 *
	 * @param string $field The filter key, which decides whether the operand is a
	 *                      set of term ids or a plain value.
	 * @param mixed  $value The operand as persisted.
	 */
	private function value_label( string $field, $value ): string {
		if ( null === $value ) {
			return '';
		}

		$taxonomy = self::TAXONOMIES[ $field ] ?? null;

		if ( null === $taxonomy && str_starts_with( $field, 'attribute:' ) ) {
			$taxonomy = substr( $field, strlen( 'attribute:' ) );
		}

		$values = is_array( $value ) ? $value : array( $value );

		if ( null !== $taxonomy ) {
			$values = array_map(
				function ( $one ) use ( $taxonomy ) {
					$term = get_term( (int) $one, $taxonomy );

					// A deleted term shows as its id rather than vanishing: the
					// filter still names it, and a condition missing from this list
					// would make the summary describe a wider run than happened.
					//
					// Decoded, because WordPress stores term names entity-encoded
					// and React renders this as text and escapes it again — so
					// "Home & Kitchen" reached the screen as "Home &amp; Kitchen".
					// The results table had exactly this bug and a test pins the
					// fix there; this is the same read of the same column.
					return ( $term instanceof \WP_Term )
						? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' )
						: '#' . (int) $one;
				},
				$values
			);
		}

		$values = array_map(
			static fn( $one ): string => is_scalar( $one ) ? (string) $one : wp_json_encode( $one ),
			$values
		);

		return implode( ', ', $values );
	}

	/**
	 * One action as the field it wrote and the change it made.
	 *
	 * @param array<string, mixed> $action One entry of actions_json.
	 * @return array{label: string, change: string}
	 */
	private function action( array $action ): array {
		$type  = (string) ( $action['type'] ?? '' );
		$field = (string) ( $action['field'] ?? '' );

		$change = match ( $type ) {
			'set'     => sprintf(
				/* translators: %s: the value the field was set to. */
				__( 'set to %s', 'catalogops' ),
				(string) ( $action['value'] ?? '' )
			),
			// The sign is already in the number, and it is the whole meaning of the
			// action: +2 and -2 are a rise and a cut, not "2" twice.
			'adjust'  => sprintf(
				/* translators: %s: a signed amount, e.g. "+2.00" or "-2.00". */
				__( 'changed by %s', 'catalogops' ),
				sprintf( '%+g', (float) ( $action['amount'] ?? 0 ) )
			),
			'formula' => sprintf(
				/* translators: %s: the formula expression as the user wrote it. */
				__( 'calculated as %s', 'catalogops' ),
				(string) ( $action['expression'] ?? '' )
			),
			default   => $type,
		};

		return array(
			'label'  => $this->write_field_label( $field ),
			'change' => $change,
		);
	}

	/**
	 * A written field's name. A different key space from the filter's — `price`
	 * filters a lookup column while `regular_price` writes a meta key, and neither
	 * exists on the other side — so it gets its own map rather than sharing one.
	 *
	 * @param string $field Write field key as persisted.
	 */
	private function write_field_label( string $field ): string {
		$core = array(
			'regular_price'  => __( 'Regular price', 'catalogops' ),
			'sale_price'     => __( 'Sale price', 'catalogops' ),
			'stock_quantity' => __( 'Stock quantity', 'catalogops' ),
			'stock_status'   => __( 'Stock status', 'catalogops' ),
			'weight'         => __( 'Weight', 'catalogops' ),
			'name'           => __( 'Name', 'catalogops' ),
			'sku'            => __( 'SKU', 'catalogops' ),
		);

		if ( isset( $core[ $field ] ) ) {
			return $core[ $field ];
		}

		return str_starts_with( $field, 'meta:' )
			? substr( $field, strlen( 'meta:' ) )
			: $field;
	}
}
