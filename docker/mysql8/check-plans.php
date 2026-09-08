<?php
/**
 * EXPLAIN every shape the engine can emit, and assert its plan.
 *
 *   wp eval-file docker/mysql8/check-plans.php
 *
 * Runs against whatever database wpdb points at — the live 5.7 catalogue by
 * default, or the 8.0 container when pointed there. Nothing here ships in the
 * plugin.
 *
 * **Why this replaces a checklist.** The README used to end with five things to
 * look for by eye in a thousand-line diff, and a human comparing two EXPLAIN
 * dumps finds the regression they are looking for and misses the one they are
 * not. Every item on that list is mechanical, so every item is asserted here:
 *
 *   - no `type: ALL` on `l` or `p` — a full scan of the driving tables;
 *   - a chosen index on `l`;
 *   - no `Using temporary` or `Using filesort`;
 *   - a row estimate within a multiple of the scope, so a mis-estimated
 *     selectivity is visible before it becomes a four-minute query.
 *
 * **The plan asserted is the COUNT statement's, not the resolve's**, and the
 * first version of this file got that wrong in a way worth recording: it
 * asserted the resolve plan, which carries `ORDER BY l.product_id`, and an
 * ORDER BY across a join is a temporary table and a filesort by construction.
 * Every one of twenty-one cases duly "failed", including the five that had been
 * signed off as clean months earlier. An assertion that fires on everything says
 * nothing — it is indistinguishable from no assertion at all, and worse, because
 * it looks like coverage. The count is also the statement that matters most:
 * `preview()` renders it 2+N+W times per request, where the resolve runs once.
 *
 * The count is also what gets TIMED, for the same reason and for one more: the
 * first version timed a full resolve, which materialises every matching id, and
 * one case took **forty-six minutes** on the live catalogue before the run
 * finished. That number was a real finding about that shape — but it is not a
 * measurement anybody can afford to take on every run.
 *
 * A failure here is a real finding: the shape is wrong for a real catalogue, and
 * the answer is to change the clause or cut the shape — before the provider
 * contract is public, not after.
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

/** How many times the scope a row estimate may reach before it is a finding. */
const CO_ROWS_TOLERANCE = 4;

/**
 * How long a single count may take before it is a finding.
 *
 * A preview renders its filter 2+N+W times, so a count is on the interactive
 * path and a person is waiting for it. Five seconds is already poor; the number
 * is a ceiling for "this shape is unusable", not a target.
 */
const CO_SLOW_MS = 5000;

/**
 * How long a single count may run before the harness gives up on it.
 *
 * Enforced with MySQL's own MAX_EXECUTION_TIME optimizer hint rather than a PHP
 * timer, so the server stops the work rather than the client stopping to wait
 * for it. Generous — a shape that cannot count in half a minute on this
 * catalogue is not a shape anybody can ship.
 */
const CO_TIMEOUT_MS = 30000;

$builder = require __DIR__ . '/explain-cases.php';

