<?php
/**
 * Unit tests for the recurrence presets.
 *
 * @package CatalogOps\Tests\Unit\Operations
 */

namespace CatalogOps\Tests\Unit\Operations;

use CatalogOps\Operations\Recurrence;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CatalogOps\Operations\Recurrence
 */
final class RecurrenceTest extends TestCase {

	/**
	 * A UTC moment from a MySQL-style string.
	 *
	 * @param string $mysql GMT datetime.
	 */
	private function moment( string $mysql ): DateTimeImmutable {
		return new DateTimeImmutable( $mysql, new DateTimeZone( 'UTC' ) );
	}

	public function test_once_has_no_next_run(): void {
		$this->assertNull(
			Recurrence::ONCE->next_after( $this->moment( '2026-08-10 12:00:00' ), $this->moment( '2026-08-10 12:00:00' ) )
		);
	}

	public function test_each_interval_advances_by_one_period(): void {
		$from = $this->moment( '2026-08-10 12:00:00' );
		$now  = $from; // Not stale: the very next period.

		$this->assertSame(
			'2026-08-10 13:00:00',
			Recurrence::HOURLY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
		$this->assertSame(
			'2026-08-11 12:00:00',
			Recurrence::DAILY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
		$this->assertSame(
			'2026-08-17 12:00:00',
			Recurrence::WEEKLY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
		$this->assertSame(
			'2026-09-10 12:00:00',
			Recurrence::MONTHLY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_catches_up_past_missed_periods_in_one_jump(): void {
		// A daily schedule last aligned days ago; the next run is the first
		// occurrence strictly after now, not a burst of back-dated runs.
		$from = $this->moment( '2026-08-01 09:00:00' );
		$now  = $this->moment( '2026-08-10 12:00:00' );

		$this->assertSame(
			'2026-08-11 09:00:00',
			Recurrence::DAILY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_next_run_is_strictly_after_from_even_when_now_is_behind(): void {
		$from = $this->moment( '2026-08-10 12:00:00' );
		$now  = $this->moment( '2026-08-10 11:00:00' ); // now precedes from.

		$this->assertSame(
			'2026-08-10 13:00:00',
			Recurrence::HOURLY->next_after( $from, $now )->format( 'Y-m-d H:i:s' )
		);
	}
}
