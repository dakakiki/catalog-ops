<?php
/**
 * Generate EXPLAIN statements for the CatalogOps query engine's real SQL.
 *
 * Run against the live dev site so the statements carry the live table prefix
 * and real term ids:
 *
 *   wp eval-file docker/mysql8/generate-explain-sql.php
 *
 * (from the WordPress root, with the plugin active). It reflects into
 * Query_Engine::select() so the EXPLAINed SQL is byte-for-byte what the engine
 * emits at runtime — hand-copied SQL would drift as the engine changes. The
 * output file is then piped into both the MySQL 8.0 container and the local 5.7
 * so their plans can be compared (see README.md).
 *
 * @package CatalogOps\Docker
 */

use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use CatalogOps\Query\Query_Scope;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run through wp eval-file.\n" );
	exit( 1 );
}

if ( ! class_exists( Query_Engine::class ) ) {
	fwrite( STDERR, "CatalogOps is not loaded — activate the plugin first.\n" );
	exit( 1 );
}

global $wpdb;

$engine     = new Query_Engine( $wpdb );
$reflection = new ReflectionMethod( Query_Engine::class, 'select' );
$reflection->setAccessible( true );

// wpdb::prepare() returns LIKE '%' literals as an internal placeholder hash and
// only converts them back to '%' inside get_col()/query() at execution time. The
// engine's reads go through those, so mirror the same step here — otherwise the
// EXPLAINed LIKE would carry the hash instead of a real leading wildcard.
$finalize = static fn( string $sql ): string => $wpdb->remove_placeholder_escape( $sql );

/**
 * Build the resolve SQL (SELECT product_id ... ORDER BY) exactly as
 * Query_Engine::resolve() would, via reflection on the private select().
 */
$resolve_sql = static function ( Filter $filter ) use ( $engine, $reflection, $finalize ): string {
	return $finalize( $reflection->invoke( $engine, 'l.product_id', $filter ) . ' ORDER BY l.product_id ASC' );
};

/**
 * Build the count SQL exactly as Query_Engine::count() would.
 */
$count_sql = static function ( Filter $filter ) use ( $engine, $reflection, $finalize ): string {
	return $finalize( $reflection->invoke( $engine, 'COUNT(*)', $filter ) );
};

/**
 * Pull a few real term ids for a taxonomy, with a harmless fallback so the
 * generator still produces valid SQL on a catalog that lacks them (EXPLAIN
 * plans depend on index stats, not on whether the literals match a row).
 *
 * @return int[]
 */
$term_ids = static function ( string $taxonomy, int $limit = 3 ) use ( $wpdb ): array {
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s ORDER BY tt.count DESC LIMIT %d",
			$taxonomy,
			$limit
		)
	);

	$ids = array_map( 'intval', $ids );

	return array() === $ids ? array( 10, 11, 12 ) : $ids;
};

$categories = $term_ids( 'product_cat' );
$colors     = $term_ids( 'pa_color' );
$sizes      = $term_ids( 'pa_size' );

$product   = Query_Scope::PRODUCT;
$variation = Query_Scope::VARIATION;

