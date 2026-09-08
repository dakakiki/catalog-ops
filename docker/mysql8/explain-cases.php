<?php
/**
 * The EXPLAIN suite's case list, shared by both consumers.
 *
 * `generate-explain-sql.php` turns these into a .sql file for the MySQL 8.0
 * container; `check-plans.php` runs them against whatever database wpdb points
 * at and asserts their plans. One list, so the two cannot drift — a shape
 * measured on 8.0 but absent from the assertions, or the reverse, is worse than
 * measuring nothing, because it reads as coverage.
 *
 * Returns `array<string, array{desc: string, filter: Filter, requirements: Requirement[]}>`.
 *
 * @package CatalogOps\Docker
 */

use CatalogOps\Licensing\License;
use CatalogOps\Operations\Actions\Adjust;
use CatalogOps\Operations\Write_Rules;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Fields\Field_Storage;
use CatalogOps\Query\Fields\Filter_Control;
use CatalogOps\Query\Fields\Filter_Field;
use CatalogOps\Query\Fields\Filter_Provider;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Fields\Object_Anchor;
use CatalogOps\Query\Fields\Value_Kind;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;

/**
 * A provider covering every storage shape the compiler can emit.
 *
 * Deliberately one provider rather than several: the harness is measuring
 * shapes, not modules, and a shape must be measured whether or not the module
 * that will eventually use it has been written. The meta keys are the ones the
 * ACF demo dataset writes across the whole catalogue — see the project notes —
 * with `_regular_price` as the fallback on a catalogue that lacks them, since a
 * plan depends on index statistics rather than on whether a literal matches.
 */
final class CatalogOps_Explain_Provider implements Filter_Provider {

	/**
	 * The meta key used where the demo dataset is absent.
	 */
	private string $fallback;

	/**
	 * Whether the demo dataset is present.
	 */
	private bool $demo;

	/**
	 * Build the provider.
	 *
	 * @param bool   $demo     Whether the ACF demo meta keys exist.
	 * @param string $fallback Meta key to use when they do not.
	 */
	public function __construct( bool $demo, string $fallback = '_regular_price' ) {
		$this->demo     = $demo;
		$this->fallback = $fallback;
	}

	/**
	 * The meta key behind a shape, falling back when the demo data is absent.
	 *
	 * @param string $preferred The demo key.
	 */
	private function key( string $preferred ): string {
		return $this->demo ? $preferred : $this->fallback;
	}

	public function module(): string {
		return '';
	}

	public function filter_fields(): array {
		$fields = array();

		foreach ( self::SHAPES as $key => $spec ) {
			$fields[] = new Filter_Field(
				$key,
				$key,
				Filter_Control::TEXT,
				Value_Kind::from( $spec )->operators(),
				array( Query_Scope::PRODUCT, Query_Scope::VARIATION )
			);
		}

		return $fields;
	}

	/**
	 * Every field this provider offers, and the value kind behind it.
	 *
	 * @var array<string, string>
	 */
	private const SHAPES = array(
		'x:meta_text'    => 'text',
		'x:meta_number'  => 'numeric_text',
		'x:meta_date'    => 'date_text',
		'x:meta_list'    => 'serialized_list',
		'x:repeater'     => 'text',
		'x:related'      => 'text',
		'x:taxonomy'     => 'integer',
		'x:lookup_price' => 'decimal',
	);

	public function handles_filter( string $key ): bool {
		return isset( self::SHAPES[ $key ] );
	}

