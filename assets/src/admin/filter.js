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
		// Keyed by field key, each `{ value, to, mode }` — `mode` holding the
		// operator token and `to` the far end of a range. Empty rather than
		// pre-populated from the descriptors: a form built before the field list
		// arrives must still be a valid form, and a row nobody touched must not
		// become a condition.
		modules: {},
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
 * @param {Object} form  Form values.
 * @param {string} scope 'product' or 'variation'.
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

/**
 * The controls whose value is a set rather than a single thing.
 */
const SET_CONTROLS = [ 'term_set', 'value_set' ];

/**
 * The entry a set control offers for "this field is empty".
 *
 * The same idea as {@see NO_TAG}, and it exists for the same reason: on a set
 * field, "carries nothing at all" is a question no operator on the list can ask.
 * `is not sale` deliberately KEEPS the products carrying nothing — they are,
 * definitively, not on sale — so without this entry those products cannot be
 * selected by any combination of choices.
 *
 * A distinctive string rather than something readable, because unlike a term id
 * an ACF choice key is itself a string and could collide. The control only adds
 * this entry when no real option already answers to it, so a shop that has
 * somehow named a choice this still gets its own value rather than a sentinel.
 */
export const NO_VALUE = '__catalogops_no_value__';

/**
 * Coerce a module field's value to the shape its control implies.
 *
 * Term sets send numeric ids and value sets send strings, and the difference is
 * not cosmetic: the engine casts a term id to an integer and would silently turn
 * a string into 0, which matches nothing and reads as an over-narrow filter.
 *
 * @param {string} control The control kind from the descriptor.
 * @param {*}      value   Whatever the control is holding.
 * @return {*} The value to send, or undefined when there is nothing to send.
 */
export function moduleValue( control, value ) {
	if ( SET_CONTROLS.includes( control ) ) {
		const list = ( value || [] ).filter( ( one ) => one !== '' );

		if ( ! list.length ) {
			return undefined;
		}

		return 'term_set' === control ? list.map( Number ) : list.map( String );
	}

	if ( 'toggle' === control ) {
		// A toggle has three states, not two: on, off, and "not asked". Only the
		// third means no condition — an explicit "off" is a real question.
		if ( '' === value || undefined === value || null === value ) {
			return undefined;
		}

		return value ? '1' : '0';
	}

	const single = 'string' === typeof value ? value.trim() : value;

	if ( '' === single || undefined === single || null === single ) {
		return undefined;
	}

	return 'number' === control || 'money' === control
		? Number( single )
		: single;
}

/**
 * Every operator, in the order a menu should offer them.
 *
 * Value comparisons first, then the ordered ones, then the two that ask about
 * presence and carry no value at all. Ordering here rather than at each call site
 * means a field's menu reads the same wherever it appears, and a module that
 * declares its operators in some other order does not get a shuffled menu.
 *
 * Labels are deliberately NOT here. Deciding *which* operators a field offers is
 * a rule; saying them in the user's language is a view's job, and keeping the two
 * apart is what lets this file stay free of i18n and be tested as plain data.
 */
export const MODULE_OPERATOR_ORDER = [
	'=',
	'!=',
	'contains',
	'in',
	'not_in',
	'>',
	'>=',
	'<',
	'<=',
	'between',
	'exists',
	'not_exists',
];

/**
 * The operators a field offers, in menu order.
 *
 * **The descriptor is the authority.** Only what the field declares is offered,
 * because an operator it does not declare is one `Filter_Providers` refuses
 * before the provider is even asked — and a refusal stops the whole filter, not
 * just that row.
 *
 * This is what the old `moduleOperator()` could not do. That function mapped an
 * include/exclude mode onto one of `=`, `!=`, `in`, `not_in`, `contains`, so
 * `>`, `>=`, `<`, `<=` and `between` were **unreachable from the UI** even though
 * ACF's number and date fields declare them, the compiler emits them and the
 * engine answers them. "Cost price is more than 100" could not be asked.
 *
 * @param {Object} field The descriptor.
 * @return {string[]} Operator tokens, in menu order.
 */
export function moduleOperators( field ) {
	const declared = ( field && field.operators ) || [];

	return MODULE_OPERATOR_ORDER.filter( ( operator ) =>
		declared.includes( operator )
	);
}

/**
 * Whether an operator compares against something the user types.
 *
 * `exists` and `not_exists` do not: they ask whether the field is filled in at
 * all. The value box is REMOVED for them rather than disabled — a disabled box
 * beside a chip reading "has no value" looks like something is broken, and it
 * invites the reader to wonder what the greyed-out text would have done.
 *
 * @param {string} operator Operator token.
 * @return {boolean} Whether a value is needed.
 */