/**
 * A few real term ids for a taxonomy, with a harmless fallback: a plan depends on
 * index statistics, not on whether a literal matches a row.
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

// The scope each estimate is judged against: how many rows the driving table
// holds. An estimate far above it means the optimiser has lost the plot.
$scope = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_meta_lookup" );

printf( "Database: %s   lookup rows: %s   demo meta: %s\n", DB_NAME, number_format( $scope ), $demo ? 'present' : 'ABSENT (using fallback keys)' );
printf( "MySQL: %s\n\n", $wpdb->get_var( 'SELECT VERSION()' ) );

printf( "%-34s %8s %9s %10s  %-24s %s\n", 'case', 'ms', 'matched', 'est.rows', 'problems', 'verdict' );
printf( "%s\n", str_repeat( '-', 110 ) );

$failures = array();
$skipped  = array();

foreach ( $cases as $name => $case ) {
	try {
		$sql = $engine->count_sql( $case['filter'], $case['requirements'] );
	} catch ( \Throwable $e ) {
		$skipped[ $name ] = $e->getMessage();
		printf( "%-34s %9s %9s %-28s %s\n", $name, '-', '-', 'refused', substr( $e->getMessage(), 0, 40 ) );
		continue;
	}

	// Capped, because one bad shape must not hold the whole run hostage. It
	// already did: the first run spent forty-six minutes inside a single case
	// while every other measurement waited behind it. A statement that hits the
	// cap has told us what we needed to know — it is unusable — and the cap is
	// the measurement.
	$capped = preg_replace(
		'/^SELECT /',
		sprintf( 'SELECT /*+ MAX_EXECUTION_TIME(%d) */ ', CO_TIMEOUT_MS ),
		$sql,
		1
	);

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A );

	$wpdb->suppress_errors( true );
	$wpdb->last_error = '';

	// The plan is only half the answer; a shape can plan well and still be slow.
	$start   = microtime( true );
	$result  = $wpdb->get_var( (string) $capped );
	$ms      = (int) round( ( microtime( true ) - $start ) * 1000 );
	$timeout = '' !== (string) $wpdb->last_error;

	$wpdb->suppress_errors( false );
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$matched = $timeout ? -1 : (int) $result;

	$problems = array();
	$rows     = 0;

	foreach ( (array) $plan as $row ) {
		$alias = (string) ( $row['table'] ?? '' );
		$type  = (string) ( $row['type'] ?? '' );
		$key   = $row['key'] ?? null;
		$extra = (string) ( $row['Extra'] ?? '' );
		$rows  = max( $rows, (int) ( $row['rows'] ?? 0 ) );

		if ( in_array( $alias, array( 'l', 'p' ), true ) ) {
			if ( 'ALL' === $type ) {
				$problems[] = "{$alias}:type=ALL";
			}

			if ( null === $key && 'l' === $alias ) {
				$problems[] = 'l:no index';
			}
		}

		// Only on the DRIVING tables. A `Using temporary` on the table inside a
		// derived table is how MySQL reports materialising it, and materialising a
		// DISTINCT derived table is the shape this engine deliberately chose — it
		// is what replaced the semi-join that went from one second to four minutes.
		// Flagging it flagged thirteen cases including every one that had been
		// signed off, which is the same mistake as asserting the resolve's ORDER BY:
		// a rule that fires on the intended design teaches its reader to ignore it.
		// On `l` or `p` it means the whole result set is being buffered, which is a
		// finding.
		if ( ! in_array( $alias, array( 'l', 'p' ), true ) ) {
			continue;
		}

		if ( false !== strpos( $extra, 'Using temporary' ) ) {
			$problems[] = "{$alias}:temporary";
		}

		if ( false !== strpos( $extra, 'Using filesort' ) ) {
			$problems[] = "{$alias}:filesort";
		}
	}

	if ( $scope > 0 && $rows > $scope * CO_ROWS_TOLERANCE ) {
		$problems[] = sprintf( 'rows %sx scope', round( $rows / $scope, 1 ) );
	}

	// A shape can plan perfectly and still be unusable. The count is on the
	// interactive path — a preview renders it 2+N+W times with a person waiting —
	// so how long it takes is as much a property of the shape as its plan.
	if ( $timeout ) {
		$problems[] = sprintf( 'GAVE UP after %ss', round( CO_TIMEOUT_MS / 1000 ) );
	} elseif ( $ms > CO_SLOW_MS ) {
		$problems[] = sprintf( 'count %ss', round( $ms / 1000, 1 ) );
	}

	$ok = array() === $problems;

	if ( ! $ok ) {
		$failures[ $name ] = $problems;
	}

	printf(
		"%-34s %8d %9s %10s  %-24s %s\n",
		$name,
		$ms,
		-1 === $matched ? '-' : number_format( $matched ),
		number_format( $rows ),
		$ok ? '' : implode( ' ', array_slice( $problems, 0, 2 ) ),
		$ok ? 'ok' : 'FAIL'
	);
}

printf( "\n%s\n", str_repeat( '=', 104 ) );

if ( array() !== $skipped ) {
	printf( "%d case(s) refused by the engine:\n", count( $skipped ) );

	foreach ( $skipped as $name => $why ) {
		printf( "  %-32s %s\n", $name, $why );
	}

	printf( "\n" );
}

if ( array() === $failures ) {
	printf( "ALL %d CASES PLAN CLEANLY.\n", count( $cases ) - count( $skipped ) );
	return;
}

printf( "%d CASE(S) FAILED:\n\n", count( $failures ) );

foreach ( $failures as $name => $problems ) {
	printf( "  %-32s %s\n", $name, implode( ', ', $problems ) );
	printf( "      %s\n", $cases[ $name ]['desc'] );
}

printf( "\nA failure is a finding: change the clause, or cut the shape before the contract is public.\n" );
