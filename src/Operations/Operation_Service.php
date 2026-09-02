<?php
/**
 * Orchestrates the operation pipeline: create, queue, cancel.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Operations\Actions\Formula;
use CatalogOps\Operations\Fields\Field_Providers;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Engine;
use InvalidArgumentException;
use WC_Product;

/**
 * The front of the pipeline (CONTEXT §2): Filter → (Preview) → Snapshot →
 * Execution. `create()` records a draft; `queue()` performs the one irreversible
 * decision that everything downstream depends on — it resolves the filter
 * exactly once, freezes the resulting ids into the changes table, and hands off
 * to Action Scheduler. From that point execution reads only the frozen list, so
 * changing a field the filter matched on cannot make targets disappear
 * mid-operation (CONTEXT §2).
 *
 * Queueing takes the single-writer lock; the runner releases it when the
 * operation settles.
 */
final class Operation_Service {

	/**
	 * Default number of objects in the first chunk, before adaptation kicks in.
	 * Kept conservative so the very first request stays well under a 30s limit on
	 * an average shared host (CONTEXT §6); the runner grows it when chunks run
	 * fast. Measured worst case at 200/chunk on the pessimized dev box was ~16s,
	 * so 100 leaves comfortable margin for slower hosts.
	 */
	public const DEFAULT_BATCH = 100;

	/**
	 * Query engine (filter → ids).
	 *
	 * @var Query_Engine
	 */
	private Query_Engine $engine;

	/**
	 * Operations repository.
	 *
	 * @var Operations
	 */
	private Operations $operations;

	/**
	 * Changes repository.
	 *
	 * @var Changes
	 */
	private Changes $changes;

	/**
	 * Field provider registry.
	 *
	 * @var Field_Providers
	 */
	private Field_Providers $providers;

	/**
	 * Single-writer lock.
	 *
	 * @var Lock
	 */
	private Lock $lock;

	/**
	 * Scheduler for chunk hand-off and cancellation.
	 *
	 * @var Operation_Scheduler
	 */
	private Operation_Scheduler $scheduler;

	/**
	 * Plan gating (free-tier object cap, undo/formula availability).
	 *
	 * @var License
	 */
	private License $license;

	/**
	 * The rules deciding which objects a change can actually be written to.
	 *
	 * @var Write_Rules
	 */
	private Write_Rules $rules;

	/**
	 * Works out what an action would write — the same one the runner uses, so the
	 * preview's sample cannot show a value the run would not produce.
	 *
	 * @var Evaluator
	 */
	private Evaluator $evaluator;

	/**
	 * How many objects the preview shows worked out in full. Ten is a sample, not
	 * a set: enough to see the shape of the change, few enough to load quickly and
	 * to read without scrolling. The search covers "but I want to see that one".
	 */
	private const SAMPLE_SIZE = 10;

	/**
	 * Build the service.
	 *
	 * @param Query_Engine        $engine     Query engine.
	 * @param Operations          $operations Operations repository.
	 * @param Changes             $changes    Changes repository.
	 * @param Field_Providers     $providers  Field provider registry.
	 * @param Lock                $lock       Single-writer lock.
	 * @param Operation_Scheduler $scheduler Scheduler for chunk hand-off/cancel.
	 * @param License|null        $license    Plan gating; defaults to unlimited
	 *                                        (unlicensed development and tests).
	 * @param Write_Rules|null    $rules      Applicability rules; the default set is
	 *                                        stateless, so it is built when omitted.
	 */
	public function __construct(
		Query_Engine $engine,
		Operations $operations,
		Changes $changes,
		Field_Providers $providers,
		Lock $lock,
		Operation_Scheduler $scheduler,
		?License $license = null,
		?Write_Rules $rules = null
	) {
		$this->engine     = $engine;
		$this->operations = $operations;
		$this->changes    = $changes;
		$this->providers  = $providers;
		$this->lock       = $lock;
		$this->scheduler  = $scheduler;
		$this->license    = $license ?? License::unlimited();
		$this->rules      = $rules ?? new Write_Rules();
		$this->evaluator  = new Evaluator( $providers, $this->rules );
	}

