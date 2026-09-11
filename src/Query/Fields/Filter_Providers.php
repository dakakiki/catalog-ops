<?php
/**
 * Routes a filter key to the provider that owns it, and enforces the module gate.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Licensing\License;
use CatalogOps\Licensing\License_Limited;
use CatalogOps\Query\Condition;
use CatalogOps\Query\Filter;
use CatalogOps\Query\Filter_Field_Unavailable;
use CatalogOps\Query\Filter_Fields;
use CatalogOps\Query\Query_Scope;

/**
 * The single choke point every path shares — the results table, the previewed count,
 * the incremental requirement counts, the warning counts, the sample, the
 * other-scope hint, and the one freezing resolve — which is why the licence gate
 * lives here and not in a REST permission callback.
 *
 * {@see \CatalogOps\Operations\Schedule_Runner} rehydrates a stored filter and queues
 * it on a cron tick without touching a controller, and
 * {@see \CatalogOps\Rest\Query_Controller} is not even constructed with a License. A
 * REST-only gate looks correct in every manual test — the UI hides the control, the
 * endpoint answers 402 — and is bypassed entirely by the first unattended run under a
 * lapsed licence.
 *
 * **A caveat about the gate as it stands.** {@see License::has_module()} accepts a
 * module name and, today, ignores it: every module rides the single Studio boundary.
 * Its docblock says so, and this class calls it with the real name so that when
 * per-module gating arrives nothing here changes. Until then, do not read a call to
 * `has_module( 'acf' )` as evidence that ACF is gated separately from WPML — it is
 * not, and a test asserting that it is would pass for the wrong reason.
 */
final class Filter_Providers {

	/**
	 * Registered providers, in resolution order.
	 *
	 * @var list<Filter_Provider>
	 */
	private array $providers;

	/**
	 * Build the registry.
	 *
	 * @param License         $license   The resolved licence.
	 * @param Filter_Provider ...$providers Providers, tried in order.
	 */
	public function __construct( private readonly License $license, Filter_Provider ...$providers ) {
		$this->providers = array_values( $providers );
	}

