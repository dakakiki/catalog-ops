<?php
/**
 * Fire one schedule from many processes at once, and see whether exactly one run
 * happens.
 *
 * The write lock, its generation fence and the stale-claim path are the least
 * testable part of this plugin, because nothing in a test suite runs in two
 * processes at the same time. This puts the real thing under real concurrency:
 * open N terminals, run `fire` in each, and they all wait for the same second
 * before calling into the runner — started by hand they would otherwise arrive
 * seconds apart, which the lock wins trivially and which proves nothing.
 *
 * **What should happen.** Exactly ONE operation is created. Every other process
 * either sees an active operation and declines, or loses the lock race inside
 * `Operation_Service::queue()` and reports `blocked`. No schedule is paused —
 * a lock race is not a fault, and `Schedule_Runner::fire()` leaves the schedule
 * due rather than pausing it. Anything else is the finding.
 *
 * **It never touches a real schedule.** `prepare` pauses every active schedule
 * on the site, remembering which ones it paused, and creates a throwaway of its
 * own whose action writes a private meta key rather than a price — the real
 * schedules here adjust `regular_price`, and re-running one of those to test
 * something is how a catalogue gets quietly repriced. `cleanup` puts them all
 * back.
 *
 * Usage, from the WordPress root:
 *
 *   wp eval-file bin/stress-schedules.php prepare     # once
 *   wp eval-file bin/stress-schedules.php fire        # in each terminal
 *   wp eval-file bin/stress-schedules.php check       # afterwards
 *   wp eval-file bin/stress-schedules.php cleanup     # once, when done
 *
 * @package CatalogOps
 */

use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Operations\Operation_Mode;
use CatalogOps\Operations\Recurrence;
use CatalogOps\Operations\Schedule_Runner;
use CatalogOps\Operations\Schedule_Status;
use CatalogOps\Operations\Schedules;
use CatalogOps\Query\Filter;

wp_set_current_user( 1 );

/** Where the run's shared state lives between processes. */
const CO_STRESS_OPTION = 'catalogops_stress_state';

/** How long the processes wait for a common starting second. */
const CO_STRESS_COUNTDOWN = 20;

$co_command = ( $args[0] ?? '' );

$co_container = \CatalogOps\Plugin::instance( CATALOGOPS_FILE )->container();
$co_schedules = $co_container->get( Schedules::class );
$co_runner    = $co_container->get( Schedule_Runner::class );

global $wpdb;

$co_ops_table   = $wpdb->prefix . 'catalogops_operations';
$co_sched_table = $wpdb->prefix . 'catalogops_schedules';

/**
 * Say something with a timestamp fine enough to order two processes by.
 *
 * @param string $line The message.
 */
function co_stress_say( string $line ): void {
	printf( "[%s] %s\n", gmdate( 'H:i:s' ) . substr( sprintf( '%.3f', microtime( true ) ), -4 ), $line );
}