	/**
	 * Record a draft operation.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param Operation_Mode                          $mode    Write strategy.
	 * @param Operation_Source                        $source  Origin.
	 * @param int                                     $user_id Owner user id.
	 * @return int The new operation id.
	 *
	 * @throws InvalidArgumentException When an action targets a field no provider handles.
	 */
	public function create(
		Filter $filter,
		array $actions,
		Operation_Mode $mode,
		Operation_Source $source,
		int $user_id
	): int {
		$this->assert_fields_supported( $actions );
		$this->assert_formulas_allowed( $actions );
		$this->assert_values_writable( $actions );

		return $this->operations->create( $filter, $actions, $mode, $source, $user_id );
	}

	/**
	 * Dry-run a filter and actions without writing (CONTEXT §2, the Preview step):
	 * how many objects the filter matches, of those how many the edit will actually
	 * change, and — for the rest — exactly why not.
	 *
	 * An object is omitted when the edit cannot be written to it: it lacks a field
	 * the change reads (the formula engine skips on any empty or non-numeric input,
	 * never coercing to zero), or WooCommerce would refuse the value on save and
	 * keep what was there. Each of those is a {@see Write_Rules} requirement,
	 * enforced identically here and at {@see queue()}, which is what makes the
	 * previewed count equal what execution applies — and what an undo reverts.
	 *
	 * The per-reason counts partition the omitted total exactly: each requirement is
	 * added to the previous ones, so an object is attributed to the first rule it
	 * fails rather than to every rule it fails. They therefore always sum to
	 * `omitted`, whatever the overlap between rules.
	 *
	 * Warnings are the other direction — objects the change applies to perfectly
	 * well but damages in passing, such as the sale prices a lower regular price
	 * wipes. They are counted, never subtracted.
	 *
	 * The counts are the promise; the sample shows it. A percentage or a formula is
	 * invisible in a number — `roundto( cost * 1.35, 0.99 )` only becomes checkable
	 * when someone can see 10.00 turn into 13.86 — so the preview also works out,
	 * in full, what would happen to the first few objects it would change. A SKU
	 * narrows the sample to one product without touching the counts, which describe
	 * the whole edit either way.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param string                                  $sku     Narrow the sample to
	 *                                                         SKUs containing this.
	 * @return array{matched: int, applicable: int, omitted: int, omitted_by: list<array{reason: string, count: int}>, warnings: list<array{code: string, count: int}>, sample: list<array{id: int, changes: list<array<string, mixed>>}>}
	 *
	 * @throws InvalidArgumentException When an action targets an unsupported field.
	 */
	public function preview( Filter $filter, array $actions, string $sku = '' ): array {
		$this->assert_fields_supported( $actions );
		$this->assert_values_writable( $actions );

		$matched      = $this->engine->count( $filter );
		$requirements = $this->rules->requirements( $actions );

		$applicable = $matched;
		$omitted_by = array();
		$applied    = array();

		// Add one requirement at a time: the drop in the count is precisely the
		// objects this rule is the first to exclude.
		foreach ( $requirements as $requirement ) {
			$applied[]  = $requirement;
			$remaining  = 0 === $applicable ? 0 : $this->engine->count( $filter, $applied );
			$difference = $applicable - $remaining;
			$applicable = $remaining;

			if ( $difference > 0 ) {
				$omitted_by[] = array(
					'reason' => $requirement->reason(),
					'count'  => $difference,
				);
			}
		}

		return array(
			'matched'    => $matched,
			'applicable' => $applicable,
			'omitted'    => $matched - $applicable,
			'omitted_by' => $omitted_by,
			'warnings'   => $this->count_warnings( $filter, $actions, $matched ),
			'sample'     => $this->sample( $filter, $actions, $applied, $sku ),
		);
	}