// Representative filters — one per clause path in Query_Engine, plus the
// realistic "category + brand + price" combined filter the M5 UI produces.
$cases = array(
	'price_between_product'      => array(
		'desc'   => 'Product · price BETWEEN 10 AND 250 (numeric column on wc_product_meta_lookup)',
		'filter' => new Filter(
			array( new Condition( 'price', Operator::BETWEEN, array( 10, 250 ) ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'stock_status_product'       => array(
		'desc'   => 'Product · stock_status = instock (string column on lookup)',
		'filter' => new Filter(
			array( new Condition( 'stock_status', Operator::EQUALS, 'instock' ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'sku_contains_product'       => array(
		'desc'   => 'Product · sku CONTAINS COPS (LIKE on lookup — leading wildcard)',
		'filter' => new Filter(
			array( new Condition( 'sku', Operator::CONTAINS, 'COPS' ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'category_in_product'        => array(
		'desc'   => 'Product · category IN (term ids) — correlated taxonomy EXISTS on product_id',
		'filter' => new Filter(
			array( new Condition( 'category', Operator::IN, $categories ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'attribute_color_product'    => array(
		'desc'   => 'Product · attribute:pa_color IN (term ids) — taxonomy EXISTS on product_id',
		'filter' => new Filter(
			array( new Condition( 'attribute:pa_color', Operator::IN, $colors ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'meta_string_product'        => array(
		'desc'   => 'Product · meta:_catalogops_brand = Acme — driven from the postmeta meta_key index',
		'filter' => new Filter(
			array( new Condition( 'meta:_catalogops_brand', Operator::EQUALS, 'Acme' ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'meta_numeric_product'       => array(
		'desc'   => 'Product · meta:_catalogops_cost > 10 — CAST(meta_value AS DECIMAL) comparison',
		'filter' => new Filter(
			array( new Condition( 'meta:_catalogops_cost', Operator::GREATER_THAN, 10 ) ),
			Filter::RELATION_AND,
			$product
		),
	),
	'price_variation'            => array(
		'desc'   => 'Variation · price <= 250 (post_type = product_variation)',
		'filter' => new Filter(
			array( new Condition( 'price', Operator::LESS_OR_EQUAL, 250 ) ),
			Filter::RELATION_AND,
			$variation
		),
	),
	'category_in_variation'      => array(
		'desc'   => 'Variation · category IN (term ids) — EXISTS correlated on p.post_parent (inherited)',
		'filter' => new Filter(
			array( new Condition( 'category', Operator::IN, $categories ) ),
			Filter::RELATION_AND,
			$variation
		),
	),
	'attribute_size_variation'   => array(
		'desc'   => 'Variation · attribute:pa_size IN (term ids) — postmeta attribute_pa_size by slug',
		'filter' => new Filter(
			array( new Condition( 'attribute:pa_size', Operator::IN, $sizes ) ),
			Filter::RELATION_AND,
			$variation
		),
	),
	'combined_product'           => array(
		'desc'   => 'Product · price BETWEEN + category IN + stock_status (the M5 "category+brand+price" scenario)',
		'filter' => new Filter(
			array(
				new Condition( 'price', Operator::BETWEEN, array( 10, 250 ) ),
				new Condition( 'category', Operator::IN, $categories ),
				new Condition( 'stock_status', Operator::EQUALS, 'instock' ),
			),
			Filter::RELATION_AND,
			$product
		),
	),
);

$lines   = array();
$lines[] = '-- CatalogOps query-engine EXPLAIN suite — GENERATED, do not edit by hand.';
$lines[] = '-- Regenerate: wp eval-file docker/mysql8/generate-explain-sql.php';
$lines[] = '-- Table prefix: ' . $wpdb->prefix;
$lines[] = '';

// Traditional EXPLAIN for every case (type / key / rows / Extra — the columns
// that diverge between 5.7 and 8.0).
foreach ( $cases as $name => $case ) {
	$lines[] = "-- ===== {$name} (resolve) =====";
	$lines[] = "-- {$case['desc']}";
	$lines[] = 'EXPLAIN ' . $resolve_sql( $case['filter'] ) . ';';
	$lines[] = '';
	$lines[] = "-- ===== {$name} (count) =====";
	$lines[] = 'EXPLAIN ' . $count_sql( $case['filter'] ) . ';';
	$lines[] = '';
}

// JSON EXPLAIN (with cost estimates) for the heaviest paths, where an 8.0
// optimizer change is most likely to bite.
$json_cases = array( 'combined_product', 'category_in_variation', 'attribute_size_variation', 'meta_numeric_product' );

$lines[] = '-- ===================================================================';
$lines[] = '-- EXPLAIN FORMAT=JSON (cost estimates) for the heaviest paths';
$lines[] = '-- ===================================================================';
$lines[] = '';

foreach ( $json_cases as $name ) {
	$lines[] = "-- ===== {$name} (resolve, JSON) =====";
	$lines[] = 'EXPLAIN FORMAT=JSON ' . $resolve_sql( $cases[ $name ]['filter'] ) . ';';
	$lines[] = '';
}

$out = __DIR__ . '/explain-queries.generated.sql';
file_put_contents( $out, implode( "\n", $lines ) . "\n" );

WP_CLI::success( sprintf( 'Wrote %d EXPLAIN statements to %s', count( $cases ) * 2 + count( $json_cases ), $out ) );