	public function storage_for( string $key, Query_Scope $scope ): Field_Storage {
		global $wpdb;

		switch ( $key ) {
			case 'x:meta_text':
				return Field_Storage::post_meta( $this->key( 'co_supplier' ), Value_Kind::TEXT );

			case 'x:meta_number':
				return Field_Storage::post_meta( $this->key( 'co_cost' ), Value_Kind::NUMERIC_TEXT );

			case 'x:meta_date':
				return Field_Storage::post_meta( $this->key( 'co_launch' ), Value_Kind::DATE_TEXT );

			case 'x:meta_list':
				return Field_Storage::post_meta( $this->key( 'co_badges' ), Value_Kind::SERIALIZED_LIST );

			case 'x:repeater':
				// The one shape whose meta_key is a LIKE rather than an equality, so
				// it is the one that can lose the meta_key index outright.
				return Field_Storage::post_meta_rows( 'co_specs_', '_value', Value_Kind::TEXT );

			case 'x:related':
				// A foreign table with an object-id column — WPML's icl_translations
				// is the real case. term_relationships stands in for it here because
				// every catalogue has one, and the shape is what is being measured.
				return Field_Storage::related_rows(
					ltrim( $wpdb->term_relationships, '`' ),
					'object_id',
					'term_taxonomy_id',
					Value_Kind::INTEGER,
					Object_Anchor::SELF
				);

			case 'x:taxonomy':
				return Field_Storage::taxonomy( 'product_cat' );

			default:
				return Field_Storage::lookup_column( \CatalogOps\Query\Fields\Lookup_Column::MIN_PRICE );
		}
	}
}

/**
 * Build the case list.
 *
 * @param \wpdb    $wpdb       Database handle.
 * @param int[]    $categories Real product_cat term ids.
 * @param int[]    $colors     Real pa_color term ids.
 * @param int[]    $sizes      Real pa_size term ids.
 * @param int[]    $tt_ids     Real term_taxonomy_ids, for the related-rows shape.
 * @param bool     $demo       Whether the ACF demo meta keys exist.
 * @return array<string, array{desc: string, filter: Filter, requirements: array}>
 */
