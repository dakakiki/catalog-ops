<?php
/**
 * An immutable snapshot of a row in the schedules table.
 *
 * @package CatalogOps\Operations
 */

namespace CatalogOps\Operations;

use CatalogOps\Operations\Actions\Action;
use CatalogOps\Operations\Actions\Action_Factory;
use CatalogOps\Query\Filter;

/**
 * A read-model of one schedule: the filter + actions template it fires, how
 * often, when it next runs, and a pointer to the last operation it spawned.
 *
 * Like {@see Operation}, the filter and actions are kept as their decoded arrays
 * and rebuilt on demand. Critically, a schedule stores the filter as a template
 * and re-resolves it at each fire (CONTEXT §2, rule 2: the target list is frozen
 * at execution, not at authoring) — so "10% off category X every night" catches
 * products added since the schedule was created.
 */
final class Schedule {

	/**
	 * Build the read-model.
	 *
	 * @param int                              $id           Row id.
	 * @param int                              $user_id      Owner user id.
	 * @param string                           $name         Human label.
	 * @param array<string, mixed>             $filter_data  Decoded filter_json (template).
	 * @param array<int, array<string, mixed>> $actions_data Decoded actions_json.
	 * @param Operation_Mode                   $mode         Write strategy for spawned ops.
	 * @param Recurrence                       $recurrence   How often it fires.
	 * @param Schedule_Status                  $status       Lifecycle state.
	 * @param string                           $next_run     Next fire time (GMT MySQL datetime).
	 * @param string|null                      $last_run     Last fire time, or null.
	 * @param int|null                         $last_op_id   Operation spawned by the last fire, or null.
	 * @param string                           $notify_email Report recipient (may be empty).
	 * @param string                           $created_at   Creation time (GMT MySQL datetime).
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly string $name,
		public readonly array $filter_data,
		public readonly array $actions_data,
		public readonly Operation_Mode $mode,
		public readonly Recurrence $recurrence,
		public readonly Schedule_Status $status,
		public readonly string $next_run,
		public readonly ?string $last_run,
		public readonly ?int $last_op_id,
		public readonly string $notify_email,
		public readonly string $created_at,
	) {}

	/**
	 * Rebuild the filter template from its stored form.
	 */
	public function filter(): Filter {
		return Filter::from_array( $this->filter_data );
	}

	/**
	 * Rebuild the action list from its stored form.
	 *
	 * @return list<Action>
	 */
	public function actions(): array {
		return Action_Factory::list_from_array( $this->actions_data );
	}
}
