<?php
/**
 * Generate EXPLAIN statements for the CatalogOps query engine's real SQL.
 *
 * Run against the live dev site so the statements carry the live table prefix
 * and real term ids:
 *
 *   wp eval-file docker/mysql8/generate-explain-sql.php
 *
 * The output file is piped into the MySQL 8.0 container and into the local 5.7
 * so their plans can be compared (see README.md). For assertions against the
 * database this is run on, use `check-plans.php` instead — the two share one
 * case list so they cannot drift.
 *
 * The statements come from {@see Query_Engine::resolve_sql()} and
 * {@see Query_Engine::count_sql()}, so they are byte-for-byte what the engine
 * runs. This used to reach the private `select()` through reflection; that
 * worked, and it made passing requirements awkward enough that the harness never
 * did — so the semi-joins Write_Rules contributes at preview and freeze went
 * unmeasured for the whole life of the tool.
 *
 * @package CatalogOps\Docker
 */

use CatalogOps\Licensing\License;
use CatalogOps\Query\Fields\Filter_Providers;
use CatalogOps\Query\Query_Engine;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run through wp eval-file.\n" );
	exit( 1 );
}

if ( ! class_exists( Query_Engine::class ) ) {
	fwrite( STDERR, "CatalogOps is not loaded — activate the plugin first.\n" );
	exit( 1 );
}

global $wpdb;

$builder = require __DIR__ . '/explain-cases.php';

/**
 * A few real term ids for a taxonomy, with a harmless fallback.
 *
 * @param string $taxonomy Taxonomy name.
 * @param int    $limit    How many.
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

$tt_ids = array_map(
	'intval',
	$wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} ORDER BY count DESC LIMIT 3" )
);

$demo = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", 'co_supplier' )
) > 0;

$cases = $builder(
	$wpdb,
	$term_ids( 'product_cat' ),
	$term_ids( 'pa_color' ),
	$term_ids( 'pa_size' ),
	array() === $tt_ids ? array( 1, 2, 3 ) : $tt_ids,
	$demo
);

$engine = new Query_Engine( $wpdb, new Filter_Providers( License::unlimited(), new CatalogOps_Explain_Provider( $demo ) ) );

$lines   = array();
$lines[] = '-- CatalogOps query-engine EXPLAIN suite — GENERATED, do not edit by hand.';
$lines[] = '-- Regenerate: wp eval-file docker/mysql8/generate-explain-sql.php';
$lines[] = '-- Table prefix: ' . $wpdb->prefix;
$lines[] = '-- Demo ACF meta: ' . ( $demo ? 'present' : 'absent, fallback keys in use' );
$lines[] = '';

$written = 0;
$refused = array();

foreach ( $cases as $name => $case ) {
	try {
		$resolve = $engine->resolve_sql( $case['filter'], $case['requirements'] );
		$count   = $engine->count_sql( $case['filter'], $case['requirements'] );
	} catch ( \Throwable $e ) {
		// A case the engine refuses is not a generator failure — it is the engine
		// doing its job, and it is recorded so the suite does not look complete
		// while silently covering less than it lists.
		$refused[ $name ] = $e->getMessage();

		$lines[] = "-- ===== {$name}: REFUSED BY THE ENGINE =====";
		$lines[] = '-- ' . $case['desc'];
		$lines[] = '-- ' . $e->getMessage();
		$lines[] = '';
		continue;
	}

	$lines[] = "-- ===== {$name} (resolve) =====";
	$lines[] = "-- {$case['desc']}";

	if ( array() !== $case['requirements'] ) {
		$lines[] = '-- WITH ' . count( $case['requirements'] ) . ' applicability requirement(s)';
	}

	$lines[] = 'EXPLAIN ' . $resolve . ';';
	$lines[] = '';
	$lines[] = "-- ===== {$name} (count) =====";
	$lines[] = 'EXPLAIN ' . $count . ';';
	$lines[] = '';

	++$written;
}

// JSON EXPLAIN (with cost estimates) for the paths where an 8.0 optimizer change
// is most likely to bite: the stacked shapes and the two unindexed probes.
$json_cases = array(
	'core_combined_with_requirements',
	'shape_or_stack',
	'shape_serialized_list',
	'shape_repeater_rows',
	'shape_two_module_fields',
);

$lines[] = '-- ===================================================================';
$lines[] = '-- EXPLAIN FORMAT=JSON (cost estimates) for the heaviest paths';
$lines[] = '-- ===================================================================';
$lines[] = '';

foreach ( $json_cases as $name ) {
	if ( ! isset( $cases[ $name ] ) || isset( $refused[ $name ] ) ) {
		continue;
	}

	$lines[] = "-- ===== {$name} (resolve, JSON) =====";
	$lines[] = 'EXPLAIN FORMAT=JSON ' . $engine->resolve_sql( $cases[ $name ]['filter'], $cases[ $name ]['requirements'] ) . ';';
	$lines[] = '';
}

$out = __DIR__ . '/explain-queries.generated.sql';
file_put_contents( $out, implode( "\n", $lines ) . "\n" );

WP_CLI::success( sprintf( 'Wrote %d cases (%d statements) to %s', $written, $written * 2, $out ) );

if ( array() !== $refused ) {
	WP_CLI::warning( sprintf( '%d case(s) refused by the engine and recorded as such:', count( $refused ) ) );

	foreach ( $refused as $name => $why ) {
		WP_CLI::log( sprintf( '  %-32s %s', $name, $why ) );
	}
}
