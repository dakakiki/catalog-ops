<?php
/**
 * Contract for a module that adds filterable fields.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Query_Scope;

/**
 * The read half of the M7 public API, and a deliberate sibling of
 * {@see \CatalogOps\Operations\Fields\Field_Provider} rather than more methods on it.
 *
 * Three reasons, all load-bearing. Writing and filtering are different capabilities:
 * WPML can tell you a product's language and has nothing to write; a theme provider
 * can write a field with no SQL-expressible question. Adding a method to
 * `Field_Provider` would break `Core_Fields`, `Meta_Fields` and every third-party
 * write-only provider on upgrade. And the two key spaces are already separate —
 * `price` filters `l.min_price` while `regular_price` writes `_regular_price`, and
 * neither key exists on the other side. A module doing both registers on both hooks.
 *
 * The original design (CONTEXT §3) specified
 * `query_clause( string $key, string $op, $value ): array`. That signature cannot
 * ship: it carries no scope, so it is wrong under the variation scope; no join slot,
 * so it is unsafe under OR; a string operator where the persisted JSON round-trips an
 * enum; and an `array` return whose slots a stranger can transpose into an injection.
 * What replaced it keeps the idea and moves every one of those decisions to the side
 * of the seam that measured them.
 */
interface Filter_Provider {

	/**
	 * The licensed module these fields belong to, or '' when they are free.
	 *
	 * Returned as a constant, never computed: the registry asks once per condition
	 * and the answer must not depend on anything that can change between the preview
	 * request and the queue request.
	 *
	 * The gate is enforced at the clause seam, never by declining to register. A
	 * provider that vanishes when a licence lapses turns every saved filter and every
	 * recurring schedule naming its fields into a *wider* filter — and `modules` is
	 * the one capability that goes false the instant a Studio plan lapses, because
	 * {@see \CatalogOps\Licensing\License::resolve()} draws `premium` from
	 * `can_use_premium_code()` (true after expiry by policy) but `modules` from
	 * `is_plan_or_trial( 'studio' )`. Registered-and-refused is the only safe shape.
	 */
	public function module(): string;

	/**
	 * The heading this provider's fields appear under in the filter.
	 *
	 * A sibling of {@see module()} rather than something derived from it: that one
	 * returns a slug the licence gate compares, this one returns text a shop owner
	 * reads, and deriving the second from the first means the client keeping a map of
	 * module names — which is the admin bundle learning about ACF, exactly what
	 * `options_route` exists to avoid.
	 *
	 * Translated by the provider, because only the provider knows its own text
	 * domain. Keep it short: it is a section heading above a row of controls, not a
	 * description, and every field already carries its own label.
	 */
	public function label(): string;

	/**
	 * The filterable fields this provider offers.
	 *
	 * Unlike {@see storage_for()}, this method may look things up: it is called to
	 * build the UI's field list, not on the path that renders SQL. A provider
	 * discovers its site's fields here — `acf_get_field_groups()`, WPML's active
	 * languages — and freezes what it learns into the descriptors it returns.
	 *
	 * @return list<Filter_Field>
	 */
	public function filter_fields(): array;

	/**
	 * Whether this provider owns the given filter key.
	 *
	 * Must be cheap and pure: it is asked once per condition per rendered statement,
	 * and one preview renders its filter 2+N+W times.
	 *
	 * @param string $key Filter key as persisted in filter_json.
	 */
	public function handles_filter( string $key ): bool;

	/**
	 * Where this field's value lives, for this scope.
	 *
	 * Note what this method is *not* given: the operator, the operand, the join slot,
	 * the relation, or a database handle for building SQL. Those are the four places a
	 * provider could be careful and still be wrong, and they belong to the engine.
	 * What is left is a question about one storage location.
	 *
	 * The scope is a parameter, not a hint — a field's storage may genuinely differ.
	 * A parent product's attribute is a taxonomy term; the same attribute on a
	 * variation is the variation's own `attribute_{taxonomy}` post meta holding a term
	 * slug. WPML's duplicate flag is `_icl_lang_duplicate_of` on a product and
	 * `_wcml_duplicate_of_variation` on a variation. Only ever called for a scope the
	 * field declared.
	 *
	 * Must be a pure function of ( $key, $scope ) and durable database state. Preview
	 * counts in one HTTP request; queue freezes in another, sometimes days later on a
	 * cron tick with no user present, rebuilding from the same persisted filter_json.
	 * No clock, no current user, no current admin language, no request state, no
	 * `switch_to_blog()`, no remote call. Two concrete traps found while writing this:
	 * `acf_get_field_groups()` runs the `acf/…` filter chain and is not pure, and
	 * WPML's `SitePress::get_active_languages()` branches on the current language and
	 * parses `$_SERVER['QUERY_STRING']` — so the same site answers differently to an
	 * admin screen, a REST preview and a cron tick. Both belong in
	 * {@see filter_fields()} only.
	 *
	 * @param string      $key   A key {@see handles_filter()} claimed.
	 * @param Query_Scope $scope The object type being queried.
	 *
	 * @throws \CatalogOps\Query\Filter_Field_Unavailable When the field cannot be
	 *                                  expressed right now — an ACF field that was
	 *                                  deleted, a legacy date format no lexical range
	 *                                  can order, a dependency that is inactive.
	 *                                  Refuse; there is no way to return "no
	 *                                  constraint", because that is exactly how a
	 *                                  filter silently widens.
	 */
	public function storage_for( string $key, Query_Scope $scope ): Field_Storage;
}