export function operatorTakesValue( operator ) {
	return 'exists' !== operator && 'not_exists' !== operator;
}

/**
 * Whether an operator needs two values rather than one.
 *
 * @param {string} operator Operator token.
 * @return {boolean} Whether a second box is needed.
 */
export function operatorTakesRange( operator ) {
	return 'between' === operator;
}

/**
 * The operator a field starts on.
 *
 * The first one that takes a value, so a field opens ready to be typed into
 * rather than on "has any value" — which would be a condition the user never
 * asked for the moment they touched the row. A presence-only field has nothing
 * else to offer and starts there honestly.
 *
 * @param {Object} field The descriptor.
 * @return {string} An operator token, or '' when the field declares none.
 */
export function defaultModuleOperator( field ) {
	const offered = moduleOperators( field );

	return offered.find( operatorTakesValue ) || offered[ 0 ] || '';
}

/**
 * A row's raw value, converted to what the field's column holds.
 *
 * Only a date control has anything to convert; everything else is already in the
 * shape the column stores.
 *
 * @param {Object}  field    The descriptor.
 * @param {*}       value    Whatever the control is holding.
 * @param {boolean} endOfDay Whether this is a range's upper bound.
 * @return {*} The value to send on.
 */
function asStored( field, value, endOfDay ) {
	if ( 'date' !== field.control || ! field.value_format ) {
		return value;
	}

	return dateForStorage( value, field.value_format, endOfDay );
}

/**
 * The conditions a module's fields contribute.
 *
 * Kept out of buildFilter's body so the rule that governs them is readable on
 * its own: a row contributes a condition only when the field is available, means
 * something in this scope, holds a value, and declares an operator for it. Any
 * one of those missing drops the row — never a partial condition, because a
 * condition the engine refuses stops the whole filter, and one it misreads is
 * worse.
 *
 * @param {Object} modules Values keyed by field key: `{ value, mode }`.
 * @param {Array}  fields  Descriptors from /fields/filterable.
 * @param {string} scope   'product' or 'variation'.
 * @return {Array} Conditions.
 */
export function moduleConditions( modules, fields, scope ) {
	const conditions = [];

	( fields || [] ).forEach( ( field ) => {
		if ( ! field.available ) {
			return;
		}

		if ( ! ( field.scopes || [] ).includes( scope ) ) {
			return;
		}

		const row = ( modules || {} )[ field.key ];

		if ( ! row ) {
			return;
		}

		// The row's mode IS the operator token now, not an include/exclude flag
		// mapped onto one. It is still checked against the descriptor rather than
		// trusted: the field list can change under a form that is already open —
		// a licence lapses, an ACF field is deleted — and an operator the field
		// does not declare is one the registry refuses, which stops the whole
		// filter rather than just this row.
		const operator = row.mode || defaultModuleOperator( field );

		if ( ! operator || ! ( field.operators || [] ).includes( operator ) ) {
			return;
		}

		// "Without a value" wins the row, the way "Without tag" does: it asks
		// about the field rather than about which choices, so the choices beside
		// it have nothing to add. The mode still governs and reads the way the
		// rest of the row does — the selection is what to keep, so EXCLUDING the
		// empty ones leaves exactly the products that do carry a value.
		if (
			SET_CONTROLS.includes( field.control ) &&
			( row.value || [] ).map( String ).includes( NO_VALUE )
		) {
			const presence = 'not_in' === operator ? 'exists' : 'not_exists';

			if ( ! ( field.operators || [] ).includes( presence ) ) {
				return;
			}

			conditions.push( { field: field.key, operator: presence } );

			return;
		}

		// Presence carries no value at all, so it is answered before anything
		// asks the row what it holds.
		if ( ! operatorTakesValue( operator ) ) {
			conditions.push( { field: field.key, operator } );

			return;
		}

		if ( operatorTakesRange( operator ) ) {
			const from = moduleValue(
				field.control,
				asStored( field, row.value, false )
			);
			const to = moduleValue(
				field.control,
				asStored( field, row.to, true )
			);

			// Both ends or nothing. Half a range is not a narrower range, it is a
			// different question — and BETWEEN with one bound missing is a
			// condition the engine would refuse.
			if ( undefined === from || undefined === to ) {
				return;
			}

			conditions.push( {
				field: field.key,
				operator,
				value: [ from, to ],
			} );

			return;
		}

		const value = moduleValue(
			field.control,
			asStored( field, row.value, false )
		);

		if ( undefined === value ) {
			return;
		}

		conditions.push( { field: field.key, operator, value } );
	} );

	return conditions;
}