	/**
	 * Work out in full what the change would do to the first few objects it would
	 * change — or to one named product, when a SKU is given.
	 *
	 * Drawn from the applicable set, not the matched one: every row shown is a row
	 * that would actually be written, so the sample cannot promise a change the
	 * operation would then skip. The derivation is the runner's own
	 * ({@see Evaluator}), so what is shown here is what would be written.
	 *
	 * @param Filter                                       $filter       Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[]      $actions      Actions to apply.
	 * @param \CatalogOps\Query\Requirements\Requirement[] $requirements The applicability rules.
	 * @param string                                       $sku          SKU fragment, or ''.
	 * @return list<array{id: int, changes: list<array<string, mixed>>}>
	 */
	private function sample( Filter $filter, array $actions, array $requirements, string $sku ): array {
		$target = '' === trim( $sku )
			? $filter
			: $filter->with( new Condition( 'sku', Operator::CONTAINS, trim( $sku ) ) );

		$rows = array();

		foreach ( $this->engine->resolve( $target, $requirements, self::SAMPLE_SIZE ) as $object_id ) {
			$product = wc_get_product( $object_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$changes = array();

			foreach ( $this->evaluator->derive( $product, $actions ) as $derived ) {
				$changes[] = array(
					'field'  => $derived->field,
					'old'    => Values::to_string( $derived->old_value ),
					'new'    => $derived->writes() ? Values::to_string( $derived->new_value ) : null,
					'reason' => $derived->writes() ? null : $derived->reason->value,
				);
			}

			$rows[] = array(
				'id'      => (int) $object_id,
				'changes' => $changes,
			);
		}

		return $rows;
	}

	/**
	 * Count the objects each of the actions' collateral warnings applies to,
	 * dropping the ones that affect nothing so the UI only ever shows live numbers.
	 *
	 * @param Filter                                  $filter  Target filter.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to apply.
	 * @param int                                     $matched Objects the filter matched.
	 * @return list<array{code: string, count: int}>
	 */
	private function count_warnings( Filter $filter, array $actions, int $matched ): array {
		if ( 0 === $matched ) {
			return array();
		}

		$warnings = array();

		foreach ( $this->rules->warnings( $actions ) as $warning ) {
			$count = $this->engine->count( $filter, $warning['predicates'] );

			if ( $count > 0 ) {
				$warnings[] = array(
					'code'  => $warning['code'],
					'count' => $count,
				);
			}
		}

		return $warnings;
	}

	/**
	 * Record an undo of an earlier operation as a draft (CONTEXT §2: undo is an
	 * operation, not a function — the inverse enters the same pipeline, has a
	 * preview, and is itself undoable). It carries no filter or actions; its
	 * targets are the parent's recorded deltas, frozen at {@see queue()} time. The
	 * conflict policy the user chose is persisted on it for the async run.
	 *
	 * @param int             $parent_op_id The operation to undo.
	 * @param Conflict_Policy $policy       How to resolve drifted objects.
	 * @param int             $user_id      Owner user id.
	 * @return int The new undo operation id.
	 *
	 * @throws InvalidArgumentException When the parent is missing or still active.
	 * @throws License_Limited          When undo is used without a paid plan.
	 */
	public function undo( int $parent_op_id, Conflict_Policy $policy, int $user_id ): int {
		if ( ! $this->license->can_undo() ) {
			throw new License_Limited( 'Undo is a paid-plan feature.' );
		}

		$parent = $this->operations->find( $parent_op_id );

		if ( null === $parent ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		if ( $parent->status->is_active() ) {
			throw new InvalidArgumentException( 'A running operation cannot be undone; cancel it first.' );
		}

		return $this->operations->create(
			new Filter(),
			array(),
			$parent->mode,
			Operation_Source::UNDO,
			$user_id,
			$parent_op_id,
			$policy
		);
	}

	/**
	 * Preview an undo without writing: the total number of recorded changes that
	 * would be reverted, and a sample showing, per object, whether it would revert
	 * or be skipped as drift (CONTEXT §3). The exact skipped count is not computed
	 * here — that would mean reading every target object, which the architecture
	 * reserves for execution; the sample conveys the shape and the run reports the
	 * exact figure.
	 *
	 * @param int             $parent_op_id The operation to undo.
	 * @param Conflict_Policy $policy       Policy to reflect in the sample's action.
	 * @param int             $limit        Maximum sample rows.
	 * @return array{parent_op_id: int, total: int, conflict_policy: string, sample: list<array{id: int, field: string, current: ?string, restore_to: ?string, drift: bool, action: string}>}
	 *
	 * @throws InvalidArgumentException When the parent operation is missing.
	 * @throws License_Limited          When undo is used without a paid plan.
	 */
	public function preview_undo( int $parent_op_id, Conflict_Policy $policy, int $limit = 20 ): array {
		if ( ! $this->license->can_undo() ) {
			throw new License_Limited( 'Undo is a paid-plan feature.' );
		}

		$parent = $this->operations->find( $parent_op_id );

		if ( null === $parent ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		$total  = $this->changes->counts( $parent_op_id )['applied'];
		$sample = array();

		foreach ( $this->changes->applied_sample( $parent_op_id, max( 0, $limit ) ) as $row ) {
			$resolved = $this->providers->for_storage( $row->field_type, $row->field_key );
			$product  = wc_get_product( $row->object_id );

			$current = null;
			if ( null !== $resolved && $product instanceof \WC_Product ) {
				$current = Values::to_string( $resolved['provider']->read( $product, $resolved['key'] ) );
			}

			$drift = ! Values::equal( $current, $row->new_value );

			$sample[] = array(
				'id'         => $row->object_id,
				'field'      => null === $resolved ? $row->field_key : $resolved['key'],
				'current'    => $current,
				'restore_to' => $row->old_value,
				'drift'      => $drift,
				'action'     => ( $drift && Conflict_Policy::SKIP === $policy ) ? 'skip' : 'revert',
			);
		}

		return array(
			'parent_op_id'    => $parent_op_id,
			'total'           => $total,
			'conflict_policy' => $policy->value,
			'sample'          => $sample,
		);
	}

	/**
	 * Freeze an operation's targets and hand it to the scheduler. For a normal
	 * operation this resolves the filter exactly once and seeds one row per
	 * (object, field); for an undo it copies the parent's applied deltas as the
	 * frozen target list (CONTEXT §2). From here both drive through the identical
	 * chunked pipeline.
	 *
	 * @param int $op_id Operation id.
	 *
	 * @throws InvalidArgumentException When the operation is missing or not a draft.
	 * @throws Operation_Blocked        When another operation holds the write-lock.
	 */
	public function queue( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		if ( Operation_Status::DRAFT !== $operation->status ) {
			throw new InvalidArgumentException( 'Only a draft operation can be queued.' );
		}

		if ( ! $this->lock->acquire( $op_id ) ) {
			throw new Operation_Blocked( 'Another operation is already writing to this catalog.' );
		}

		// The lock is held across freezing and handed to the async runner on
		// success. The finally releases it on every other exit — the settle-now
		// path (nothing to do) and any thrown failure, such as a free-tier
		// object-cap breach in freeze_edit() — so the catalog is never left
		// wedged against future operations.
		$handed_off = false;

		try {
			$target = $operation->is_undo()
				? $this->freeze_undo( $operation )
				: $this->freeze_edit( $op_id, $operation );

			if ( 0 === $target ) {
				// Nothing to do: settle immediately (the finally frees the lock).
				$this->operations->set_status( $op_id, Operation_Status::COMPLETED, true );
				return;
			}

			$this->operations->set_target_count( $op_id, $target );
			$this->operations->set_batch_size( $op_id, self::DEFAULT_BATCH );
			$this->operations->set_status( $op_id, Operation_Status::QUEUED );
			$this->operations->touch( $op_id );

			$this->scheduler->enqueue_chunk( $op_id, self::DEFAULT_BATCH );

			// Enqueueing does not start the work. Ask the queue to begin now, so the
			// user watches a bar move rather than a queued operation sitting still
			// until some unrelated request wakes the scheduler. Best-effort: the run
			// happens either way, this only decides how soon.
			$this->scheduler->kick();

			$handed_off = true;
		} finally {
			if ( ! $handed_off ) {
				$this->lock->release( $op_id );
			}
		}
	}

	/**
	 * Freeze a normal operation's targets: resolve the filter once and seed one
	 * pending row per (object, field).
	 *
	 * @param int       $op_id     Operation id.
	 * @param Operation $operation The operation.
	 * @return int Number of target objects frozen.
	 *
	 * @throws License_Limited When the target count exceeds the free-tier cap.
	 */
	private function freeze_edit( int $op_id, Operation $operation ): int {
		$actions = $operation->actions();
		$this->assert_fields_supported( $actions );

		$filter = $operation->filter();

		// The one-and-only filter resolution (CONTEXT §2), narrowed to the objects
		// the edit can actually change: those carrying every field it reads, and
		// those whose new value WooCommerce will keep rather than override on save.
		// An object failing either would be skipped at execution, so leaving it out
		// here makes the frozen target count equal what is applied — and what an
		// undo later reverts — so progress never disagrees with the outcome. These
		// are the same requirements {@see preview()} counted, so the number the user
		// was shown is the number that runs.
		$ids = $this->engine->resolve( $filter, $this->rules->requirements( $actions ) );

		if ( array() === $ids ) {
			return 0;
		}

		// Enforce the free-tier object cap before seeding, so a free site cannot
		// stage a 68k-row operation it is not licensed to run (CONTEXT §5). The
		// count is known from the single resolution above — no extra query.
		$max = $this->license->max_objects_per_op();

		if ( count( $ids ) > $max ) {
			throw new License_Limited(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
				sprintf( 'This operation targets %1$d objects; the free plan is limited to %2$d per operation.', count( $ids ), $max )
			);
		}

		$this->changes->seed( $op_id, $this->seed_rows( $ids, $actions, $filter->scope()->value ) );

		return count( $ids );
	}

	/**
	 * Freeze an undo's targets: copy the parent operation's applied deltas as the
	 * frozen revert list.
	 *
	 * @param Operation $operation The undo operation.
	 * @return int Number of deltas to revert.
	 */
	private function freeze_undo( Operation $operation ): int {
		return $this->changes->seed_from_parent( $operation->id, (int) $operation->parent_op_id );
	}

	/**
	 * Remove an operation from the history, along with the deltas it recorded.
	 *
	 * This is the one deletion a user performs by hand, and it is not reversible:
	 * the recorded old → new values are what undo replays, so once they are gone
	 * the operation can never be rolled back. It is the same loss the retention
	 * window causes on its own schedule — the difference is that here somebody
	 * chose it, which is why the UI asks first and this method does not.
	 *
	 * A running operation cannot be deleted; deleting the row mid-write would
	 * leave its remaining chunks pointing at nothing. Cancel it first, then delete
	 * the paused result.
	 *
	 * @param int $op_id Operation id.
	 *
	 * @throws InvalidArgumentException When the operation does not exist.
	 * @throws Operation_Blocked        When it is still queued or running.
	 */
	public function delete( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation ) {
			throw new InvalidArgumentException( 'Operation not found.' );
		}

		if ( $operation->status->is_active() ) {
			throw new Operation_Blocked( 'A running operation cannot be deleted; cancel it first.' );
		}

		// The deltas first: an operations row with no changes is a harmless stub,
		// whereas changes with no operation would be unreachable rows nothing ever
		// cleans up.
		$this->changes->delete_for_operation( $op_id );

		// Undos spawned from this operation outlive it; drop the link rather than
		// leave them pointing at a missing parent.
		$this->operations->detach_children( $op_id );

		// Belt and braces. Lock::acquire() already steals a lock whose holder has
		// vanished, so this cannot wedge the catalog either way — but leaving a
		// stale holder id behind would make the next acquisition look like a theft
		// in the logs rather than a clean take.
		$this->lock->release( $op_id );

		$this->operations->delete( $op_id );
	}

	/**
	 * Cancel a running or queued operation: stop scheduling, pause it, and free
	 * the lock. Already-applied changes remain (undo is a separate M3 operation).
	 *
	 * @param int $op_id Operation id.
	 */
	public function cancel( int $op_id ): void {
		$operation = $this->operations->find( $op_id );

		if ( null === $operation || ! $operation->status->is_active() ) {
			return;
		}

		$this->scheduler->cancel_operation( $op_id );
		$this->operations->set_status( $op_id, Operation_Status::PAUSED );
		$this->lock->release( $op_id );
	}

	/**
	 * Build the cross-product of target ids and action fields as seed rows. The
	 * object_type is stamped from the filter's scope, so a variation operation's
	 * deltas record `variation` — which is what undo and the audit log read back,
	 * and what tells the runner it is loading a variation (CONTEXT §4).
	 *
	 * @param int[]                                   $ids         Frozen target ids.
	 * @param \CatalogOps\Operations\Actions\Action[] $actions     Actions to apply.
	 * @param string                                  $object_type product|variation.
	 * @return array<int, array{object_type: string, object_id: int, field_type: string, field_key: string}>
	 */
	private function seed_rows( array $ids, array $actions, string $object_type ): array {
		$specs = array();

		foreach ( $actions as $action ) {
			$provider = $this->providers->for( $action->field() );

			if ( null === $provider ) {
				continue;
			}

			$specs[] = array(
				'field_type' => $provider->field_type( $action->field() )->value,
				'field_key'  => $provider->storage_key( $action->field() ),
			);
		}

		$rows = array();

		foreach ( $ids as $object_id ) {
			foreach ( $specs as $spec ) {
				$rows[] = array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'field_type'  => $spec['field_type'],
					'field_key'   => $spec['field_key'],
				);
			}
		}

		return $rows;
	}

	/**
	 * Fail fast if any action targets a field no provider can write.
	 *
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to check.
	 *
	 * @throws InvalidArgumentException When a field is unsupported.
	 */
	private function assert_fields_supported( array $actions ): void {
		foreach ( $actions as $action ) {
			if ( ! $this->providers->handles( $action->field() ) ) {
				throw new InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer/UI-facing message, sanitized at the REST boundary.
					sprintf( 'No provider handles the field "%s".', $action->field() )
				);
			}
		}
	}

