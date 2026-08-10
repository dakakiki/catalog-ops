<?php
/**
 * How often a schedule fires.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use DateInterval;
use DateTimeImmutable;

/**
 * The recurrence presets a scheduled operation can use (CONTEXT §4). Interval
 * presets, not cron expressions: they cover the agency case ("shave 10% every
 * night") without a cron parser or the support burden of misread expressions —
 * a full cron field can come in v1.1 without changing the storage.
 *
 * `once` is a one-shot schedule that completes after its single run; the others
 * roll forward by a fixed interval.
 */
enum Recurrence: string {
	case ONCE    = 'once';
	case HOURLY  = 'hourly';
	case DAILY   = 'daily';
	case WEEKLY  = 'weekly';
	case MONTHLY = 'monthly';

	/**
	 * The interval this recurrence advances by, or null for a one-shot.
	 */
	public function interval(): ?DateInterval {
		return match ( $this ) {
			self::ONCE    => null,
			self::HOURLY  => new DateInterval( 'PT1H' ),
			self::DAILY   => new DateInterval( 'P1D' ),
			self::WEEKLY  => new DateInterval( 'P7D' ),
			self::MONTHLY => new DateInterval( 'P1M' ),
		};
	}

	/**
	 * The next fire time strictly after $from, rolled forward until it is also
	 * after $now so a supervisor that missed several ticks catches up by one
	 * period rather than firing a burst of back-dated runs. Returns null for a
	 * one-shot, which has no next run.
	 *
	 * @param DateTimeImmutable $from The previous scheduled time to advance from.
	 * @param DateTimeImmutable $now  The current time, to skip past missed periods.
	 */
	public function next_after( DateTimeImmutable $from, DateTimeImmutable $now ): ?DateTimeImmutable {
		$interval = $this->interval();

		if ( null === $interval ) {
			return null;
		}

		$next  = $from->add( $interval );
		$guard = 0;

		// Bounded so a wildly stale schedule cannot loop forever; 100k periods is
		// years even at the hourly cadence, far past any real catch-up.
		while ( $next <= $now && $guard < 100000 ) {
			$next = $next->add( $interval );
			++$guard;
		}

		return $next;
	}
}