	/**
	 * The provider owning a key, or null when none does.
	 *
	 * @param string $key Filter key.
	 */
	public function for( string $key ): ?Filter_Provider {
		if ( $this->is_core_key( $key ) ) {
			return null;
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->handles_filter( $key ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Whether any provider is registered at all.
	 *
	 * A constant-time question, and deliberately a different one from "are there
	 * any fields": answering that would mean asking every provider to discover its
	 * site's fields, which is a database read, on every admin page load, to decide
	 * whether to draw a placeholder.
	 *
	 * The admin app uses it to know at first paint whether a module section is
	 * coming, so a site with no modules never shows one and takes it away again.
	 * A site whose only module happens to register nothing — ACF installed with no
	 * field group on products — shows the placeholder briefly and then nothing,
	 * which is the one case this trades away and the rarer one.
	 */
	public function has_providers(): bool {
		return array() !== $this->providers;
	}

	/**
	 * Every filterable field across all providers, for the filter UI.
	 *
	 * Fields whose module the licence does not permit are still listed and flagged
	 * unavailable, so the UI can show them locked rather than silently dropping a
	 * field a saved filter already uses — a dropped field is how a user ends up
	 * looking at a filter that no longer says what they wrote.
	 *
	 * @param string|null $language Language to label the fields in, or null for
	 *                              however they are stored. Only labels vary with
	 *                              it — a field key is persisted in filter_json and
	 *                              means one thing in every language.
	 * @return list<array{field: Filter_Field, module: string, label: string, available: bool}>
	 */
	public function all_fields( ?string $language = null ): array {
		$fields = array();

		foreach ( $this->providers as $provider ) {
			$module    = $provider->module();
			$available = '' === $module || $this->license->has_module( $module );
			$label     = $provider->label();

			foreach ( $provider->filter_fields( $language ) as $field ) {
				$fields[] = array(
					'field'     => $field,
					'module'    => $module,
					'label'     => $label,
					'available' => $available,
				);
			}
		}

		return $fields;
	}

	/**
	 * Check every condition in a filter before any SQL is built, so a refusal names
	 * the field at the operation boundary rather than mid-statement.
	 *
	 * Core keys are skipped: the engine answers them itself.
	 *
	 * @param Filter $filter The filter to check.
	 *
	 * @throws Filter_Field_Unavailable When a field is unknown, dropped, out of scope,
	 *                                  or asked an operator it does not declare.
	 * @throws License_Limited          When the owning module is not licensed.
	 */
	public function assert_supported( Filter $filter ): void {
		foreach ( $filter->conditions() as $condition ) {
			if ( $this->is_core_key( $condition->field ) ) {
				continue;
			}

			$this->field_for( $condition, $filter->scope() );
		}
	}

	/**
	 * Resolve a condition to the {@see Filter_Field} that may answer it, refusing
	 * with a named field for every reason it cannot.
	 *
	 * The order of the checks is the order of the reader's questions: is there a
	 * provider, may this site use it, does it still offer the field, does the field
	 * mean anything in this scope, and can it answer this comparison. Each refusal
	 * names the field, because "your filter is invalid" is not a message anybody can
	 * act on.
	 *
	 * @param Condition   $condition The condition.
	 * @param Query_Scope $scope     The object type being queried.
	 *
	 * @throws Filter_Field_Unavailable When the field cannot answer this question.
	 * @throws License_Limited          When the owning module is not licensed.
	 */
	public function field_for( Condition $condition, Query_Scope $scope ): Filter_Field {
		$key      = $condition->field;
		$provider = $this->for( $key );

		if ( null === $provider ) {
			$this->unavailable(
				sprintf(
					'This filter uses the field "%s", which no active plugin provides. Reactivate the plugin that supplies it or remove the condition — CatalogOps will not run an edit against a wider set than the filter names.',
					$key
				),
				$key
			);
		}

		$module = $provider->module();

		if ( '' !== $module && ! $this->license->has_module( $module ) ) {
			$this->limited(
				sprintf(
					'This filter uses the field "%s", which needs the Studio plan. Running the edit without the condition would target more products than the filter names, so it is refused rather than ignored.',
					$key
				)
			);
		}

		$field = null;

		foreach ( $provider->filter_fields() as $candidate ) {
			if ( $candidate->key === $key ) {
				$field = $candidate;
				break;
			}
		}

		if ( null === $field ) {
			$this->unavailable(
				sprintf( 'The field "%s" is no longer offered by the module that provided it.', $key ),
				$key
			);
		}

		if ( ! $field->applies_in( $scope ) ) {
			$this->unavailable(
				sprintf( 'The field "%1$s" does not apply to %2$ss.', $key, $scope->value ),
				$key
			);
		}

		if ( ! $field->supports( $condition->operator ) ) {
			$this->unavailable(
				sprintf(
					'The comparison "%1$s" is not available for the field "%2$s".',
					$condition->operator->value,
					$key
				),
				$key
			);
		}

		return $field;
	}

	/**
	 * Refuse a field, naming it.
	 *
	 * A helper rather than five inline throws for the same reason
	 * {@see \CatalogOps\Query\Query_Engine::refuse()} is one: the message is built
	 * from a field key that arrives verbatim from a REST body, and gathering the
	 * escaping decision in one place is the difference between reviewing it once and
	 * reviewing it wherever it happens to appear next.
	 *
	 * @param string $message Why, naming the field.
	 * @param string $key     The filter key that could not answer.
	 *
	 * @throws Filter_Field_Unavailable Always.
	 */
	private function unavailable( string $message, string $key ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
		throw new Filter_Field_Unavailable( $message, $key );
	}

	/**
	 * Refuse a field because the site's plan does not include its module.
	 *
	 * Deliberately a different exception: the REST layer answers 402 for this one,
	 * so the client can offer the upgrade instead of a dead end.
	 *
	 * @param string $message Why, naming the field.
	 *
	 * @throws License_Limited Always.
	 */
	private function limited( string $message ): never {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
		throw new License_Limited( $message );
	}

	/**
	 * Whether a key belongs to the engine's own dispatch.
	 *
	 * Delegated to {@see Filter_Fields} rather than kept as a second copy of the
	 * same six keys and two prefixes. Two lists would drift, and the drift would be
	 * silent in the dangerous direction: a key that stopped being reserved here
	 * becomes claimable by a module, which then redefines what a core filter means
	 * for every saved filter already using it.
	 *
	 * @param string $key Filter key.
	 */
	private function is_core_key( string $key ): bool {
		return Filter_Fields::handles( $key );
	}
}
