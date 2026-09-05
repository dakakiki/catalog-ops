<?php
/**
 * The single-writer lock: one writing operation per site.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

/**
 * Enforces CONTEXT §3's rule that only one operation writes to the catalog at a
 * time. The lock is a single option naming the holding operation. Acquisition
 * steals the lock when the recorded holder is gone or has reached a terminal
 * state — never from a holder whose row still reads active, which is what keeps
 * two writers off one catalogue.
 *
 * This used to claim that a crashed operation therefore could not wedge the site,
 * on the grounds that the watchdog fails the stalled run and the next queue steals
 * its abandoned lock. That was measured false, twice, on 2026-09-04. A process
 * killed mid-chunk leaves its Action Scheduler action claimed and `in-progress`;
 * Action Scheduler allows one concurrent batch, so no further batch runs — and the
 * watchdog is itself an Action Scheduler action, locked behind the door it exists
 * to open. The run stays `running`, this lock stays held, and nothing recovers:
 * forty-eight minutes with wp-admin open, until the queue was run by hand.
 *
 * So the guarantee this class can honestly make is narrower than it claimed: it
 * will not hand the catalogue to a second writer, and it will release to a holder
 * that has been settled. Getting a dead holder *settled* is somebody else's job,
 * and until that somebody can run without the queue, the wedge is real.
 */
final class Lock {

	/**
	 * Option storing the id of the operation currently holding the lock.
	 */
	private const OPTION = 'catalogops_active_operation';

	/**
	 * Option mirroring {@see OPTION}, autoloaded, so a request can ask "is anyone
	 * writing?" for nothing.
	 *
	 * Recovery has to look on ordinary requests, because the queue that would
	 * otherwise carry it is the very thing that breaks. Looking must therefore be
	 * free on the overwhelming majority of requests, when no operation is running at
	 * all — and {@see OPTION} cannot serve: it is written with autoload off, so
	 * reading it is a query, and a query on every page view of every shop to serve a
	 * rare failure is not a trade worth making.
	 *
	 * This one is autoloaded, so it arrives inside the `alloptions` array the request
	 * has already paid for, and the check costs an integer comparison. It holds `0`
	 * rather than being deleted when idle, so the read never falls through to a
	 * `notoptions` miss.
	 */
	private const WATCH_OPTION = 'catalogops_writer_active';

	/**
	 * Operations repository, to test whether the current holder is still alive.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Build the lock.
	 *
	 * @param Operations $operations Operations repository.
	 */
	public function __construct( Operations $operations ) {
		$this->operations = $operations;
	}

	/**
	 * Acquire the lock for an operation. Reentrant for the current holder; steals
	 * a lock whose holder no longer exists or has finished.
	 *
	 * @param int $op_id Operation asking for the lock.
	 * @return bool Whether the lock is now held by this operation.
	 */
	public function acquire( int $op_id ): bool {
		$holder = $this->holder();

		if ( $holder !== $op_id && $holder > 0 ) {
			$existing = $this->operations->find( $holder );

			if ( null !== $existing && $existing->status->is_active() ) {
				return false;
			}
		}

		// A fresh generation on every grant, the re-grant to the same operation
		// included — that case is not a formality, it is recovery handing a run whose
		// process died to a new worker, and it is the one that most needs the old
		// worker fenced out. See {@see generation()}. The grant time rides along as a
		// third field; see {@see granted_at()}.
		update_option( self::OPTION, $op_id . ':' . uniqid( '', true ) . ':' . time(), false );
		update_option( self::WATCH_OPTION, $op_id, true );

		return true;
	}

	/**
	 * Release the lock, but only if this operation holds it — and, when the caller
	 * names one, only the exact hold it was granted or judged.
	 *
	 * The id alone stopped being enough the day recovery learned to re-grant one
	 * operation to a second worker: "operation 31 holds the lock" and "the hold I am
	 * talking about" are no longer the same sentence. A caller that arrives here on
	 * the strength of something it read a moment ago — {@see Recovery}'s housekeeping
	 * is the one that does — would otherwise free whatever hold is there now,
	 * including one granted after it looked.
	 *
	 * The generation is optional because most callers cannot honestly supply one and
	 * do not need to. {@see Operation_Service::cancel()},
	 * {@see Operation_Service::take_over()}, {@see Operation_Service::delete()} and
	 * {@see Watchdog::run()} are not giving back a hold they took: they are taking a
	 * run down, and freeing whatever it holds is the act being asked for. They pass
	 * nothing and get the id-only match they have always had, at the price they have
	 * always paid.
	 *
	 * {@see still_held()} rather than {@see generation()}, for that method's own
	 * reason: the re-grant this is looking for happens in a *different* request, so a
	 * request-cached read cannot see it by construction and the check would pass for
	 * ever while being worthless.
	 *
	 * This is a read and then a delete, not a compare-and-delete, and does not claim
	 * to be: a grant landing between the two is freed anyway. What it removes is the
	 * decision taken on information that was already stale when it was taken, which
	 * is the wide part. Making it atomic means a conditional DELETE against
	 * `option_value`, which goes behind the options API and leaves a persistent
	 * object cache holding a value the database no longer has — the exact failure
	 * {@see still_held()} exists to work around.
	 *
	 * @param int    $op_id      Operation releasing the lock.
	 * @param string $generation The hold the caller was granted or judged, when it
	 *                           has one.
	 */
	public function release( int $op_id, string $generation = '' ): void {
		if ( '' !== $generation && ! $this->still_held( $generation ) ) {
			return;
		}

		if ( $this->holder() === $op_id ) {
			delete_option( self::OPTION );
			update_option( self::WATCH_OPTION, 0, true );
		}
	}

