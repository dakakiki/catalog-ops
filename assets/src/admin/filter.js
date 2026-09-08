/**
 * The filter form, and the payload it becomes.
 *
 * Extracted from the admin app so it can be tested, and it is the right first
 * extraction rather than an arbitrary one: `buildFilter()` is the single point
 * where the results table, the preview, the bulk edit and a saved schedule agree
 * on which products they are all talking about. Anything assembling conditions
 * outside it reintroduces exactly the preview-does-not-equal-run divergence the
 * pipeline exists to prevent — and until this file, nothing in the repository
 * tested a line of JavaScript.
 *
 * Everything here is pure: no React, no fetch, no DOM. That is what makes it
 * testable, and keeping it that way is the point of the file.
 */

/**
 * A blank filter form.
 *
 * Returned fresh each time rather than shared: the form holds arrays, and one
 * shared constant would hand every reset the same ones.
 *
 * The `…Mode` keys carry each set-valued field's include/exclude choice, so the
 * form can express "category YY but not brand XX" without growing a second
 * control per field.
 *
 * @return {Object} An empty form.
 */
export function emptyForm() {
	return {
		priceMin: '',
		priceMax: '',
		stockStatus: '',
		sku: '',
		category: [],
		categoryMode: 'in',
		tag: [],
		tagMode: 'in',
		brand: [],
		brandMode: 'in',
		attribute: '',
		attributeValues: [],
		attributeMode: 'in',
	};
}

/**
 * The API operator for a set-valued field's include/exclude mode.
 *
 * Anything that is not an explicit exclusion reads as an inclusion, so a form
 * (or a filter saved before modes existed) without the key keeps its original
 * meaning.
 *
 * @param {string} value The field's mode.
 * @return {string} 'in' or 'not_in'.
 */
export function operatorFor( value ) {
	return 'not_in' === value ? 'not_in' : 'in';
}

/**
 * Build the filter payload from the form state and target scope.
 *
 * @param {Object} form       Form values.
 * @param {string} scope      'product' or 'variation'.
 * @param {string} brandField The filter field a brand maps to (from the API).
 * @return {Object} Filter in the API's shape (scope included, so the same filter
 * drives the query, the preview, and the operation).
 */
/**
 * The tag list's one entry that is not a tag: "has none at all".
 *
 * A string, so it cannot collide with a term id — those are numbers, and the
 * real ones go through `Number()` on the way into a condition while this never
 * does.
 */
export const NO_TAG = 'none';

/**
 * Reconcile a tag selection with the "Without tag" entry, which cannot coexist
 * with a real one: no product both carries a tag and carries none, so a filter
 * saying both would always match nothing.
 *
 * Rather than refuse the combination and make the user undo it, whichever was
 * chosen last wins — picking "Without tag" clears the tags, and picking a tag
 * clears "Without tag".
 *
 * @param {Array} previous The selection before this change.
 * @param {Array} next     The selection the control is proposing.
 * @return {Array} The selection to keep.
 */
export function reconcileTagSelection( previous, next ) {
	const had = previous.map( String ).includes( NO_TAG );
	const has = next.map( String ).includes( NO_TAG );

	if ( has && ! had ) {
		return [ NO_TAG ];
	}

	if ( has && next.length > 1 ) {
		return next.filter( ( id ) => String( id ) !== NO_TAG );
	}

	return next;
}

export function buildFilter( form, scope, brandField ) {
	const conditions = [];

	if ( form.priceMin !== '' ) {
		conditions.push( {
			field: 'price',
			operator: '>=',
			value: Number( form.priceMin ),
		} );
	}
	if ( form.priceMax !== '' ) {
		conditions.push( {
			field: 'price',
			operator: '<=',
			value: Number( form.priceMax ),
		} );
	}
	if ( form.stockStatus !== '' ) {
		conditions.push( {
			field: 'stock_status',
			operator: '=',
			value: form.stockStatus,
		} );
	}
	if ( form.sku && form.sku.trim() !== '' ) {
		conditions.push( {
			field: 'sku',
			operator: 'contains',
			value: form.sku.trim(),
		} );
	}
	// A set-valued field carries its own include/exclude mode, so one filter can
	// say "in category YY, but not brand XX". Every condition is still ANDed
	// (see the return): an exclusion narrows the match, it does not widen it.
	// An empty selection is no condition at all in either mode — excluding
	// nothing excludes nobody.
	if ( form.category.length ) {
		conditions.push( {
			field: 'category',
			operator: operatorFor( form.categoryMode ),
			value: form.category.map( Number ),
		} );
	}
	if ( form.tag && form.tag.length ) {
		if ( form.tag.map( String ).includes( NO_TAG ) ) {
			// "Without tag" asks about the taxonomy rather than about which terms,
			// so it carries no value — the same shape the attribute pair below uses
			// when no value is picked. The mode still governs, and reads the way the
			// rest of the row does: the selection is what to keep, so excluding the
			// untagged leaves exactly the products that do carry a tag.
			conditions.push( {
				field: 'tag',
				operator: 'not_in' === form.tagMode ? 'exists' : 'not_exists',
			} );
		} else {
			conditions.push( {
				field: 'tag',
				operator: operatorFor( form.tagMode ),
				value: form.tag.map( Number ),
			} );
		}
	}
	if ( form.brand.length && brandField ) {
		conditions.push( {
			field: brandField,
			operator: operatorFor( form.brandMode ),
			value: form.brand,
		} );
	}
	if ( 'variation' === scope && form.attribute ) {
		// Attribute filtering targets variations (a parent's price lives on its
		// variations), so it only applies in the variation scope — the UI hides it
		// otherwise, and this guards a stale value from a prior scope.
		// A value picked → match those attribute terms; none picked → match any
		// object that has this attribute at all. Values are term ids.
		// With no value chosen the question is about the attribute itself rather
		// than its values: has one at all, or has none.
		if ( form.attributeValues.length ) {
			conditions.push( {
				field: form.attribute,
				operator: operatorFor( form.attributeMode ),
				value: form.attributeValues.map( Number ),
			} );
		} else {
			conditions.push( {
				field: form.attribute,
				operator:
					'not_in' === form.attributeMode ? 'not_exists' : 'exists',
			} );
		}
	}

	return { relation: 'AND', scope, conditions };
}