return static function ( $wpdb, array $categories, array $colors, array $sizes, array $tt_ids, bool $demo ): array {
	$product   = Query_Scope::PRODUCT;
	$variation = Query_Scope::VARIATION;
	$rules     = new Write_Rules();

	// The requirements a real price edit contributes. THIS IS THE POINT of the
	// rebuild: preview() and freeze_edit() always run with these, and the harness
	// never has — so the one-to-three positive semi-joins they add have never been
	// EXPLAINed on a real catalogue, and they are the headroom a provider clause
	// has to compete for.
	$price_edit = $rules->requirements( array( new Adjust( 'regular_price', 10 ) ) );

	$cases = array();

	/**
	 * Register a case.
	 *
	 * @param string $name         Case name.
	 * @param string $desc         What it measures.
	 * @param Filter $filter       The filter.
	 * @param array  $requirements Applicability constraints.
	 */
	$add = static function ( string $name, string $desc, Filter $filter, array $requirements = array() ) use ( &$cases ): void {
		$cases[ $name ] = array(
			'desc'         => $desc,
			'filter'       => $filter,
			'requirements' => $requirements,
		);
	};

	// ---------------------------------------------------------------- core ---

	$add(
		'core_price_between',
		'Product · price BETWEEN — indexed lookup column',
		new Filter( array( new Condition( 'price', Operator::BETWEEN, array( 10, 250 ) ) ) )
	);

	$add(
		'core_category_in',
		'Product · category IN — taxonomy join over a DISTINCT derived table',
		new Filter( array( new Condition( 'category', Operator::IN, $categories ) ) )
	);

	$add(
		'core_category_not_in',
		'Product · category NOT IN — the measured NOT EXISTS anti-join',
		new Filter( array( new Condition( 'category', Operator::NOT_IN, $categories ) ) )
	);

	$add(
		'core_attribute_variation',
		'Variation · attribute by slug on the variation itself',
		new Filter( array( new Condition( 'attribute:pa_size', Operator::IN, $sizes ) ), Filter::RELATION_AND, $variation )
	);

	$add(
		'core_combined',
		'Product · price + category + stock_status, the filter the UI actually produces',
		new Filter(
			array(
				new Condition( 'price', Operator::BETWEEN, array( 10, 250 ) ),
				new Condition( 'category', Operator::IN, $categories ),
				new Condition( 'stock_status', Operator::EQUALS, 'instock' ),
			)
		)
	);

	// -------------------------------------------------- core + requirements ---

	$add(
		'core_combined_with_requirements',
		'Product · the same filter as freeze_edit() runs it — WITH the applicability requirements',
		new Filter(
			array(
				new Condition( 'price', Operator::BETWEEN, array( 10, 250 ) ),
				new Condition( 'category', Operator::IN, $categories ),
			)
		),
		$price_edit
	);

	$add(
		'core_requirements_alone',
		'Product · no conditions, requirements only — the semi-joins Write_Rules adds, measured alone',
		new Filter(),
		$price_edit
	);

	// ------------------------------------------------------------ provider ---

	$add(
		'shape_meta_join',
		'Provider · post_meta equality under AND — INNER JOIN over a DISTINCT derived table',
		new Filter( array( new Condition( 'x:meta_text', Operator::EQUALS, 'Globex' ) ) )
	);

	$add(
		'shape_meta_semijoin_or',
		'Provider · the same under OR, where no join is available — the documented plan hazard',
		new Filter(
			array(
				new Condition( 'x:meta_text', Operator::EQUALS, 'Globex' ),
				new Condition( 'price', Operator::GREATER_THAN, 500 ),
			),
			Filter::RELATION_OR
		)
	);

	$add(
		'shape_meta_not_in',
		'Provider · post_meta exclusion — the uncorrelated NOT IN, measured at 2.5s vs 13.6s for NOT EXISTS',
		new Filter( array( new Condition( 'x:meta_text', Operator::NOT_EQUALS, 'Globex' ) ) )
	);

	$add(
		'shape_meta_numeric_cast',
		'Provider · a number stored as text, ordered — the cast that no index can serve',
		new Filter( array( new Condition( 'x:meta_number', Operator::GREATER_THAN, 100 ) ) )
	);

	$add(
		'shape_meta_date_range',
		'Provider · a big-endian date range, lexical and uncast',
		new Filter( array( new Condition( 'x:meta_date', Operator::BETWEEN, array( '20240101', '20240630' ) ) ) )
	);

	$add(
		'shape_serialized_list',
		'Provider · SERIALIZED_LIST — an unindexed LONGTEXT LIKE across a whole meta_key partition. The spec records this shape as UNMEASURED.',
		new Filter( array( new Condition( 'x:meta_list', Operator::IN, array( 'eco', 'sale' ) ) ) )
	);

	$add(
		'shape_repeater_rows',
		'Provider · post_meta_rows — meta_key LIKE, the one shape that can lose the meta_key index',
		new Filter( array( new Condition( 'x:repeater', Operator::EQUALS, 'Steel' ) ) )
	);

	$add(
		'shape_related_rows',
		'Provider · related_rows over a foreign table with an object-id column (WPML icl_translations is the real case)',
		new Filter( array( new Condition( 'x:related', Operator::IN, $tt_ids ) ) )
	);

	$add(
		'shape_related_rows_not_in',
		'Provider · related_rows exclusion, with the IS NOT NULL guard a nullable object column needs',
		new Filter( array( new Condition( 'x:related', Operator::NOT_IN, $tt_ids ) ) )
	);

	$add(
		'shape_taxonomy_provider',
		'Provider · taxonomy membership through the compiler, which must match the core path',
		new Filter( array( new Condition( 'x:taxonomy', Operator::IN, $categories ) ) )
	);

	$add(
		'shape_provider_with_requirements',
		'Provider · a module field beside the requirements a real edit adds — the shape that actually ships',
		new Filter( array( new Condition( 'x:meta_text', Operator::EQUALS, 'Globex' ) ) ),
		$price_edit
	);

	$add(
		'shape_two_module_fields',
		'Provider · two module conditions in one filter — two derived-table joins stacked',
		new Filter(
			array(
				new Condition( 'x:meta_text', Operator::EQUALS, 'Globex' ),
				new Condition( 'x:meta_number', Operator::GREATER_THAN, 100 ),
			)
		)
	);

	$add(
		'shape_or_stack',
		'Provider · three semi-joins under OR — the stacking the spec calls untested',
		new Filter(
			array(
				new Condition( 'x:meta_text', Operator::EQUALS, 'Globex' ),
				new Condition( 'x:meta_number', Operator::GREATER_THAN, 100 ),
				new Condition( 'x:meta_date', Operator::GREATER_THAN, '20240101' ),
			),
			Filter::RELATION_OR
		)
	);

	$add(
		'shape_variation_parent_anchor',
		'Provider · a PARENT-anchored field under the variation scope — matches p.post_parent',
		new Filter( array( new Condition( 'x:taxonomy', Operator::IN, $categories ) ), Filter::RELATION_AND, $variation )
	);

	unset( $colors, $product );

	return $cases;
};