/**
 * Build the filter payload from the form state and target scope.
 *
 * @param {Object} form         Form values.
 * @param {string} scope        'product' or 'variation'.
 * @param {Array}  moduleFields Descriptors from /fields/filterable.
 * @return {Object} Filter in the API's shape.
 */
export function buildFilter( form, scope, moduleFields = [] ) {
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
	if ( form.brand.length ) {
		// Term ids, like category and tag — brand is WooCommerce's own taxonomy.
		// It used to be a meta key whose name arrived from the API, and its values
		// were strings, because the seed command had invented one and the UI
		// followed it. On a shop using WooCommerce's brands the control listed
		// nothing at all.
		conditions.push( {
			field: 'brand',
			operator: operatorFor( form.brandMode ),
			value: form.brand.map( Number ),
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

	// Appended after the built-in controls, so a module field reads in the
	// payload exactly where it reads on screen. Every condition is still ANDed:
	// a module cannot widen a filter, only narrow it.
	conditions.push( ...moduleConditions( form.modules, moduleFields, scope ) );

	return { relation: 'AND', scope, conditions };
}

/**
 * Group the module fields that apply to a scope, ready to render.
 *
 * Three jobs, all of which were being done badly by not being done at all: drop
 * the fields that mean nothing in this scope, gather what is left under one
 * heading per module, and keep the order the server sent.
 *
 * **The heading comes from `module_label`, never from the module slug.** A client
 * that maps `'acf'` to "ACF fields" has learned a module's name, and the next
 * module has to be taught here too — the same coupling `options_route` exists to
 * avoid. A provider that sends no label gets no heading rather than a made-up one.
 *
 * Insertion order is the server's order, which is ACF's own field order within a
 * group. A shop owner arranged those fields; re-sorting them alphabetically here
 * would throw that away for nothing.
 *
 * @param {Array}  fields Descriptors from /fields/filterable.
 * @param {string} scope  'product' or 'variation'.
 * @return {Array} Groups of { module, label, fields }, in server order.
 */
export function groupModuleFields( fields, scope ) {
	const groups = [];
	const byModule = new Map();

	( fields || [] ).forEach( ( field ) => {
		if ( ! field || ! ( field.scopes || [] ).includes( scope ) ) {
			return;
		}

		// Keyed by module, not by label: two providers sharing a heading are still
		// two modules, and the licence gate is per module.
		const key = field.module || '';

		if ( ! byModule.has( key ) ) {
			const group = {
				module: key,
				label: field.module_label || '',
				fields: [],
			};
			byModule.set( key, group );
			groups.push( group );
		}

		byModule.get( key ).fields.push( field );
	} );

	return groups;
}

/**
 * Turn what a date input gives us into what the column actually holds.
 *
 * A browser's `<input type="date">` produces `YYYY-MM-DD` and will produce
 * nothing else — that is the whole point of using it, because a free text box
 * lets someone type `8.7.2024.` and get a filter that reads correctly and matches
 * nothing. ACF stores `20240708` for a date picker and `2024-07-08 00:00:00` for
 * a date-and-time picker, so the two never meet without this.
 *
 * The upper bound of a range is pushed to the end of the day when the stored
 * format carries a time. `between 2024-01-01 and 2024-12-31` on a
 * `Y-m-d H:i:s` column would otherwise stop at midnight and silently drop
 * everything recorded during the last day — which reads as an off-by-one nobody
 * can see, because the answer is plausible.
 *
 * An unknown format is returned untouched rather than guessed at: a provider
 * that declares a format this does not know is better served sending the raw
 * text, which its own column may well match, than a value invented here.
 *
 * @param {string}  value    `YYYY-MM-DD` from the date input.
 * @param {string}  format   The PHP date format the descriptor declares.
 * @param {boolean} endOfDay Whether this is the upper bound of a range.
 * @return {string} The value to send.
 */
export function dateForStorage( value, format, endOfDay = false ) {
	const iso = 'string' === typeof value ? value.trim() : '';

	if ( '' === iso || ! /^\d{4}-\d{2}-\d{2}$/.test( iso ) ) {
		// Not a date the input produced — an empty box, or a value typed before
		// this field became a date control. Hand it back and let the ordinary
		// empty-value rule drop it.
		return value;
	}

	if ( 'Ymd' === format ) {
		return iso.replace( /-/g, '' );
	}

	if ( 'Y-m-d H:i:s' === format ) {
		return iso + ( endOfDay ? ' 23:59:59' : ' 00:00:00' );
	}

	return iso;
}