	/**
	 * When the current hold was granted, or 0 when the stored value does not say.
	 *
	 * The third field of the lock value, and it exists for one reader.
	 * {@see Recovery} finds the writer flag pointing at an operation whose row is not
	 * active, and has to decide whether that is a flag which outlived its run or a
	 * hand-off caught in the middle. The row cannot tell it apart, because being
	 * briefly out of date is precisely what the row is doing in that window:
	 * {@see Operation_Service::resume()} takes the lock and only then moves the row
	 * off `failed`, so for those statements a deliberate, live resume reads exactly
	 * like a corpse. The age of the hold is the one fact that separates them, and
	 * until now nothing recorded it.
	 *
	 * It rides in {@see OPTION} because it describes the hold rather than the
	 * operation — a fact whose whole life is one grant — and because here it is free:
	 * the only caller is already inside the branch that reads this option in order to
	 * release it, so the read is served from the request cache. A column would want a
	 * migration for a value that never outlives a request; a second option would be a
	 * second write on every grant and a value free to drift from the hold it
	 * describes. The autoloaded mirror was considered and rejected for the opposite
	 * reason: the two facts would then be torn apart by a death between
	 * {@see acquire()}'s two writes, and every request already parses that one.
	 *
	 * Nothing else is disturbed. {@see holder()}'s leading `(int)` cast still yields
	 * the id, {@see generation()} still compares the stored string whole, and the
	 * value is written once per grant and never edited afterwards, so a worker's
	 * captured copy stays comparable for the life of its chunk. `uniqid( '', true )`
	 * spends a dot and never a colon, so the third field is unambiguous.
	 *
	 * A hold minted by the release before this one has no third field and reads as 0
	 * — "granted long enough ago that nobody is waiting on it", which is true of every
	 * such hold but one, and which leaves it tidied exactly as it is today. Stamping
	 * an unstamped hold with the current time on sight was designed and rejected: it
	 * rewrites the option, and a chunk still running the old code compares the value
	 * whole, so it would surrender work it was entitled to finish — a certain cost to
	 * remove a coincidental one.
	 */
	public function granted_at(): int {
		$parts = explode( ':', $this->generation() );

		return isset( $parts[2] ) ? (int) $parts[2] : 0;
	}

	/**
	 * The operation recovery should be watching, or 0 when nobody is writing.
	 *
	 * The cheap half of the pair: answered from autoloaded options, so the question
	 * can be asked on every request without costing anything. A non-zero answer is
	 * permission to go and look properly, not a diagnosis.
	 */
	public function watching(): int {
		$flag = get_option( self::WATCH_OPTION, null );

		if ( null !== $flag ) {
			return (int) $flag;
		}

		// The option is absent, which happens exactly once per site: the first request
		// after the release that introduced it. A run may already be in flight — and
		// an upgrade landing mid-run is precisely when a process is likely to be
		// interrupted — so the real lock is read this once and the answer kept. Left
		// unhandled, a run in flight at upgrade time would be invisible to recovery
		// for the rest of its life, which is the whole of the failure this exists to
		// end.
		$holder = $this->holder();
		update_option( self::WATCH_OPTION, $holder, true );

		return $holder;
	}

	/**
	 * The operation id currently holding the lock, or 0 when free.
	 *
	 * A leading `(int)` cast over `"31:65f0a1..."` yields 31, so every existing
	 * reader kept working when the generation was added to the stored value.
	 */
	public function holder(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * The full identity of the current hold: the operation id and the generation
	 * that was minted when it was granted.
	 *
	 * The id alone is not enough to fence a worker out, and that is the whole reason
	 * this exists. Recovery hands a run whose process died back to a new worker under
	 * the *same operation id* — so a process that was declared dead but is merely
	 * blocked would wake, read the holder, recognise its own id, and carry on
	 * writing beside the new one. Two writers over one catalogue is bad in general
	 * and specifically ruinous here: an {@see \CatalogOps\Operations\Actions\Adjust}
	 * reads the field it writes, so "add 15" applied twice is not a repeat but a
	 * different number.
	 *
	 * The generation makes the difference visible. A worker captures this when it
	 * starts and stops the moment it no longer matches.
	 */
	public function generation(): string {
		return (string) get_option( self::OPTION, '' );
	}

	/**
	 * Whether a worker still holds the lock it started under.
	 *
	 * Deliberately not `get_option()`. Options are cached for the life of a request,
	 * and the steal this is looking for happens in a *different* request — so a
	 * cached read cannot, by construction, ever see it, and the check would pass for
	 * ever while being worthless. Both cache keys have to go: the value itself, and
	 * `notoptions`, which is where a deleted option is remembered as absent.
	 *
	 * @param string $generation What {@see generation()} returned when the worker started.
	 */
	public function still_held( string $generation ): bool {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return '' !== $generation && $this->generation() === $generation;
	}
}
