<?php
/**
 * Everything the plugin needs to know about one filterable field.
 *
 * @package CatalogOps\Query\Fields
 */

namespace CatalogOps\Query\Fields;

use CatalogOps\Query\Operator;
use CatalogOps\Query\Query_Scope;

/**
 * One descriptor answers four questions that are answered by four hardcoded lists
 * today: which control the filter UI renders and with which operators (hardcoded in
 * the React bundle); whether the results table shows a column for it (hardcoded in
 * {@see \CatalogOps\Rest\Query_Controller}); whether an Adjust on it needs a
 * presence requirement so the preview stays exact (hardcoded in
 * {@see \CatalogOps\Operations\Write_Rules}); and, with {@see Field_Storage}, how
 * the clause is built.
 *
 * The operator list is load-bearing in both directions: the UI offers nothing outside
 * it, and {@see Filter_Providers} refuses a persisted condition whose operator is not
 * in it *before* calling the provider. That is what makes "I do not support this
 * operator" a hard error rather than the empty fragment that silently widens an AND
 * filter.
 *
 * The scope list is the same promise for scopes. A field omitted from a scope is
 * refused there by name — never answered wrongly, which is the only other option.
 */
final class Filter_Field {

	/**
	 * Describe a filterable field.
	 *
	 * @param string         $key                Stable key, persisted in filter_json for the
	 *                                           life of every saved filter and schedule.
	 *                                           Namespace it (`acf:approved`, `wpml:language`)
	 *                                           and never change it.
	 * @param string         $label              Translated label for the filter row.
	 * @param Filter_Control $control            How the UI collects a value.
	 * @param Operator[]     $operators          Operators offered and accepted. Must be a
	 *                                           subset of the storage's value kind.
	 * @param Query_Scope[]  $scopes             Scopes this field answers correctly in.
	 * @param string         $options_route      REST route serving options for a set control,
	 *                                           or '' for free entry.
	 * @param string|null    $column_label       Header for a results-table column showing this
	 *                                           field, or null for none. 0.7.1 added the brand
	 *                                           column precisely because filtering by something
	 *                                           the results do not show was a defect; leaving
	 *                                           this null reintroduces it.
	 * @param bool           $required_for_write True when an Adjust or Formula targeting this
	 *                                           field needs a presence requirement to keep the
	 *                                           preview exact.
	 * @param string         $value_format       The PHP date format the value is STORED in, for a
	 *                                           date control — `Ymd`, `Y-m-d H:i:s`. Empty for
	 *                                           every other control.
	 *
	 *                                           A date control collects `YYYY-MM-DD`, which is
	 *                                           what a browser's date input produces and the only
	 *                                           thing it will produce. ACF stores `20240708`. The
	 *                                           two never match, and the failure is silent: a
	 *                                           filter that reads correctly returns nothing, and
	 *                                           nothing is exactly what an over-narrow filter
	 *                                           looks like. Naming the stored format is what lets
	 *                                           the client send what the column actually holds
	 *                                           without knowing anything about ACF.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly Filter_Control $control,
		public readonly array $operators,
		public readonly array $scopes,
		public readonly string $options_route = '',
		public readonly ?string $column_label = null,
		public readonly bool $required_for_write = false,
		public readonly string $value_format = '',
	) {}

	/**
	 * Whether this field answers the given operator.
	 *
	 * @param Operator $operator The operator asked for.
	 */
	public function supports( Operator $operator ): bool {
		return in_array( $operator, $this->operators, true );
	}

	/**
	 * Whether this field means anything in the given scope.
	 *
	 * @param Query_Scope $scope The object type being queried.
	 */
	public function applies_in( Query_Scope $scope ): bool {
		return in_array( $scope, $this->scopes, true );
	}
}