	/**
	 * Fail fast if an action writes a value the field cannot hold whatever object
	 * it lands on — today, a negative price.
	 *
	 * Only actions with a fixed result are judged here: a literal `Set to -5`, and
	 * a formula carrying no variables (`0 - 10`), both of which are that value for
	 * every object in the catalogue. Refusing them at the boundary means the user
	 * is told when they press Preview or Apply, rather than watching an operation
	 * run to completion having changed nothing.
	 *
	 * A formula that reads a field cannot be judged here — `regular_price - 10` is
	 * a fine edit that happens to go negative on cheap products — so it is left to
	 * {@see Apply_Plan}, which drops those objects one at a time with a reason.
	 *
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to check.
	 *
	 * @throws InvalidArgumentException When an action's fixed value is refused.
	 */
	private function assert_values_writable( array $actions ): void {
		foreach ( $actions as $action ) {
			if ( array() !== $action->reads() ) {
				// Depends on the object; only the plan can answer it.
				continue;
			}

			// The resolver takes no parameter because it answers nothing: an action
			// that reads a field was already skipped above, so nothing asks.
			$value = $action->apply( null, static fn(): mixed => null );

			if ( ! $this->rules->refuses( $action->field(), $value ) ) {
				continue;
			}

			$message = sprintf(
				'A price cannot be negative: this would set %1$s to %2$s.',
				$action->field(),
				Values::to_string( $value )
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
			throw new InvalidArgumentException( $message );
		}
	}

	/**
	 * Fail fast if a formula action is used without a paid plan (CONTEXT §5): the
	 * free tier gets Set_Value edits, not computed formulas.
	 *
	 * @param \CatalogOps\Operations\Actions\Action[] $actions Actions to check.
	 *
	 * @throws License_Limited When a formula action is used on the free tier.
	 */
	private function assert_formulas_allowed( array $actions ): void {
		if ( $this->license->can_use_formulas() ) {
			return;
		}

		foreach ( $actions as $action ) {
			if ( $action instanceof Formula ) {
				throw new License_Limited( 'Formulas are a paid-plan feature.' );
			}
		}
	}
}