switch ( $co_command ) {

	case 'prepare':
		// Pause everything that could fire on its own while this is running, and
		// remember exactly which ones were paused so cleanup can be precise rather
		// than "set them all active", which would wake a schedule the owner had
		// paused for their own reasons.
		$co_active = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$co_sched_table} WHERE status = %s", Schedule_Status::ACTIVE->value ) ); // phpcs:ignore WordPress.DB

		foreach ( $co_active as $co_id ) {
			$wpdb->update( $co_sched_table, array( 'status' => Schedule_Status::PAUSED->value ), array( 'id' => (int) $co_id ), array( '%s' ), array( '%d' ) );
		}

		co_stress_say( sprintf( 'paused %d real schedule(s): %s', count( $co_active ), implode( ', ', $co_active ) ?: '(none)' ) );

		// A filter that matches a handful of products, and an action that writes a
		// private meta key. Nothing a shop can see changes, so a run that does
		// happen is harmless and a run that happens twice is still harmless.
		$co_filter = Filter::from_array(
			array(
				'relation'   => 'AND',
				'scope'      => 'product',
				'conditions' => array(
					array(
						'field'    => 'sku',
						'operator' => 'contains',
						'value'    => 'COPS-1000',
					),
				),
			)
		);

		$co_actions = Action_Factory::list_from_array(
			array(
				array(
					'type'  => 'set',
					'field' => 'meta:_catalogops_stresstest',
					'value' => (string) time(),
				),
			)
		);

		$co_new = $co_schedules->create(
			'ZZZ lock stress test — delete me',
			$co_filter,
			$co_actions,
			Operation_Mode::SAFE,
			Recurrence::ONCE,
			gmdate( 'Y-m-d H:i:s', time() + 86400 ),
			'',
			1
		);

		update_option(
			CO_STRESS_OPTION,
			array(
				'schedule_id'  => $co_new,
				'paused'       => array_map( 'intval', $co_active ),
				'max_op_before' => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$co_ops_table}" ), // phpcs:ignore WordPress.DB
			),
			false
		);

		co_stress_say( sprintf( 'created throwaway schedule %d (action: meta:_catalogops_stresstest, never a price)', $co_new ) );
		echo "\nNow open as many terminals as you want and run this in each — they\n";
		echo "will all wait for the same second before firing:\n\n";
		echo "  C:\\wamp64\\wpx.cmd eval-file C:\\dev\\catalogops\\bin\\stress-schedules.php fire\n\n";
		printf( "Start them all within %d seconds of each other.\n", CO_STRESS_COUNTDOWN );
		break;

	case 'fire':
		$co_state = get_option( CO_STRESS_OPTION );

		if ( ! is_array( $co_state ) || empty( $co_state['schedule_id'] ) ) {
			co_stress_say( 'no stress state — run `prepare` first.' );
			break;
		}

		// A common starting instant, derived rather than stored, so every process
		// computes the same one without coordinating: the next multiple of the
		// countdown on the wall clock.
		$co_start = (int) ( ceil( time() / CO_STRESS_COUNTDOWN ) * CO_STRESS_COUNTDOWN );

		co_stress_say( sprintf( 'pid %d waiting until %s UTC', getmypid(), gmdate( 'H:i:s', $co_start ) ) );

		// usleep rather than sleep, so the processes converge to the same
		// millisecond instead of the same second.
		while ( microtime( true ) < $co_start ) {
			usleep( 2000 );
		}

		co_stress_say( sprintf( 'pid %d calling run_one(%d)', getmypid(), $co_state['schedule_id'] ) );

		$co_fired = $co_runner->run_one( (int) $co_state['schedule_id'] );

		co_stress_say( sprintf( 'pid %d run_one returned %s', getmypid(), var_export( $co_fired, true ) ) );
		break;

	case 'check':
		$co_state = get_option( CO_STRESS_OPTION );

		if ( ! is_array( $co_state ) ) {
			co_stress_say( 'no stress state — run `prepare` first.' );
			break;
		}

		$co_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, schedule_id, target_count, processed, failed, created_at FROM {$co_ops_table} WHERE id > %d ORDER BY id", (int) $co_state['max_op_before'] ), ARRAY_A ); // phpcs:ignore WordPress.DB

		printf( "\noperations created since prepare: %d\n", count( $co_rows ) );

		foreach ( $co_rows as $co_row ) {
			printf(
				"  op %-4s %-10s schedule=%-4s target=%-4s processed=%-4s failed=%-3s %s\n",
				$co_row['id'],
				$co_row['status'],
				$co_row['schedule_id'],
				$co_row['target_count'],
				$co_row['processed'],
				$co_row['failed'],
				$co_row['created_at']
			);
		}

		printf( "\nVERDICT: %s\n", 1 === count( $co_rows ) ? 'PASS — exactly one run, which is the single-writer promise.' : ( 0 === count( $co_rows ) ? 'nothing fired at all — did the processes overlap?' : 'FAIL — more than one run was created under one lock.' ) );

		// A lock race must not pause a schedule: a machine losing a race is not a
		// fault, and pausing on it would stop a shop's repricing over nothing.
		$co_sched = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, paused_reason FROM {$co_sched_table} WHERE id = %d", (int) $co_state['schedule_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB

		printf(
			"\nthe throwaway schedule: status=%s paused_reason=%s\n",
			$co_sched['status'] ?? '(gone)',
			var_export( $co_sched['paused_reason'] ?? null, true )
		);

		if ( ! empty( $co_sched['paused_reason'] ) ) {
			echo "FINDING: a lock race paused the schedule. It should have been left due.\n";
		}

		// Both halves of the lock: the holder (`{id}:{generation}:{granted}`) and
		// the watch flag a worker re-reads each pulse. A holder left behind after
		// every process has exited is the thing worth seeing here.
		printf(
			"\nlock holder (catalogops_active_operation): %s\nwriter watch (catalogops_writer_active)  : %s\n",
			var_export( get_option( 'catalogops_active_operation', null ), true ),
			var_export( get_option( 'catalogops_writer_active', null ), true )
		);
		break;

	case 'cleanup':
		$co_state = get_option( CO_STRESS_OPTION );

		if ( ! is_array( $co_state ) ) {
			co_stress_say( 'no stress state — nothing to clean.' );
			break;
		}

		$co_rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$co_ops_table} WHERE id > %d", (int) $co_state['max_op_before'] ) ); // phpcs:ignore WordPress.DB

		// Release the lock BEFORE deleting the row that holds it. `acquire()` does
		// steal a hold whose operation is not active, so a dangling holder heals
		// itself — but an operation deleted mid-queue leaves a holder pointing at a
		// row that no longer exists, and "it heals itself" is a bad thing to leave
		// behind on a site somebody is about to test on. Released by id only: the
		// generation belongs to the worker, and this is the caller that knows the
		// operation is going away entirely.
		foreach ( $co_rows as $co_id ) {
			$co_container->get( \CatalogOps\Operations\Lock::class )->release( (int) $co_id );
		}

		foreach ( $co_rows as $co_id ) {
			$wpdb->delete( $wpdb->prefix . 'catalogops_changes', array( 'operation_id' => (int) $co_id ), array( '%d' ) );
			$wpdb->delete( $co_ops_table, array( 'id' => (int) $co_id ), array( '%d' ) );

			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'catalogops_run_chunk', array( (int) $co_id ) );
			}
		}

		$wpdb->delete( $co_sched_table, array( 'id' => (int) $co_state['schedule_id'] ), array( '%d' ) );

		foreach ( (array) ( $co_state['paused'] ?? array() ) as $co_id ) {
			$wpdb->update( $co_sched_table, array( 'status' => Schedule_Status::ACTIVE->value ), array( 'id' => (int) $co_id ), array( '%s' ), array( '%d' ) );
		}

		// The private meta the action wrote. Removed by key, so it cannot reach
		// anything the action did not write.
		$co_meta = $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_catalogops_stresstest'" ); // phpcs:ignore WordPress.DB

		delete_option( CO_STRESS_OPTION );

		co_stress_say( sprintf(
			'removed %d operation(s), the throwaway schedule, %d stress meta row(s); reactivated %s',
			count( $co_rows ),
			(int) $co_meta,
			implode( ', ', (array) ( $co_state['paused'] ?? array() ) ) ?: '(none)'
		) );
		break;

	default:
		echo "Usage: wp eval-file bin/stress-schedules.php prepare|fire|check|cleanup\n";
		break;
}
