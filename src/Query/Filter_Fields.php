<?php
/**
 * The filter field keys the query engine can answer.
 *
 * @package CatalogOps\Query
 */

namespace CatalogOps\Query;

/**
 * One list of the filter keys {@see Query_Engine::clause_for()} dispatches on, and
 * the single place that decides a key names nothing.
 *
 * It exists because "the count in the preview is the count that runs" is only true
 * if every condition either constrains the query or refuses. `clause_for()` used
 * to end by returning an empty SQL fragment for a field it did not recognise;
 * {@see Query_Engine::build_where()} skips an empty fragment, so under the AND
 * relation the filter ran with one condition fewer and matched strictly more
 * objects than was asked for. Widening is the one direction that may never happen
 * quietly: preview and run resolve the same widened filter, agree perfectly, and
 * both report success, so nothing downstream can notice.
 *
 * The check is pure and static: the shape of the key, nothing else. It never asks
 * the database whether a taxonomy, a term or a meta key exists. A filter naming a
 * category that has since been deleted is a real question with the real answer
 * "nothing", and its negation — "exclude a brand that was deleted" — must keep
 * every product; {@see Query_Engine::taxonomy_clause()} answers both. Refusing on
 * existence would turn those honest answers into dead ends, and would make a
 * filter fail because the catalogue changed rather than because the filter is
 * unanswerable.
 *
 * It is also why parsing stays total. {@see Filter::from_array()} still rebuilds
 * any stored filter whatever field it names, so a saved filter or a schedule
 * pointing at a field that has gone away can be listed, opened and repaired. Only
 * execution refuses.
 */
final class Filter_Fields {

	/**
	 * Field keys the engine answers by name.
	 *
	 * @var list<string>
	 */
	private const CORE = array(
		'price',
		'stock_quantity',
		'stock_status',
		'sku',
		'category',
		'tag',
	);

	/**
	 * Key prefixes the engine answers, each of which must be followed by a
	 * non-empty identifier.
	 *
	 * @var list<string>
	 */
	private const PREFIXES = array(
		'attribute:',
		'meta:',
	);

	/**
	 * Refuse a filter naming a field the engine cannot answer.
	 *
	 * @param Filter $filter The filter to check.
	 *
	 * @throws Filter_Field_Unavailable When a condition names an unknown field.
	 */
	public static function assert_answerable( Filter $filter ): void {
		foreach ( $filter->conditions() as $condition ) {
			self::assert_field( $condition->field );
		}
	}

	/**
	 * Refuse one field key the engine cannot answer.
	 *
	 * @param string $field The filter field key.
	 *
	 * @throws Filter_Field_Unavailable When no clause builder claims the key.
	 */
	public static function assert_field( string $field ): void {
		if ( self::handles( $field ) ) {
			return;
		}

		throw new Filter_Field_Unavailable(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- UI-facing message, sanitized at the REST boundary.
			sprintf( 'No filter field is called "%s". Remove or correct this condition and try again.', $field )
		);
	}

	/**
	 * Whether a clause builder claims this key.
	 *
	 * Public because it is the one list of what the engine answers itself, and two
	 * other places need to ask it rather than keep a second copy that drifts:
	 * {@see Query_Engine::clause_for()} asks before offering a key to a provider,
	 * and {@see \CatalogOps\Query\Fields\Filter_Providers} asks so a module cannot
	 * claim a key that would silently redefine what a core filter means.
	 *
	 * @param string $field The filter field key.
	 */
	public static function handles( string $field ): bool {
		if ( in_array( $field, self::CORE, true ) ) {
			return true;
		}

		foreach ( self::PREFIXES as $prefix ) {
			// The identifier after the prefix has to be there. `meta:` on its own
			// names no key, and the engine answered it by ignoring the condition —
			// which is what a site whose catalogops_brand_meta_key filter returns an
			// empty string emits ({@see \CatalogOps\Rest\Fields_Controller::brands()}).
			if ( str_starts_with( $field, $prefix ) && '' !== substr( $field, strlen( $prefix ) ) ) {
				return true;
			}
		}

		return false;
	}
}
