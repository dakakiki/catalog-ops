/**
 * CatalogOps admin app.
 *
 * M1: a read-only filter + product table.
 * M2: a bulk-edit panel that previews a change, applies it as an operation, and
 * polls its progress.
 * M3: operation history with undo (drift preview + conflict policy), an audit
 * detail view of a run's recorded changes, and the retention-window setting.
 * M5: bulk edit gains formula and percentage modes; scheduled/recurring
 * operations (create from bulk edit, manage in the Schedules list); a
 * variation-attribute filter.
 *
 * Deliberately rough — functionality first (see project notes); visual polish
 * comes later.
 */
import {
	createRoot,
	render,
	useState,
	useCallback,
	useEffect,
	useRef,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import './style.css';

const PER_PAGE = 10;

/**
 * Plan capabilities for the current site, surfaced by the server via
 * wp_localize_script (see Admin_Page). Missing config fails open — everything
 * allowed — because the REST layer enforces the real limits and returns 402; the
 * UI only decides what to *offer*, never what to permit. So a flag gates a
 * control only when it is explicitly `false`.
 */
const CAPABILITIES =
	( window.catalogopsConfig && window.catalogopsConfig.capabilities ) || {};

/**
 * Whether the current plan permits a capability. Unknown flags default to true
 * (fail open); only an explicit `false` from the server gates the control.
 *
 * @param {string} key Capability key (e.g. canUseFormulas, canSchedule).
 * @return {boolean} Whether the control should be offered.
 */
function can( key ) {
	return CAPABILITIES[ key ] !== false;
}

/**
 * A small "paid plan" upsell shown in place of a gated control on the free tier.
 *
 * @param {Object} props          Component props.
 * @param {Node}   props.children The upsell copy.
 */
function UpsellNotice( { children } ) {
	return (
		<p className="catalogops-upsell">
			<span className="catalogops-upsell__badge">
				{ __( 'Paid plan', 'catalogops' ) }
			</span>
			<span>{ children }</span>
		</p>
	);
}

/**
 * Map a stock status to a badge modifier.
 *
 * @param {string} status Stock status.
 * @return {string} Badge modifier class suffix.
 */
function stockBadge( status ) {
	if ( status === 'instock' ) {
		return 'in';
	}
	if ( status === 'outofstock' ) {
		return 'out';
	}
	return 'neutral';
}

/** Statuses at which an operation stops moving and polling can end. */
const TERMINAL_STATUSES = [ 'completed', 'failed', 'reverted', 'paused' ];

/** Fields the bulk editor can set. */
const EDITABLE_FIELDS = [
	{ key: 'regular_price', label: __( 'Regular price', 'catalogops' ) },
	{ key: 'sale_price', label: __( 'Sale price', 'catalogops' ) },
	{ key: 'stock_quantity', label: __( 'Stock quantity', 'catalogops' ) },
	{ key: 'stock_status', label: __( 'Stock status', 'catalogops' ) },
];

/**
 * The fields a formula or percentage change can target — prices only. Percentage
 * and formula edits don't make sense for stock quantity or status, so those are
 * "Set to" only; `stock` is still available as a formula variable to read.
 */
const NUMERIC_FIELDS = [
	{
		key: 'regular_price',
		variable: 'regular_price',
		label: __( 'Regular price', 'catalogops' ),
	},
	{
		key: 'sale_price',
		variable: 'sale_price',
		label: __( 'Sale price', 'catalogops' ),
	},
];

/**
 * The formula variable a numeric field key reads under.
 *
 * @param {string} key A numeric field key (e.g. stock_quantity).
 * @return {string} The formula variable name (e.g. stock).
 */
const fieldVariable = ( key ) =>
	( NUMERIC_FIELDS.find( ( f ) => f.key === key ) || {} ).variable || key;

/**
 * Every variable a formula may reference — mirrors the server-side whitelist in
 * CatalogOps\Operations\Formula\Variables. Used to tell the user, before they
 * press Preview, which fields their formula depends on.
 */
const FORMULA_VARIABLES = [
	'regular_price',
	'sale_price',
	'stock',
	'weight',
	'cost',
];

/**
 * The formula variables an expression reads.
 *
 * Word boundaries keep `stock` from matching inside `stock_quantity`: the
 * character after it is `_`, which is a word character, so the boundary fails.
 *
 * @param {string} expression The formula source.
 * @return {Array} The variable names it references.
 */
function formulaReads( expression ) {
	if ( ! expression ) {
		return [];
	}

	return FORMULA_VARIABLES.filter( ( name ) =>
		new RegExp( `\\b${ name }\\b` ).test( expression )
	);
}

/** A short description of each editable field, for the contextual change hint. */
const FIELD_NOUNS = {
	regular_price: __( 'the regular price', 'catalogops' ),
	sale_price: __( 'the sale price', 'catalogops' ),
	stock_quantity: __( 'the stock quantity (inventory level)', 'catalogops' ),
	stock_status: __( 'the stock status', 'catalogops' ),
};

/**
 * A one-line, mode-aware explanation of what applying the change does to the
 * selected field, shown under the value control.
 *
 * Two things it has to say that the field name alone does not. A formula's
 * *inputs* decide which products qualify, not just its output — "recalculates the
 * regular price" gives no hint that products without a sale price will be left
 * out of `sale_price * 1.5`. And a stock status is not stored at all where stock
 * is managed; WooCommerce derives it from the quantity on every save.
 *
 * @param {string} field         The selected field key.
 * @param {string} mode          'set' | 'percent' | 'formula'.
 * @param {string} expression    The formula being applied, for percent and formula
 *                               modes — the source of the fields it reads.
 * @param {Object} percentChange For percent mode, { direction, amount }: the
 *                               sentence names which way the price moves rather
 *                               than pointing at the control that says so.
 * @return {string} The hint sentence.
 */
function changeHint( field, mode, expression = '', percentChange = null ) {
	const noun = FIELD_NOUNS[ field ] || field;
	let sentence;
	if ( mode === 'percent' ) {
		const down =
			'decrease' === ( percentChange && percentChange.direction );
		const amount = percentChange ? percentChange.amount : '';

		const named = '' !== amount && ! Number.isNaN( Number( amount ) );
		const shown = named ? Math.abs( Number( amount ) ) : 0;

		if ( named && down ) {
			sentence = sprintf(
				/* translators: 1: the field being changed, e.g. "the regular price". 2: a percentage, e.g. "10". */
				__(
					'Lowers %1$s by %2$s%%, for every matching product.',
					'catalogops'
				),
				noun,
				shown
			);
		} else if ( named ) {
			sentence = sprintf(
				/* translators: 1: the field being changed, e.g. "the regular price". 2: a percentage, e.g. "10". */
				__(
					'Raises %1$s by %2$s%%, for every matching product.',
					'catalogops'
				),
				noun,
				shown
			);
		} else if ( down ) {
			sentence = sprintf(
				/* translators: %s: the field being changed, e.g. "the regular price". */
				__(
					'Lowers %s by the percentage above, for every matching product.',
					'catalogops'
				),
				noun
			);
		} else {
			sentence = sprintf(
				/* translators: %s: the field being changed, e.g. "the regular price". */
				__(
					'Raises %s by the percentage above, for every matching product.',
					'catalogops'
				),
				noun
			);
		}
	} else if ( mode === 'formula' ) {
		sentence = sprintf(
			/* translators: %s: the field being changed, e.g. "the regular price". */
			__(
				'Recalculates %s with the formula, for every matching product.',
				'catalogops'
			),
			noun
		);
	} else {
		sentence = sprintf(
			/* translators: %s: the field being changed, e.g. "the regular price". */
			__(
				'Sets %s to the value above, for every matching product.',
				'catalogops'
			),
			noun
		);
	}
	if ( 'stock_quantity' === field ) {
		sentence +=
			' ' +
			__(
				'Only products with “Manage stock” enabled are affected.',
				'catalogops'
			);
	}

	if ( 'stock_status' === field ) {
		sentence +=
			' ' +
			__(
				'Only products with “Manage stock” off are affected: where stock is managed, WooCommerce works the status out from the quantity on every save and overwrites whatever is set here — change Stock quantity for those instead.',
				'catalogops'
			);
	}

	// Which fields the calculation depends on, and therefore which products it can
	// be applied to at all.
	const reads = formulaReads( expression );

	if ( reads.length > 0 ) {
		sentence +=
			' ' +
			sprintf(
				/* translators: %s: comma-separated list of field names a formula reads. */
				_n(
					'It reads %s, so products where that field is empty or non-numeric are left out — never set to 0.',
					'It reads %s, so products where any of those is empty or non-numeric are left out — never set to 0.',
					reads.length,
					'catalogops'
				),
				reads.join( ', ' )
			);
	}

	return sentence;
}

/** The recurrence presets a schedule can use (mirrors the Recurrence enum). */
const RECURRENCES = [
	{ value: 'once', label: __( 'Once', 'catalogops' ) },
	{ value: 'hourly', label: __( 'Hourly', 'catalogops' ) },
	{ value: 'daily', label: __( 'Daily', 'catalogops' ) },
	{ value: 'weekly', label: __( 'Weekly', 'catalogops' ) },
	{ value: 'monthly', label: __( 'Monthly', 'catalogops' ) },
];

/**
 * Build the percentage-change factor as a clean decimal string, so a 10%
 * decrease becomes the formula `<field> * 0.9` with no floating-point noise in
 * the text.
 *
 * The sign comes from the direction alone; the amount is read as a magnitude.
 * Otherwise a typed "-10" under Decrease would negate the negation and quietly
 * raise prices — the exact confusion the direction control exists to remove.
 *
 * @param {number|string} percent   The percentage amount (e.g. 15).
 * @param {string}        direction 'increase' or 'decrease'.
 * @return {string} The multiplier as a trimmed decimal string.
 */
function percentFactor( percent, direction ) {
	const amount = Math.abs( Number( percent ) );
	const delta = 'decrease' === direction ? -amount : amount;
	const factor = 1 + delta / 100;

	return String( Number( factor.toFixed( 6 ) ) );
}

/**
 * The deepest cut a percentage change may express. At 100% the price lands on
 * zero, which is a real thing to want; past it the factor goes negative and the
 * write path has nothing to stop it — WooCommerce's setters store what they are
 * given, so a mistyped 150 would put negative prices across the catalogue.
 */
const MAX_DECREASE = 100;

const isTerminal = ( op ) => op && TERMINAL_STATUSES.includes( op.status );

/**
 * Above this many selections the control stops naming them and shows a count.
 * Three chips fit the filter row's width; a fourth starts pushing the control
 * taller than the two rows it is allowed, and a wall of pills stops being
 * readable well before it stops fitting.
 */
const MAX_CHIPS = 3;

/**
 * What to do when a filter finds nothing here but something next door.
 *
 * The Products/Variations split is invisible until it bites: a variable product
 * keeps its price, stock and SKU on its variations, so a price or stock filter
 * over parents sails past every variable product in the catalogue and reports
 * nothing found (CONTEXT §4). An empty result is exactly the moment to say so.
 *
 * The switch is offered rather than described. Telling someone to go and click a
 * control they have already looked past is how the old preview tip put it, and a
 * button that just does it is one step instead of three.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.other    { scope, total } as the query answered.
 * @param {Function} props.onSwitch Called with the scope to switch to.
 */
function ScopeHint( { other, onSwitch } ) {
	const toVariations = 'variation' === other.scope;

	const sentence = toVariations
		? sprintf(
				/* translators: %d: number of matching variations. */
				_n(
					'No products match, but %d variation does. Variable products keep their price, stock and SKU on their variations, not on the parent.',
					'No products match, but %d variations do. Variable products keep their price, stock and SKU on their variations, not on the parent.',
					other.total,
					'catalogops'
				),
				other.total
		  )
		: sprintf(
				/* translators: %d: number of matching products. */
				_n(
					'No variations match, but %d product does.',
					'No variations match, but %d products do.',
					other.total,
					'catalogops'
				),
				other.total
		  );

	return (
		<div className="catalogops-scope-hint">
			<p>{ sentence }</p>
			<button
				type="button"
				className="button"
				onClick={ () => onSwitch( other.scope ) }
			>
				{ toVariations
					? __( 'Switch to Variations', 'catalogops' )
					: __( 'Switch to Products', 'catalogops' ) }
			</button>
		</div>
	);
}

/**
 * A multiselect bound to an array of ids: chips for what is chosen, a searchable
 * checkbox list for choosing.
 *
 * Replaces WordPress's FormTokenField, which could not be held to this row's
 * 30px control height. Its real layout lives in a generated emotion class
 * (`…TokensAndInputWrapperFlex…`) carrying 7px of padding, so matching the other
 * controls meant selecting on that class name — and WordPress renames it, and
 * moved its default control size to 40px, so the override silently missed and
 * the field came back 44px tall. A control this central cannot depend on the
 * internals of someone else's component staying still.
 *
 * Being our own also buys the behaviour the token field could not: a checkbox
 * list rather than type-to-filter tokens, and a count instead of an unbounded
 * pile of pills.
 *
 * @param {Object}   props              Component props.
 * @param {string}   props.label        Field label.
 * @param {Array}    props.options      Selectable options as { id, name }.
 * @param {string[]} props.value        Currently-selected ids.
 * @param {Function} props.onChange     Called with the new array of id strings.
 * @param {string}   props.placeholder  Shown when nothing is selected.
 * @param {string}   props.mode         'in' or 'not_in' — whether the selection is
 *                                      what to keep or what to exclude. Omit,
 *                                      along with onModeChange, for a field that
 *                                      cannot be negated.
 * @param {Function} props.onModeChange Called with the new mode.
 */
function MultiSelect( {
	label,
	options,
	value,
	onChange,
	placeholder,
	mode,
	onModeChange,
} ) {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const root = useRef( null );
	const search = useRef( null );
	const inputId = useRef(
		`catalogops-ms-${ Math.random().toString( 36 ).slice( 2, 9 ) }`
	).current;

	const ids = value.map( String );
	const chosen = options.filter( ( o ) => ids.includes( String( o.id ) ) );

	// Close on anything that means "I am done here": a click elsewhere, Escape,
	// or focus leaving the component entirely (Tab past the last checkbox).
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}

		const onDocument = ( event ) => {
			if ( root.current && ! root.current.contains( event.target ) ) {
				setOpen( false );
			}
		};

		document.addEventListener( 'mousedown', onDocument );
		document.addEventListener( 'focusin', onDocument );

		return () => {
			document.removeEventListener( 'mousedown', onDocument );
			document.removeEventListener( 'focusin', onDocument );
		};
	}, [ open ] );

	// Opening puts the caret in the search box, which is the only thing anyone
	// wants to do next with a list of a few hundred categories.
	useEffect( () => {
		if ( open && search.current ) {
			search.current.focus();
		}
	}, [ open ] );

	const toggle = ( id ) => {
		const key = String( id );
		onChange(
			ids.includes( key )
				? ids.filter( ( existing ) => existing !== key )
				: [ ...ids, key ]
		);
	};

	const onControlKeyDown = ( event ) => {
		if ( [ 'Enter', ' ', 'ArrowDown' ].includes( event.key ) ) {
			event.preventDefault();
			setOpen( true );
		}
	};

	const needle = query.trim().toLowerCase();
	const shown = needle
		? options.filter( ( o ) =>
				String( o.name ).toLowerCase().includes( needle )
		  )
		: options;

	const excluding = 'not_in' === mode;

	return (
		<div
			className={ `catalogops-ms${ excluding ? ' is-excluding' : '' }` }
			ref={ root }
		>
			<span className="catalogops-ms__label-row">
				<span
					className="catalogops-field-label"
					id={ `${ inputId }-label` }
				>
					{ label }
				</span>

				{ /* The include/exclude switch sits in the label row because it
				     modifies the label's question ("Category is…" / "is not…"),
				     not the values. The visible word is also the start of the
				     accessible name, so the two agree. */ }
				{ onModeChange && (
					<button
						type="button"
						className="catalogops-ms__mode"
						aria-label={
							excluding
								? sprintf(
										/* translators: %s: the filter field's name, e.g. Brand. */
										__(
											'is not — %s: click to include instead of exclude',
											'catalogops'
										),
										label
								  )
								: sprintf(
										/* translators: %s: the filter field's name, e.g. Brand. */
										__(
											'is — %s: click to exclude instead of include',
											'catalogops'
										),
										label
								  )
						}
						onClick={ () =>
							onModeChange( excluding ? 'in' : 'not_in' )
						}
					>
						{ excluding
							? __( 'is not', 'catalogops' )
							: __( 'is', 'catalogops' ) }
					</button>
				) }
			</span>

			{ /* A div rather than a button: the chips carry their own remove
			     buttons, and a button inside a button is invalid. Role, tabindex
			     and key handling give it the same behaviour. */ }
			<div
				className={ `catalogops-ms__control${
					open ? ' is-open' : ''
				}` }
				role="combobox"
				tabIndex={ 0 }
				aria-expanded={ open }
				aria-haspopup="dialog"
				aria-controls={ `${ inputId }-panel` }
				aria-labelledby={ `${ inputId }-label` }
				onClick={ () => setOpen( ! open ) }
				onKeyDown={ onControlKeyDown }
			>
				<span className="catalogops-ms__value">
					{ chosen.length === 0 && (
						<span className="catalogops-ms__placeholder">
							{ placeholder || __( 'Any', 'catalogops' ) }
						</span>
					) }

					{ chosen.length > 0 &&
						chosen.length <= MAX_CHIPS &&
						chosen.map( ( o ) => (
							<span className="catalogops-ms__chip" key={ o.id }>
								{ o.name }
								<button
									type="button"
									className="catalogops-ms__chip-remove"
									aria-label={ sprintf(
										/* translators: %s: the removed item's name. */
										__( 'Remove %s', 'catalogops' ),
										o.name
									) }
									onClick={ ( event ) => {
										event.stopPropagation();
										toggle( o.id );
									} }
								>
									×
								</button>
							</span>
						) ) }

					{ /* Past the chip cap there are no chips to remove things from,
					     so the count carries the way out. Without it the only escape
					     is to open the panel and untick one at a time. */ }
					{ chosen.length > MAX_CHIPS && (
						<span className="catalogops-ms__chip catalogops-ms__chip--count">
							{ sprintf(
								/* translators: %d: number of selected items. */
								_n(
									'%d selected',
									'%d selected',
									chosen.length,
									'catalogops'
								),
								chosen.length
							) }
							<button
								type="button"
								className="catalogops-ms__chip-remove"
								aria-label={ __(
									'Clear selection',
									'catalogops'
								) }
								onClick={ ( event ) => {
									event.stopPropagation();
									onChange( [] );
								} }
							>
								×
							</button>
						</span>
					) }
				</span>

				<span
					className="catalogops-ms__arrow dashicons dashicons-arrow-down-alt2"
					aria-hidden="true"
				/>
			</div>

			{ open && (
				<div
					className="catalogops-ms__panel"
					id={ `${ inputId }-panel` }
				>
					<input
						ref={ search }
						type="search"
						className="catalogops-ms__search"
						value={ query }
						placeholder={ __( 'Search…', 'catalogops' ) }
						aria-label={ __( 'Search options', 'catalogops' ) }
						onChange={ ( e ) => setQuery( e.target.value ) }
						onKeyDown={ ( e ) =>
							e.key === 'Escape' && setOpen( false )
						}
					/>

					<div className="catalogops-ms__list">
						{ shown.length === 0 && (
							<p className="catalogops-ms__empty">
								{ __( 'No matches.', 'catalogops' ) }
							</p>
						) }
						{ /* Real checkboxes: native semantics, native keyboard,
						     nothing to reimplement. */ }
						{ /* The label is a sibling of its checkbox, not its parent. A
						     label that both wraps a control and points at it with
						     htmlFor activates it twice — the box ticks and unticks in
						     one click, and the selection never takes. */ }
						{ shown.map( ( o ) => (
							<div className="catalogops-ms__option" key={ o.id }>
								<input
									id={ `${ inputId }-opt-${ o.id }` }
									type="checkbox"
									checked={ ids.includes( String( o.id ) ) }
									onChange={ () => toggle( o.id ) }
								/>
								<label htmlFor={ `${ inputId }-opt-${ o.id }` }>
									{ o.name }
								</label>
							</div>
						) ) }
					</div>

					{ chosen.length > 0 && (
						<div className="catalogops-ms__footer">
							<button
								type="button"
								className="button button-small"
								onClick={ () => onChange( [] ) }
							>
								{ __( 'Clear', 'catalogops' ) }
							</button>
							<span className="catalogops-muted">
								{ sprintf(
									/* translators: %d: number of selected items. */
									_n(
										'%d selected',
										'%d selected',
										chosen.length,
										'catalogops'
									),
									chosen.length
								) }
							</span>
						</div>
					) }
				</div>
			) }
		</div>
	);
}

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
function emptyForm() {
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
function operatorFor( value ) {
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
function buildFilter( form, scope, brandField ) {
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
		conditions.push( {
			field: 'tag',
			operator: operatorFor( form.tagMode ),
			value: form.tag.map( Number ),
		} );
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

/**
 * Poll an operation until it reaches a terminal status, then call onDone once.
 *
 * @param {Object|null} operation    The operation being watched.
 * @param {Function}    setOperation Setter to store each refreshed snapshot.
 * @param {Function}    onDone       Called once when the operation settles.
 */
function useOperationPoll( operation, setOperation, onDone ) {
	const timer = useRef( null );

	useEffect( () => {
		if ( ! operation ) {
			return undefined;
		}
		if ( isTerminal( operation ) ) {
			onDone();
			return undefined;
		}

		timer.current = setTimeout( () => {
			apiFetch( {
				path: `/catalogops/v1/operations/${ operation.id }`,
			} )
				.then( setOperation )
				.catch( () => {} );
		}, 1500 );

		return () => clearTimeout( timer.current );
	}, [ operation, setOperation, onDone ] );
}

/**
 * Why an item was left untouched, keyed by the code the server records on the
 * change row (CatalogOps\Operations\Skip_Reason). Written as lowercase clauses so
 * they read as the tail of "N items — …" in a list.
 */
const SKIP_REASONS = {
	empty_input: __(
		'a field the change reads is empty or non-numeric, so no value could be worked out (never set to 0)',
		'catalogops'
	),
	sale_not_below_regular: __(
		'the sale price you are setting is not below their regular price — WooCommerce only keeps a sale price lower than the regular price, so it would refuse this one and clear whatever sale price is already there',
		'catalogops'
	),
	stock_managed: __(
		'stock is managed here, so WooCommerce sets the status from the quantity and backorder setting instead',
		'catalogops'
	),
	negative_value: __(
		'the new price would have come out negative, so their price was left as it is — a formula that subtracts a fixed amount does this to items cheaper than that amount',
		'catalogops'
	),
	unchanged: __( 'the value was already set', 'catalogops' ),
	rejected: __(
		'WooCommerce did not keep the value — another plugin may be overriding it',
		'catalogops'
	),
	drift: __( 'the item changed after the operation ran', 'catalogops' ),
	no_record: __( 'there is no recorded value to restore', 'catalogops' ),
};

/**
 * A readable explanation for a skip-reason code.
 *
 * @param {string} code The stored reason code.
 * @return {string} Human-readable clause.
 */
function skipReasonLabel( code ) {
	return SKIP_REASONS[ code ] || __( 'no reason recorded', 'catalogops' );
}

/**
 * A count-and-reason breakdown. This is the whole point of recording reasons: a
 * bare "432 skipped" tells nobody anything they can act on.
 *
 * @param {Object} props       Component props.
 * @param {Array}  props.items Entries of { reason, count }.
 */
function ReasonList( { items } ) {
	return (
		<ul className="catalogops-reasons">
			{ items.map( ( item ) => (
				<li key={ item.reason || 'unknown' }>
					<strong>{ item.count }</strong>
					{ ' — ' }
					{ skipReasonLabel( item.reason ) }
				</li>
			) ) }
		</ul>
	);
}

/**
 * The copy for a preview warning: something the change applies to perfectly well
 * but damages on the way past.
 *
 * @param {string} code  Warning code from the server.
 * @param {number} count Items affected.
 * @return {string} Warning text, or '' for an unknown code.
 */
function warningText( code, count ) {
	if ( code === 'sale_price_protected' ) {
		return sprintf(
			/* translators: %d: number of omitted products that already have a sale price. */
			_n(
				'%d of the products left out already has a sale price. WooCommerce would have deleted it — a sale price is only kept while it is below the regular price — so it was left out instead.',
				'%d of the products left out already have a sale price. WooCommerce would have deleted them — a sale price is only kept while it is below the regular price — so they were left out instead.',
				count,
				'catalogops'
			),
			count
		);
	}

	if ( code === 'sale_price_cleared' ) {
		return sprintf(
			/* translators: %d: number of products whose sale price would be deleted. */
			_n(
				'%d matching product has a sale price at or above the new regular price. WooCommerce only keeps a sale price below the regular price, so applying this will delete that sale price — and the deletion is not recorded, so Undo cannot bring it back.',
				'%d matching products have a sale price at or above the new regular price. WooCommerce only keeps a sale price below the regular price, so applying this will delete those sale prices — and the deletion is not recorded, so Undo cannot bring them back.',
				count,
				'catalogops'
			),
			count
		);
	}

	return '';
}

/**
 * Seconds elapsed since `active` last became true, ticking once a second.
 * Resets whenever it goes false, so a run that stalls after making progress
 * measures the new wait rather than its whole lifetime.
 *
 * @param {boolean} active Whether to keep counting.
 * @return {number} Whole seconds spent waiting.
 */
function useWaitingSeconds( active ) {
	const [ seconds, setSeconds ] = useState( 0 );

	useEffect( () => {
		if ( ! active ) {
			setSeconds( 0 );
			return undefined;
		}

		const started = Date.now();
		const timer = setInterval(
			() => setSeconds( Math.round( ( Date.now() - started ) / 1000 ) ),
			1000
		);

		return () => clearInterval( timer );
	}, [ active ] );

	return seconds;
}

/** After this long with no progress, explain what the wait is actually for. */
const SLOW_START_SECONDS = 20;

/**
 * A labelled progress bar for an in-flight or finished operation.
 *
 * Applying does not start the work — it queues it, and the background runner
 * picks it up on its own schedule. On a site where WP-Cron only fires on the
 * next page load that gap can be minutes, during which a plain 0% bar looks
 * indistinguishable from a stuck one. So an operation that has processed nothing
 * yet gets an explicitly indeterminate bar and a spinner: something is happening,
 * it just is not measurable yet. If the wait runs long, the bar says why.
 *
 * @param {Object} props    Component props.
 * @param {Object} props.op The operation to render.
 */
function ProgressBar( { op } ) {
	const settled = isTerminal( op );
	const waiting = ! settled && op.processed === 0;
	const waited = useWaitingSeconds( waiting );
	const skipped = ( op.skip_reasons || [] ).filter( ( r ) => r.count > 0 );

	return (
		<div
			className={ `catalogops-progress is-${ op.status }${
				waiting ? ' is-waiting' : ''
			}` }
		>
			<p aria-live="polite">
				{ waiting && (
					<span className="catalogops-spinner" aria-hidden="true" />
				) }
				{ waiting && op.status === 'queued' && (
					<>
						{ sprintf(
							/* translators: %d: number of items queued. */
							__(
								'Queued — waiting for the background runner to start on %d items…',
								'catalogops'
							),
							op.target_count
						) }
					</>
				) }
				{ waiting && op.status !== 'queued' && (
					<>
						{ sprintf(
							/* translators: %d: number of items in the operation. */
							__(
								'Started — working through %d items…',
								'catalogops'
							),
							op.target_count
						) }
					</>
				) }
				{ ! waiting &&
					sprintf(
						/* translators: 1: status, 2: processed, 3: target. */
						__( 'Operation %1$s — %2$d / %3$d', 'catalogops' ),
						op.status,
						op.processed,
						op.target_count
					) }
				{ op.failed > 0 &&
					' ' +
						sprintf(
							/* translators: %d: number of failed objects. */
							__( '(%d failed)', 'catalogops' ),
							op.failed
						) }
			</p>
			<div className="catalogops-progress__track">
				<div
					className="catalogops-progress__fill"
					style={
						waiting ? undefined : { width: `${ op.percent }%` }
					}
				/>
			</div>
			{ waiting && waited >= SLOW_START_SECONDS && (
				<p className="catalogops-muted catalogops-progress__note">
					{ __(
						'Still waiting. The background queue was asked to start; if it has not picked this up yet, it will on the next request to the site — leaving this page open is enough. Nothing is lost either way: the operation is saved and will run.',
						'catalogops'
					) }
				</p>
			) }
			{ settled && skipped.length > 0 && (
				<div className="catalogops-progress__note">
					<p>{ __( 'Not changed:', 'catalogops' ) }</p>
					<ReasonList items={ skipped } />
				</div>
			) }
		</div>
	);
}

/**
 * The bulk-edit panel: pick a field and value, preview the change over the
 * current filter, then apply it and watch progress.
 *
 * @param {Object}   props                   Component props.
 * @param {Object}   props.filter            The current filter payload.
 * @param {number}   props.resetKey          Bumping clears the edit + schedule inputs.
 * @param {Function} props.onDone            Called when an operation finishes (to refresh).
 * @param {Function} props.onScheduleCreated Called after a schedule is created.
 * @param {boolean}  props.backupAck         Whether the backup reminder is already acknowledged.
 * @param {Function} props.onBackupAck       Called once the reminder is acknowledged.
 * @param {number}   props.retentionDays     Days an operation stays reversible (for the copy).
 */
function BulkEdit( {
	filter,
	resetKey,
	onDone,
	onScheduleCreated,
	backupAck,
	onBackupAck,
	retentionDays,
} ) {
	const [ mode, setMode ] = useState( 'set' );
	const [ field, setField ] = useState( 'regular_price' );
	const [ value, setValue ] = useState( '' );
	const [ expression, setExpression ] = useState( '' );
	const [ percent, setPercent ] = useState( '' );
	const [ direction, setDirection ] = useState( 'increase' );
	const [ preview, setPreview ] = useState( null );
	const [ operation, setOperation ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	// Percent and Formula modes both compile to a formula action, which the free
	// tier cannot run (the REST layer returns 402); scheduling is paid too. Gate
	// both controls so the free tier sees an upsell instead of a dead end.
	const canFormulas = can( 'canUseFormulas' );
	const canSchedule = can( 'canSchedule' );

	// The apply confirmation. Before the very first operation the backup reminder
	// is mandatory (CONTEXT §9): a required acknowledgement, not a throwaway
	// dialog. Once acknowledged, applying just asks for a plain confirmation.
	const [ confirming, setConfirming ] = useState( false );
	const [ backupChecked, setBackupChecked ] = useState( false );

	// Scheduling: the same filter + action, deferred and possibly recurring.
	const [ showSchedule, setShowSchedule ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ recurrence, setRecurrence ] = useState( 'once' );
	const [ startsAt, setStartsAt ] = useState( '' );
	const [ notifyEmail, setNotifyEmail ] = useState( '' );
	const [ scheduleMsg, setScheduleMsg ] = useState( '' );

	// Clear the edit + schedule inputs when the parent bumps resetKey (after an
	// operation settles or a schedule is created). The finished ProgressBar
	// (`operation`) and the "Schedule created" note (`scheduleMsg`) are kept, so
	// the user still sees the outcome of what they just ran.
	useEffect( () => {
		if ( resetKey === 0 ) {
			return;
		}
		setMode( 'set' );
		setField( 'regular_price' );
		setValue( '' );
		setExpression( '' );
		setPercent( '' );
		setDirection( 'increase' );
		setPreview( null );
		setError( '' );
		setName( '' );
		setRecurrence( 'once' );
		setStartsAt( '' );
		setNotifyEmail( '' );
		setShowSchedule( false );
	}, [ resetKey ] );

	// A cut deeper than 100% would produce a negative price, and nothing further
	// down the path would stop it. Refusing it here — rather than clamping the
	// number silently — leaves the typed figure visible next to the reason.
	const percentTooDeep =
		'decrease' === direction &&
		Math.abs( Number( percent ) ) > MAX_DECREASE;

	// Percentage change is expressed as a formula, so it flows through the exact
	// same action path (and preview/skip semantics) as a typed formula.
	const percentExpression =
		percent === '' || Number.isNaN( Number( percent ) ) || percentTooDeep
			? ''
			: `${ fieldVariable( field ) } * ${ percentFactor(
					percent,
					direction
			  ) }`;

	const buildActions = () => {
		if ( mode === 'formula' ) {
			return [ { type: 'formula', field, expression } ];
		}
		if ( mode === 'percent' ) {
			return [
				{ type: 'formula', field, expression: percentExpression },
			];
		}
		return [ { type: 'set', field, value } ];
	};

	// Whether the current inputs form a runnable action.
	const ready =
		( mode === 'set' && value !== '' ) ||
		( mode === 'formula' && expression.trim() !== '' ) ||
		( mode === 'percent' && percentExpression !== '' );

	// Switching to a numeric-only mode off a non-numeric field (stock_status)
	// falls back to a sensible numeric field.
	const changeMode = ( next ) => {
		setMode( next );
		setPreview( null );
		if (
			next !== 'set' &&
			! NUMERIC_FIELDS.some( ( f ) => f.key === field )
		) {
			setField( 'regular_price' );
		}
	};

	const fieldOptions = mode === 'set' ? EDITABLE_FIELDS : NUMERIC_FIELDS;

	useOperationPoll( operation, setOperation, onDone );

	const runPreview = () => {
		setBusy( true );
		setError( '' );
		setPreview( null );
		// Drop any finished operation's result bar: the render gates the preview
		// on `! operation`, so a stale bar from a previous run would otherwise
		// hide the new preview and make Preview look like it did nothing.
		setOperation( null );
		apiFetch( {
			path: '/catalogops/v1/operations/preview',
			method: 'POST',
			data: { filter, actions: buildActions() },
		} )
			.then( setPreview )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	// Actually queue the operation. Reached only after the apply confirmation
	// (and, the first time, the backup acknowledgement) is satisfied.
	const doApply = () => {
		setConfirming( false );
		setBusy( true );
		setError( '' );
		// The preview is superseded by the running operation, and any previous
		// operation's bar is replaced by this one — clear both so the panel shows
		// the new run cleanly rather than a stale result.
		setPreview( null );
		setOperation( null );
		apiFetch( {
			path: '/catalogops/v1/operations',
			method: 'POST',
			data: { filter, actions: buildActions() },
		} )
			.then( setOperation )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	// Confirm the apply. The first time (no backup acknowledgement yet) the
	// checkbox is required and the acknowledgement is recorded so the reminder
	// does not nag on every later operation.
	const confirmApply = () => {
		if ( ! backupAck ) {
			if ( ! backupChecked ) {
				return;
			}
			apiFetch( {
				path: '/catalogops/v1/settings/onboarding',
				method: 'POST',
				data: { backup_ack: true },
			} ).catch( () => {} );
			if ( onBackupAck ) {
				onBackupAck();
			}
		}
		doApply();
	};

	const createSchedule = () => {
		setBusy( true );
		setError( '' );
		setScheduleMsg( '' );
		apiFetch( {
			path: '/catalogops/v1/schedules',
			method: 'POST',
			data: {
				name,
				filter,
				actions: buildActions(),
				recurrence,
				starts_at: startsAt,
				notify_email: notifyEmail,
			},
		} )
			.then( () => {
				setScheduleMsg( __( 'Schedule created.', 'catalogops' ) );
				if ( onScheduleCreated ) {
					onScheduleCreated();
				}
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const running = operation && ! isTerminal( operation );

	// The preview reports counts and reasons: how many the filter matched, of
	// those how many the edit will actually change (applicable), and — for the
	// rest — which rule left each one out. An item is omitted when a field the
	// change reads is empty, or when WooCommerce would refuse the new value and
	// keep what was there. None-will-change is an all-omitted match.
	const noneWillChange =
		!! preview && preview.matched > 0 && preview.applicable === 0;
	const omittedBy = ( preview && preview.omitted_by ) || [];
	const previewWarnings = ( preview && preview.warnings ) || [];

	/**
	 * How many items were omitted under one reason code.
	 *
	 * @param {string} reason The skip-reason code.
	 * @return {number} Items omitted under it.
	 */
	const omittedFor = ( reason ) =>
		omittedBy.reduce(
			( total, item ) => ( item.reason === reason ? item.count : total ),
			0
		);

	// The sale-price rule is about the value being typed, not the sale price a
	// product already has — a distinction the reason list states but which is much
	// easier to see with the actual number in it.
	const saleCeiling =
		mode === 'set' &&
		field === 'sale_price' &&
		value !== '' &&
		! Number.isNaN( Number( value ) ) &&
		omittedFor( 'sale_not_below_regular' ) > 0
			? sprintf(
					/* translators: %s: the sale price the user typed. */
					__(
						'You are setting the sale price to %s. WooCommerce keeps it only on products whose regular price is higher than that — so a lower value will reach more of them.',
						'catalogops'
					),
					value
			  )
			: '';

	return (
		<div className="catalogops-bulk-edit">
			<h2>{ __( 'Bulk edit', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Change a field for every item in the filter above. Preview shows old → new; nothing is written until you Apply. You can also schedule it to run later or on a recurring basis.',
					'catalogops'
				) }
			</p>

			<div className="catalogops-controls">
				<div className="catalogops-control-group">
					<span className="catalogops-group-label">
						{ __( 'Change', 'catalogops' ) }
					</span>
					<div className="catalogops-filter-rows">
						<div className="catalogops-filter-row">
							<div className="catalogops-segmented" role="group">
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										mode === 'set' ? ' is-active' : ''
									}` }
									onClick={ () => changeMode( 'set' ) }
								>
									{ __( 'Set to', 'catalogops' ) }
								</button>
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										mode === 'percent' ? ' is-active' : ''
									}${ canFormulas ? '' : ' is-locked' }` }
									onClick={ () => changeMode( 'percent' ) }
									disabled={ ! canFormulas }
									title={
										canFormulas
											? undefined
											: __(
													'Available on a paid plan',
													'catalogops'
											  )
									}
								>
									{ __( 'Percent', 'catalogops' ) }
								</button>
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										mode === 'formula' ? ' is-active' : ''
									}${ canFormulas ? '' : ' is-locked' }` }
									onClick={ () => changeMode( 'formula' ) }
									disabled={ ! canFormulas }
									title={
										canFormulas
											? undefined
											: __(
													'Available on a paid plan',
													'catalogops'
											  )
									}
								>
									{ __( 'Formula', 'catalogops' ) }
								</button>
							</div>
							{ ! canFormulas && (
								<UpsellNotice>
									{ __(
										'Percent and formula edits are available on a paid plan. Free plans can set a fixed value.',
										'catalogops'
									) }
								</UpsellNotice>
							) }
						</div>

						<div className="catalogops-filter-row">
							<div className="catalogops-field">
								<label htmlFor="catalogops-field">
									{ __( 'Field', 'catalogops' ) }
								</label>
								<select
									id="catalogops-field"
									value={ field }
									onChange={ ( e ) => {
										setField( e.target.value );
										setValue( '' );
										setPreview( null );
									} }
								>
									{ fieldOptions.map( ( f ) => (
										<option key={ f.key } value={ f.key }>
											{ f.label }
										</option>
									) ) }
								</select>
							</div>

							{ mode === 'set' && (
								<div className="catalogops-field">
									<label htmlFor="catalogops-value">
										{ __( 'To', 'catalogops' ) }
									</label>
									{ field === 'stock_status' ? (
										<select
											id="catalogops-value"
											value={ value }
											onChange={ ( e ) =>
												setValue( e.target.value )
											}
										>
											<option value="">
												{ __(
													'Choose…',
													'catalogops'
												) }
											</option>
											<option value="instock">
												{ __(
													'In stock',
													'catalogops'
												) }
											</option>
											<option value="outofstock">
												{ __(
													'Out of stock',
													'catalogops'
												) }
											</option>
											<option value="onbackorder">
												{ __(
													'On backorder',
													'catalogops'
												) }
											</option>
										</select>
									) : (
										<input
											id="catalogops-value"
											type="text"
											value={ value }
											onChange={ ( e ) =>
												setValue( e.target.value )
											}
										/>
									) }
								</div>
							) }

							{ mode === 'percent' && (
								<div className="catalogops-field">
									<span
										className="catalogops-field-label"
										id="catalogops-direction-label"
									>
										{ __( 'Direction', 'catalogops' ) }
									</span>
									{ /* Which way the price moves is a choice, not a
									     character to remember to type: a bare "-10"
									     and "10" differ by one keystroke, and the
									     one you get by forgetting it raises prices
									     across the catalogue. */ }
									<div
										className="catalogops-segmented catalogops-segmented--compact"
										role="group"
										aria-labelledby="catalogops-direction-label"
									>
										<button
											type="button"
											className={ `catalogops-segmented__btn${
												direction === 'increase'
													? ' is-active'
													: ''
											}` }
											onClick={ () =>
												setDirection( 'increase' )
											}
										>
											{ __( 'Increase', 'catalogops' ) }
										</button>
										<button
											type="button"
											className={ `catalogops-segmented__btn${
												direction === 'decrease'
													? ' is-active'
													: ''
											}` }
											onClick={ () =>
												setDirection( 'decrease' )
											}
										>
											{ __( 'Decrease', 'catalogops' ) }
										</button>
									</div>
								</div>
							) }

							{ mode === 'percent' && (
								<div className="catalogops-field">
									<label htmlFor="catalogops-percent">
										{ __( 'By (%)', 'catalogops' ) }
									</label>
									<input
										id="catalogops-percent"
										type="number"
										step="any"
										min="0"
										max={
											direction === 'decrease'
												? MAX_DECREASE
												: undefined
										}
										aria-invalid={ percentTooDeep }
										aria-describedby={
											percentTooDeep
												? 'catalogops-percent-error'
												: undefined
										}
										value={ percent }
										onChange={ ( e ) =>
											setPercent( e.target.value )
										}
									/>
								</div>
							) }

							{ mode === 'formula' && (
								<div className="catalogops-field catalogops-field--formula">
									<label htmlFor="catalogops-expression">
										{ __( 'Formula', 'catalogops' ) }
									</label>
									<textarea
										id="catalogops-expression"
										className="catalogops-formula-input"
										rows={ 3 }
										placeholder="roundto( cost * 1.35, 0.99 )"
										value={ expression }
										onChange={ ( e ) =>
											setExpression( e.target.value )
										}
									/>
									<div className="catalogops-formula-guide">
										<p>
											{ __(
												'Write a math expression. It is calculated for each product and becomes the new value of the field above.',
												'catalogops'
											) }
										</p>
										<ul>
											<li>
												<strong>
													{ __(
														'Values',
														'catalogops'
													) }
													:
												</strong>{ ' ' }
												<code>regular_price</code>,{ ' ' }
												<code>sale_price</code>,{ ' ' }
												<code>cost</code>
											</li>
											<li>
												<strong>
													{ __(
														'Math',
														'catalogops'
													) }
													:
												</strong>{ ' ' }
												<code>+ - * / ( )</code>
											</li>
											<li>
												<strong>
													{ __(
														'Functions',
														'catalogops'
													) }
													:
												</strong>{ ' ' }
												<code>round</code>,{ ' ' }
												<code>ceil</code>,{ ' ' }
												<code>floor</code>,{ ' ' }
												<code>
													roundto(value, step)
												</code>
												, <code>min</code>,{ ' ' }
												<code>max</code>,{ ' ' }
												<code>abs</code>
											</li>
										</ul>
										<p>
											{ __(
												'“cost” is your product cost (cost of goods). It needs a cost field — the _catalogops_cost meta, or a cost plugin mapped to it. Products with no cost are skipped.',
												'catalogops'
											) }
										</p>
										<p>
											<strong>
												{ __(
													'Examples',
													'catalogops'
												) }
												:
											</strong>
										</p>
										<ul>
											<li>
												<code>regular_price * 1.2</code>{ ' ' }
												—{ ' ' }
												{ __(
													'raise the price by 20%',
													'catalogops'
												) }
											</li>
											<li>
												<code>
													roundto( cost * 1.35, 0.99 )
												</code>{ ' ' }
												—{ ' ' }
												{ __(
													'35% markup on cost, rounded to end in .99',
													'catalogops'
												) }
											</li>
											<li>
												<code>
													max( regular_price * 0.8,
													cost * 1.1 )
												</code>{ ' ' }
												—{ ' ' }
												{ __(
													'20% off, but never below cost + 10%',
													'catalogops'
												) }
											</li>
										</ul>
										<p className="catalogops-muted">
											{ __(
												'Empty or non-numeric fields are skipped — never set to 0.',
												'catalogops'
											) }
										</p>
									</div>
								</div>
							) }
						</div>

						{ /* Full width and on its own line: inside the field the
						     sentence sets the control's width and pushes the rest
						     of the row onto a second line. */ }
						{ percentTooDeep && (
							<div className="catalogops-filter-row">
								<p
									className="catalogops-field-error"
									id="catalogops-percent-error"
								>
									{ sprintf(
										/* translators: %d: the largest decrease allowed, 100. */
										__(
											'A decrease cannot go past %d%% — beyond that the price would come out negative.',
											'catalogops'
										),
										MAX_DECREASE
									) }
								</p>
							</div>
						) }

						<div className="catalogops-filter-row">
							<p className="catalogops-field-hint">
								{ changeHint(
									field,
									mode,
									mode === 'percent'
										? percentExpression
										: expression,
									{
										direction,
										// A rejected amount is not described as
										// though it were about to run.
										amount: percentTooDeep ? '' : percent,
									}
								) }
							</p>
						</div>

						<div className="catalogops-filter-row">
							<button
								className="button"
								onClick={ runPreview }
								disabled={ busy || running || ! ready }
							>
								{ __( 'Preview', 'catalogops' ) }
							</button>
							<button
								className="button button-primary"
								onClick={ () => setConfirming( true ) }
								disabled={
									busy || running || ! ready || confirming
								}
							>
								{ __( 'Apply', 'catalogops' ) }
							</button>
							{ busy && (
								<span
									className="catalogops-inline-loading"
									aria-live="polite"
								>
									<span
										className="catalogops-spinner"
										aria-hidden="true"
									/>
									{ __( 'Working…', 'catalogops' ) }
								</span>
							) }
						</div>
					</div>
				</div>
			</div>

			{ confirming && (
				<div className="catalogops-confirm">
					{ ! backupAck ? (
						<>
							<p className="catalogops-confirm__lead">
								{ __(
									'Before your first change: CatalogOps is safe, but it is not a backup.',
									'catalogops'
								) }
							</p>
							<label
								className="catalogops-confirm__ack"
								htmlFor="catalogops-backup-ack"
							>
								<input
									id="catalogops-backup-ack"
									type="checkbox"
									checked={ backupChecked }
									onChange={ ( e ) =>
										setBackupChecked( e.target.checked )
									}
								/>
								{ sprintf(
									/* translators: %d: the number of days changes remain reversible. */
									__(
										'I have a recent backup, and I understand this change can be undone for %d days from History.',
										'catalogops'
									),
									retentionDays || 30
								) }
							</label>
						</>
					) : (
						<p className="catalogops-confirm__lead">
							{ __(
								'Apply this change to every matching item?',
								'catalogops'
							) }
						</p>
					) }
					<div className="catalogops-confirm__actions">
						<button
							className="button button-primary"
							onClick={ confirmApply }
							disabled={
								busy || ( ! backupAck && ! backupChecked )
							}
						>
							{ __( 'Apply now', 'catalogops' ) }
						</button>
						<button
							className="button"
							onClick={ () => setConfirming( false ) }
							disabled={ busy }
						>
							{ __( 'Cancel', 'catalogops' ) }
						</button>
					</div>
				</div>
			) }

			{ mode === 'percent' && percentExpression && (
				<p className="description catalogops-formula-help">
					{ sprintf(
						/* translators: %s: the generated formula. */
						__( 'Applies: %s', 'catalogops' ),
						percentExpression
					) }
				</p>
			) }

			<div className="catalogops-controls catalogops-schedule-form">
				<div className="catalogops-control-group">
					<button
						type="button"
						className={ `catalogops-collapse-toggle catalogops-group-label${
							canSchedule ? '' : ' is-locked'
						}` }
						onClick={ () => setShowSchedule( ! showSchedule ) }
						aria-expanded={ canSchedule && showSchedule }
						disabled={ ! canSchedule }
						title={
							canSchedule
								? undefined
								: __( 'Available on a paid plan', 'catalogops' )
						}
					>
						{ __( 'Scheduling', 'catalogops' ) }
						<svg
							className="catalogops-collapse-toggle__arrow"
							width="12"
							height="12"
							viewBox="0 0 12 12"
							aria-hidden="true"
							focusable="false"
						>
							<path
								d={
									showSchedule
										? 'M2.5 7.5 6 4 9.5 7.5'
										: 'M2.5 4.5 6 8 9.5 4.5'
								}
								fill="none"
								stroke="currentColor"
								strokeWidth="1.6"
								strokeLinecap="round"
								strokeLinejoin="round"
							/>
						</svg>
					</button>
					{ ! canSchedule && (
						<UpsellNotice>
							{ __(
								'Schedule changes to run later or on a recurring basis with a paid plan.',
								'catalogops'
							) }
						</UpsellNotice>
					) }
					{ canSchedule && showSchedule && (
						<div className="catalogops-filter-rows">
							{ /* Before the form, not after it: a schedule nothing
							     drives is a promise the site cannot keep, so this
							     has to be read before the first one is created. */ }
							<SchedulerSetup lead />

							<div className="catalogops-filter-row">
								<div className="catalogops-field">
									<label htmlFor="catalogops-sched-name">
										{ __( 'Name', 'catalogops' ) }
									</label>
									<input
										id="catalogops-sched-name"
										type="text"
										value={ name }
										onChange={ ( e ) =>
											setName( e.target.value )
										}
									/>
								</div>
							</div>

							<div className="catalogops-filter-row">
								<div className="catalogops-field catalogops-field--repeat">
									<label htmlFor="catalogops-sched-recur">
										{ __( 'Repeat', 'catalogops' ) }
									</label>
									<select
										id="catalogops-sched-recur"
										value={ recurrence }
										onChange={ ( e ) =>
											setRecurrence( e.target.value )
										}
									>
										{ RECURRENCES.map( ( r ) => (
											<option
												key={ r.value }
												value={ r.value }
											>
												{ r.label }
											</option>
										) ) }
									</select>
									<p className="catalogops-field-hint">
										{ __(
											'The filter is re-checked each run, so a repeating schedule keeps applying to new or changed products that match — not just today’s. Use “Once” for a one-time change.',
											'catalogops'
										) }
									</p>
								</div>
								<div className="catalogops-field">
									<label htmlFor="catalogops-sched-start">
										{ __( 'Start', 'catalogops' ) }
									</label>
									<input
										id="catalogops-sched-start"
										type="datetime-local"
										value={ startsAt }
										onChange={ ( e ) =>
											setStartsAt( e.target.value )
										}
									/>
									<p className="catalogops-field-hint">
										{ __(
											'Leave empty to start at the next run.',
											'catalogops'
										) }
									</p>
								</div>
							</div>

							<div className="catalogops-filter-row">
								<div className="catalogops-field">
									<label htmlFor="catalogops-sched-email">
										{ __(
											'Send notification to',
											'catalogops'
										) }
									</label>
									<input
										id="catalogops-sched-email"
										type="email"
										placeholder={ __(
											'site admin',
											'catalogops'
										) }
										value={ notifyEmail }
										onChange={ ( e ) =>
											setNotifyEmail( e.target.value )
										}
									/>
								</div>
							</div>

							<div className="catalogops-filter-row catalogops-schedule-preview">
								{ preview ? (
									<>
										<p>
											{ sprintf(
												/* translators: 1: matched products, 2: products that will change, 3: products that will not. */
												__(
													'As of now: %1$d matched · %2$d would change · %3$d would not.',
													'catalogops'
												),
												preview.matched,
												preview.applicable,
												preview.omitted
											) }
										</p>
										{ omittedBy.length > 0 && (
											<ReasonList items={ omittedBy } />
										) }
									</>
								) : (
									<p>
										{ __(
											'Run Preview first to see how many items this would change, and why the rest would not.',
											'catalogops'
										) }
									</p>
								) }
								<p className="catalogops-muted">
									{ __(
										'A schedule re-checks the catalog every time it runs, so these numbers can differ when it fires — the same rules decide, against the catalog as it is then.',
										'catalogops'
									) }
								</p>
							</div>

							<div className="catalogops-filter-row">
								<button
									className="button button-primary"
									onClick={ createSchedule }
									disabled={ busy || ! ready }
								>
									{ __( 'Create schedule', 'catalogops' ) }
								</button>
								{ busy && (
									<span
										className="catalogops-inline-loading"
										aria-live="polite"
									>
										<span
											className="catalogops-spinner"
											aria-hidden="true"
										/>
										{ __( 'Working…', 'catalogops' ) }
									</span>
								) }
							</div>
							{ scheduleMsg && (
								<p className="catalogops-saved">
									{ scheduleMsg }
								</p>
							) }
						</div>
					) }
				</div>
			</div>

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ preview && ! operation && (
				<div className="catalogops-preview">
					{ preview.matched === 0 && (
						<div className="notice notice-info">
							<p>
								{ __(
									'No products match this filter.',
									'catalogops'
								) }
							</p>
						</div>
					) }
					{ noneWillChange && (
						<div className="notice notice-warning">
							<p>
								{ sprintf(
									/* translators: %d: number of matched products. */
									__(
										'Preview: none of the %d matching products will change. Nothing will be written when you Apply.',
										'catalogops'
									),
									preview.matched
								) }
							</p>
							<ReasonList items={ omittedBy } />
							{ saleCeiling && <p>{ saleCeiling }</p> }
							{ filter.scope === 'product' &&
								omittedFor( 'empty_input' ) > 0 && (
									<p>
										{ __(
											'Tip: variable products keep their price, sale price, and cost on their variations, not on the parent — so a change to the parent is omitted. Use the Products / Variations toggle above the results to switch to Variations and edit those.',
											'catalogops'
										) }
									</p>
								) }
						</div>
					) }
					{ preview.matched > 0 && preview.applicable > 0 && (
						<div className="notice notice-success">
							<p>
								{ sprintf(
									/* translators: 1: matched products, 2: products that will change, 3: products that will not. */
									__(
										'Preview: %1$d matched · %2$d will change · %3$d will not.',
										'catalogops'
									),
									preview.matched,
									preview.applicable,
									preview.omitted
								) }
							</p>
							{ omittedBy.length > 0 && (
								<>
									<ReasonList items={ omittedBy } />
									{ saleCeiling && <p>{ saleCeiling }</p> }
									<p className="catalogops-muted">
										{ sprintf(
											/* translators: %d: number of products that will be updated. */
											__(
												'Only the %d that will change go into the operation, so its progress, history, and undo all match this number.',
												'catalogops'
											),
											preview.applicable
										) }
									</p>
								</>
							) }
						</div>
					) }
					{ previewWarnings.map( ( warning ) => (
						<div
							className="notice notice-warning"
							key={ warning.code }
						>
							<p>
								{ warningText( warning.code, warning.count ) }
							</p>
						</div>
					) ) }
				</div>
			) }

			{ operation && <ProgressBar op={ operation } /> }
		</div>
	);
}

/**
 * The audit detail for one operation: a page of its recorded deltas.
 *
 * @param {Object} props    Component props.
 * @param {number} props.id Operation id.
 */
const CHANGES_PER_PAGE = 10;

function ChangesTable( { id } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	// Draft is the input value; sku is the applied search (on Enter/Search).
	const [ draft, setDraft ] = useState( '' );
	const [ sku, setSku ] = useState( '' );

	useEffect( () => {
		const query = `page=${ page }&per_page=${ CHANGES_PER_PAGE }${
			sku ? `&sku=${ encodeURIComponent( sku ) }` : ''
		}`;
		setLoading( true );
		apiFetch( {
			path: `/catalogops/v1/operations/${ id }/changes?${ query }`,
		} )
			.then( setData )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setLoading( false ) );
	}, [ id, page, sku ] );

	const applySearch = () => {
		setPage( 1 );
		setSku( draft.trim() );
	};

	if ( error ) {
		return (
			<div className="notice notice-error">
				<p>{ error }</p>
			</div>
		);
	}
	if ( ! data ) {
		return (
			<p className="catalogops-loading">
				{ __( 'Loading…', 'catalogops' ) }
			</p>
		);
	}

	const pages = Math.max(
		1,
		Math.ceil( ( data.total || 0 ) / CHANGES_PER_PAGE )
	);

	// The whole run's skip breakdown, not just this page's — the reasons are the
	// answer to "why is applied smaller than the number I was shown", and paging
	// through rows to reconstruct that would be absurd.
	const skipBreakdown = ( data.skip_reasons || [] ).filter(
		( r ) => r.count > 0
	);

	return (
		<div>
			<div className="catalogops-results-bar catalogops-results-bar--end">
				<div className="catalogops-search">
					<input
						id={ `changes-search-${ id }` }
						type="search"
						placeholder={ __(
							'SKU, e.g. COPS-1234',
							'catalogops'
						) }
						aria-label={ __( 'Find by SKU', 'catalogops' ) }
						value={ draft }
						onChange={ ( e ) => setDraft( e.target.value ) }
						onKeyDown={ ( e ) =>
							e.key === 'Enter' && applySearch()
						}
					/>
					<button
						className="button"
						onClick={ applySearch }
						disabled={ loading }
					>
						{ __( 'Search', 'catalogops' ) }
					</button>
				</div>
			</div>

			{ loading && (
				<p className="catalogops-loading">
					{ __( 'Loading…', 'catalogops' ) }
				</p>
			) }

			{ skipBreakdown.length > 0 && (
				<div className="catalogops-skip-summary">
					<p>
						{ sprintf(
							/* translators: %d: number of items left unchanged. */
							__(
								'%d of this run’s items were not changed:',
								'catalogops'
							),
							data.counts.skipped
						) }
					</p>
					<ReasonList items={ skipBreakdown } />
				</div>
			) }

			<div
				className={ `catalogops-table-scroll${
					loading ? ' catalogops-loading-dim' : ''
				}` }
			>
				<table className="wp-list-table widefat striped catalogops-changes">
					<thead>
						<tr>
							<th>{ __( 'SKU', 'catalogops' ) }</th>
							<th>{ __( 'Item', 'catalogops' ) }</th>
							<th>{ __( 'Field', 'catalogops' ) }</th>
							<th>{ __( 'Old', 'catalogops' ) }</th>
							<th>{ __( 'New', 'catalogops' ) }</th>
							<th>{ __( 'Status', 'catalogops' ) }</th>
							<th>{ __( 'Why', 'catalogops' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.length === 0 ? (
							<tr className="catalogops-empty">
								<td colSpan="7">
									{ __(
										'No matching changes.',
										'catalogops'
									) }
								</td>
							</tr>
						) : (
							data.items.map( ( c, i ) => (
								<tr key={ i }>
									<td>
										{ c.sku || (
											<span className="catalogops-muted">
												{ `#${ c.object_id }` }
											</span>
										) }
									</td>
									<td>
										{ c.name || '—' }
										{ c.object_type === 'variation' && (
											<span className="catalogops-badge catalogops-badge--neutral catalogops-var-tag">
												{ __(
													'variation',
													'catalogops'
												) }
											</span>
										) }
									</td>
									<td>{ c.field_key }</td>
									<td className="catalogops-num">
										{ c.old_value }
									</td>
									<td className="catalogops-num">
										{ c.new_value }
									</td>
									<td>
										<span
											className={ `catalogops-badge catalogops-change-${ c.status }` }
										>
											{ c.status }
										</span>
									</td>
									<td className="catalogops-why">
										{ c.status === 'skipped' ? (
											skipReasonLabel( c.skip_reason )
										) : (
											<span className="catalogops-muted">
												—
											</span>
										) }
									</td>
								</tr>
							) )
						) }
					</tbody>
				</table>
			</div>

			<div className="catalogops-pagination">
				<button
					className="button"
					disabled={ page <= 1 || loading }
					onClick={ () => setPage( page - 1 ) }
				>
					{ __( 'Previous', 'catalogops' ) }
				</button>
				<span className="catalogops-page">
					{ sprintf(
						/* translators: 1: current page, 2: total pages. */
						__( 'Page %1$d of %2$d', 'catalogops' ),
						page,
						pages
					) }
				</span>
				<button
					className="button"
					disabled={ page >= pages || loading }
					onClick={ () => setPage( page + 1 ) }
				>
					{ __( 'Next', 'catalogops' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * The undo flow for one operation: preview how many changes revert versus drift,
 * choose a conflict policy, then run the undo and watch it.
 *
 * @param {Object}   props        Component props.
 * @param {Object}   props.op     The operation to undo.
 * @param {Function} props.onDone Called when the undo finishes.
 */
function UndoPanel( { op, onDone } ) {
	const [ policy, setPolicy ] = useState( 'skip' );
	const [ preview, setPreview ] = useState( null );
	const [ operation, setOperation ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	useOperationPoll( operation, setOperation, onDone );

	const loadPreview = useCallback(
		( withPolicy ) => {
			setBusy( true );
			setError( '' );
			apiFetch( {
				path: `/catalogops/v1/operations/${ op.id }/undo/preview`,
				method: 'POST',
				data: { conflict_policy: withPolicy },
			} )
				.then( setPreview )
				.catch( ( err ) => setError( err.message ) )
				.finally( () => setBusy( false ) );
		},
		[ op.id ]
	);

	useEffect( () => {
		loadPreview( policy );
	}, [ policy, loadPreview ] );

	const driftCount = preview
		? preview.sample.filter( ( s ) => s.drift ).length
		: 0;

	const runUndo = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Run this undo now?', 'catalogops' ) ) ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `/catalogops/v1/operations/${ op.id }/undo`,
			method: 'POST',
			data: { conflict_policy: policy },
		} )
			.then( setOperation )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const running = operation && ! isTerminal( operation );

	return (
		<div className="catalogops-undo" style={ { padding: '8px 0' } }>
			<fieldset>
				<legend>
					<strong>
						{ __( 'On conflict (drift):', 'catalogops' ) }
					</strong>
				</legend>
				<label
					htmlFor={ `policy-skip-${ op.id }` }
					style={ { marginRight: '12px' } }
				>
					<input
						id={ `policy-skip-${ op.id }` }
						type="radio"
						name={ `policy-${ op.id }` }
						checked={ policy === 'skip' }
						onChange={ () => setPolicy( 'skip' ) }
					/>{ ' ' }
					{ __( 'Skip changed objects (safe)', 'catalogops' ) }
				</label>
				<label htmlFor={ `policy-force-${ op.id }` }>
					<input
						id={ `policy-force-${ op.id }` }
						type="radio"
						name={ `policy-${ op.id }` }
						checked={ policy === 'force' }
						onChange={ () => setPolicy( 'force' ) }
					/>{ ' ' }
					{ __( 'Force — overwrite anyway', 'catalogops' ) }
				</label>
			</fieldset>

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ preview && ! operation && (
				<div className="catalogops-preview">
					<p>
						{ sprintf(
							/* translators: %d: number of recorded changes. */
							__( '%d changes will be reverted.', 'catalogops' ),
							preview.total
						) }
						{ driftCount > 0 &&
							' ' +
								sprintf(
									/* translators: %d: number of drifted objects in the sample. */
									__(
										'%d in this sample changed since the operation.',
										'catalogops'
									),
									driftCount
								) }
					</p>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'Object', 'catalogops' ) }</th>
								<th>{ __( 'Field', 'catalogops' ) }</th>
								<th>{ __( 'Now', 'catalogops' ) }</th>
								<th>{ __( 'Restore to', 'catalogops' ) }</th>
								<th>{ __( 'Outcome', 'catalogops' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.sample.map( ( s, i ) => (
								<tr
									key={ i }
									className={
										s.action === 'skip' ? 'is-drift' : ''
									}
								>
									<td>{ s.id }</td>
									<td>{ s.field }</td>
									<td className="catalogops-num">
										{ s.current }
									</td>
									<td className="catalogops-num">
										{ s.restore_to }
									</td>
									<td>
										{ s.action === 'skip' ? (
											<span className="catalogops-badge catalogops-badge--out">
												{ __(
													'skip (drift)',
													'catalogops'
												) }
											</span>
										) : (
											<span className="catalogops-badge catalogops-badge--neutral">
												{ __( 'revert', 'catalogops' ) }
											</span>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<button
						className="button button-primary"
						onClick={ runUndo }
						disabled={ busy || running || preview.total === 0 }
					>
						{ __( 'Run undo', 'catalogops' ) }
					</button>
				</div>
			) }

			{ operation && <ProgressBar op={ operation } /> }
		</div>
	);
}

/**
 * An icon button for a row action.
 *
 * Deliberately not a wp-admin `.button`: that class frames every one of them in
 * blue, so a row of three reads as a block of chrome rather than three distinct
 * actions, and its line-height leaves the glyph sitting high in the box. This is
 * a plain button the stylesheet owns end to end — white, grey-bordered, tinted
 * grey on hover, with the icon carrying the only colour.
 *
 * The label reaches everyone: `aria-label` names the button for screen readers,
 * `data-tooltip` draws a styled tooltip on hover and on keyboard focus. No
 * `title`, or the browser's own tooltip would surface on top of that one.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.icon     Dashicons name, without the `dashicons-` prefix.
 * @param {string}   props.label    What the button does.
 * @param {string}   props.variant  Colour role: 'view', 'undo' or 'danger'.
 * @param {Function} props.onClick  Click handler.
 * @param {boolean}  props.isActive Whether its panel is currently open.
 * @param {boolean}  props.disabled Whether it is unavailable.
 */
function IconButton( {
	icon,
	label,
	variant,
	onClick,
	isActive = false,
	disabled = false,
} ) {
	return (
		<button
			type="button"
			className={ `catalogops-icon-button catalogops-icon-button--${ variant }${
				isActive ? ' is-active' : ''
			}` }
			onClick={ onClick }
			disabled={ disabled }
			data-tooltip={ label }
			aria-label={ label }
		>
			<span
				className={ `dashicons dashicons-${ icon }` }
				aria-hidden="true"
			/>
		</button>
	);
}

/**
 * One row of the operation history, expandable to its audit detail or undo flow.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.op        The operation.
 * @param {Function} props.onChanged Called when an undo or delete from this row
 *                                   finishes, so the list reloads.
 */
function OperationRow( { op, onChanged } ) {
	const [ open, setOpen ] = useState( null ); // 'changes' | 'undo' | 'delete' | null
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const toggle = ( which ) =>
		setOpen( ( cur ) => ( cur === which ? null : which ) );

	// Deleting mid-write would strand the operation's remaining chunks, so the
	// control is closed off until the run is cancelled.
	const stillRunning = op.status === 'queued' || op.status === 'running';

	const confirmDelete = () => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `/catalogops/v1/operations/${ op.id }`,
			method: 'DELETE',
		} )
			.then( () => {
				setOpen( null );
				onChanged();
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<tr>
				<td>{ op.id }</td>
				<td>{ op.source }</td>
				<td>
					<span
						className={ `catalogops-badge catalogops-status-badge is-${ op.status }` }
					>
						{ op.status }
					</span>
				</td>
				<td className="catalogops-num">
					{ op.processed } / { op.target_count }
					{ op.failed > 0 &&
						' ' +
							sprintf(
								/* translators: %d: number of failed objects. */
								__( '(%d failed)', 'catalogops' ),
								op.failed
							) }
					{ op.status === 'queued' && (
						<span
							className="catalogops-inline-loading"
							aria-live="polite"
						>
							<span
								className="catalogops-spinner"
								aria-hidden="true"
							/>
							{ __( 'waiting to start', 'catalogops' ) }
						</span>
					) }
				</td>
				<td>{ op.user_name || '—' }</td>
				<td>{ op.created_at }</td>
				<td className="catalogops-cell--actions">
					<div className="catalogops-actions">
						<IconButton
							icon="list-view"
							variant="view"
							label={ __( 'View changes', 'catalogops' ) }
							onClick={ () => toggle( 'changes' ) }
							isActive={ open === 'changes' }
						/>
						{ op.can_undo && (
							<IconButton
								icon="undo"
								variant="undo"
								label={ __( 'Undo this run', 'catalogops' ) }
								onClick={ () => toggle( 'undo' ) }
								isActive={ open === 'undo' }
							/>
						) }
						<IconButton
							icon="trash"
							variant="danger"
							// A disabled control still owes an explanation; the
							// tooltip is where it fits.
							label={
								stillRunning
									? __(
											'Cancel the run before deleting it',
											'catalogops'
									  )
									: __( 'Delete from history', 'catalogops' )
							}
							onClick={ () => toggle( 'delete' ) }
							isActive={ open === 'delete' }
							disabled={ stillRunning }
						/>
					</div>
				</td>
			</tr>
			{ open && (
				<tr className="catalogops-detail">
					<td colSpan="7">
						{ open === 'changes' && <ChangesTable id={ op.id } /> }
						{ open === 'undo' && (
							<UndoPanel
								op={ op }
								onDone={ () => {
									setOpen( null );
									onChanged();
								} }
							/>
						) }
						{ open === 'delete' && (
							<div className="catalogops-confirm">
								<p className="catalogops-confirm__lead catalogops-confirm__lead--danger">
									{ sprintf(
										/* translators: %d: operation id. */
										__(
											'Delete operation #%d from the history?',
											'catalogops'
										),
										op.id
									) }
								</p>
								<p>
									{ op.can_undo
										? __(
												'This removes the record of what it changed, so it can no longer be undone. The products themselves are left exactly as they are now. This cannot be reversed.',
												'catalogops'
										  )
										: __(
												'This removes the record of what it changed. The products themselves are left exactly as they are now. This cannot be reversed.',
												'catalogops'
										  ) }
								</p>
								{ error && (
									<div className="notice notice-error">
										<p>{ error }</p>
									</div>
								) }
								<div className="catalogops-confirm__actions">
									<button
										className="button catalogops-button--danger"
										onClick={ confirmDelete }
										disabled={ busy }
									>
										{ __(
											'Delete permanently',
											'catalogops'
										) }
									</button>
									<button
										className="button"
										onClick={ () => setOpen( null ) }
										disabled={ busy }
									>
										{ __( 'Cancel', 'catalogops' ) }
									</button>
									{ busy && (
										<span
											className="catalogops-inline-loading"
											aria-live="polite"
										>
											<span
												className="catalogops-spinner"
												aria-hidden="true"
											/>
											{ __( 'Deleting…', 'catalogops' ) }
										</span>
									) }
								</div>
							</div>
						) }
					</td>
				</tr>
			) }
		</>
	);
}

/**
 * The operation history / audit log.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.refreshKey Bumping this reloads the list.
 * @param {Function} props.onChanged  Called when an undo finishes.
 */
function History( { refreshKey, onChanged } ) {
	const [ items, setItems ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ tick, setTick ] = useState( 0 );
	const timer = useRef( null );

	useEffect( () => {
		let cancelled = false;
		apiFetch( { path: '/catalogops/v1/operations' } )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setItems( res.items );
				// Keep polling while any operation is still moving, so a queued or
				// running op — e.g. a schedule's "Run now" — visibly progresses to
				// completion here without a manual refresh.
				if ( res.items.some( ( op ) => ! isTerminal( op ) ) ) {
					timer.current = setTimeout(
						() => setTick( ( t ) => t + 1 ),
						2000
					);
				}
			} )
			.catch( ( err ) => ! cancelled && setError( err.message ) );

		return () => {
			cancelled = true;
			clearTimeout( timer.current );
		};
	}, [ refreshKey, tick ] );

	return (
		<div className="catalogops-history">
			<h2>{ __( 'Operation history', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Every run is recorded here. Undo reverts a run; changed-since objects are skipped unless you force.',
					'catalogops'
				) }
			</p>

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'ID', 'catalogops' ) }</th>
						<th>{ __( 'Source', 'catalogops' ) }</th>
						<th>{ __( 'Status', 'catalogops' ) }</th>
						<th>{ __( 'Progress', 'catalogops' ) }</th>
						<th>{ __( 'By', 'catalogops' ) }</th>
						<th>{ __( 'Created', 'catalogops' ) }</th>
						<th className="catalogops-cell--actions">
							{ __( 'Actions', 'catalogops' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ items.length === 0 ? (
						<tr className="catalogops-empty">
							<td colSpan="7">
								{ __( 'No operations yet.', 'catalogops' ) }
							</td>
						</tr>
					) : (
						items.map( ( op ) => (
							<OperationRow
								key={ op.id }
								op={ op }
								onChanged={ onChanged }
							/>
						) )
					) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * The retention-window setting: how long recorded changes (the undo/audit
 * window) are kept before the daily purge removes them.
 */
function RetentionSetting() {
	const [ data, setData ] = useState( null );
	const [ days, setDays ] = useState( '' );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/settings/retention' } )
			.then( ( res ) => {
				setData( res );
				setDays( String( res.days ) );
			} )
			.catch( () => {} );
	}, [] );

	if ( ! data ) {
		return null;
	}

	const save = () => {
		setSaved( false );
		apiFetch( {
			path: '/catalogops/v1/settings/retention',
			method: 'PUT',
			data: { days: Number( days ) },
		} )
			.then( ( res ) => {
				setData( res );
				setDays( String( res.days ) );
				setSaved( true );
			} )
			.catch( () => {} );
	};

	return (
		<div className="catalogops-retention">
			<h2>{ __( 'Retention', 'catalogops' ) }</h2>
			<p className="description">
				{ sprintf(
					/* translators: 1: minimum days, 2: maximum days. */
					__(
						'How long recorded changes are kept — the window in which an operation can still be undone (%1$d–%2$d days).',
						'catalogops'
					),
					data.min,
					data.max
				) }
			</p>
			<input
				type="number"
				min={ data.min }
				max={ data.max }
				value={ days }
				onChange={ ( e ) => setDays( e.target.value ) }
				style={ { width: '6em' } }
			/>{ ' ' }
			<button className="button" onClick={ save }>
				{ __( 'Save', 'catalogops' ) }
			</button>
			{ saved && (
				<span style={ { marginLeft: '8px', color: '#1a7f37' } }>
					{ __( 'Saved.', 'catalogops' ) }
				</span>
			) }
		</div>
	);
}

/**
 * The list of scheduled operations, with pause/resume, run-now, and delete.
 *
 * @param {Object}   props            Component props.
 * @param {number}   props.refreshKey Bumping this reloads the list.
 * @param {Function} props.onRan      Called after a run-now, to refresh history.
 */
/**
 * Server details the setup instructions are built from, filled in by the PHP side
 * (see Admin_Page::cron_config). Missing config degrades to generic placeholders
 * rather than printing a broken command.
 */
const CRON = ( window.catalogopsConfig && window.catalogopsConfig.cron ) || {};

/**
 * Put text on the clipboard, wherever the admin happens to be served from.
 *
 * The async Clipboard API only exists in a secure context. A WordPress admin on
 * plain HTTP — a staging box, a local domain like example.test — has none, and
 * `navigator.clipboard` is simply undefined there, which is most of the places
 * these setup commands get read. So the deprecated execCommand path is not a
 * legacy nicety here; it is the one that actually runs.
 *
 * @param {string} text The text to copy.
 * @return {Promise} Resolves when copied, rejects when the browser refuses.
 */
function copyToClipboard( text ) {
	if ( window.isSecureContext && navigator.clipboard ) {
		return navigator.clipboard.writeText( text );
	}

	return new Promise( ( resolve, reject ) => {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		// Off-screen rather than hidden: a display:none field cannot be selected.
		area.style.position = 'fixed';
		area.style.top = '-1000px';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		area.setSelectionRange( 0, text.length );

		let copied = false;
		try {
			copied = document.execCommand( 'copy' );
		} catch {
			copied = false;
		}
		document.body.removeChild( area );

		if ( copied ) {
			resolve();
		} else {
			reject( new Error( 'copy refused' ) );
		}
	} );
}

/**
 * A command the user is meant to run, with a button that copies it.
 *
 * If the browser refuses to copy at all, the command's text is selected instead,
 * so Ctrl+C still works — a dead button in the middle of setup instructions is
 * worse than no button.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label What the command is for.
 * @param {string} props.code  The command itself.
 */
function CommandBox( { label, code } ) {
	const [ state, setState ] = useState( '' ); // '' | 'copied' | 'select'
	const pre = useRef( null );

	const selectCode = () => {
		const node = pre.current;
		const view = node && node.ownerDocument.defaultView;

		if ( ! view || ! view.getSelection ) {
			return;
		}

		const range = node.ownerDocument.createRange();
		range.selectNodeContents( node );

		const selection = view.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	};

	const copy = () => {
		copyToClipboard( code )
			.then( () => {
				setState( 'copied' );
				setTimeout( () => setState( '' ), 2000 );
			} )
			.catch( () => {
				selectCode();
				setState( 'select' );
			} );
	};

	return (
		<div className="catalogops-command">
			<div className="catalogops-command__head">
				<span>{ label }</span>
				<button
					type="button"
					className="button button-small"
					onClick={ copy }
				>
					{ state === 'copied' && __( 'Copied', 'catalogops' ) }
					{ state === 'select' &&
						__( 'Selected — press Ctrl+C', 'catalogops' ) }
					{ '' === state && __( 'Copy', 'catalogops' ) }
				</button>
			</div>
			<pre ref={ pre }>
				<code>{ code }</code>
			</pre>
		</div>
	);
}

/**
 * The one-time server setup a schedule depends on: a task or cron entry that runs
 * the queue every few minutes. Commands are printed with this install's own paths,
 * so they are copy-and-run rather than examples to adapt.
 *
 * Deliberately short. The reasoning behind it belongs in docs/scheduling.md; what
 * belongs here is the command and where to paste it.
 *
 * @param {Object}  props      Component props.
 * @param {boolean} props.lead Whether to show the standing one-line warning. Set
 *                             where a schedule is being created, so the dependency
 *                             is seen before the first one exists.
 */
function SchedulerSetup( { lead = false } ) {
	const [ open, setOpen ] = useState( false );
	const [ platform, setPlatform ] = useState(
		CRON.isWindows ? 'windows' : 'linux'
	);

	const cronUrl =
		CRON.cronUrl || 'https://example.com/wp-cron.php?doing_wp_cron=1';
	const minutes = CRON.supervisorMinutes || 5;

	// Both platforms fetch the same URL. Nothing to install: curl ships with
	// Windows 10 and later and with every Linux host, and hosting panels ask for
	// exactly this shape of command.
	const windowsArgs = `-s "${ cronUrl }"`;
	const linuxCron = `*/${ minutes } * * * * curl -s "${ cronUrl }" >/dev/null 2>&1`;

	// The dialog's "Run whether user is logged on or not" wants a password and the
	// "Log on as batch job" right, and often simply refuses. Running as SYSTEM does
	// the same thing with no password, which is one line here and unreachable there.
	const windowsPs =
		`Register-ScheduledTask -TaskName 'CatalogOps queue' -Force` +
		` -Action (New-ScheduledTaskAction -Execute 'curl.exe' -Argument '-s "${ cronUrl }"')` +
		` -Trigger (New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes ${ minutes }))` +
		` -Settings (New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable)` +
		` -Principal (New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest)`;

	return (
		<div className="catalogops-setup">
			{ lead && (
				<p className="catalogops-setup__lead">
					{ __(
						'A schedule runs only if the server is set up to run it. Do this once, before you create one.',
						'catalogops'
					) }
				</p>
			) }
			<button
				type="button"
				className="catalogops-collapse-toggle catalogops-group-label"
				onClick={ () => setOpen( ! open ) }
				aria-expanded={ open }
			>
				{ __( 'Server setup', 'catalogops' ) }
				<svg
					className="catalogops-collapse-toggle__arrow"
					width="12"
					height="12"
					viewBox="0 0 12 12"
					aria-hidden="true"
					focusable="false"
				>
					<path
						d={
							open
								? 'M2.5 7.5 6 4 9.5 7.5'
								: 'M2.5 4.5 6 8 9.5 4.5'
						}
						fill="none"
						stroke="currentColor"
						strokeWidth="1.6"
						strokeLinecap="round"
						strokeLinejoin="round"
					/>
				</svg>
			</button>

			{ open && (
				<div className="catalogops-setup__body">
					<p>
						{ sprintf(
							/* translators: %d: minutes between runs. */
							__(
								'Set the server to run the queue every %d minutes. Once only.',
								'catalogops'
							),
							minutes
						) }
					</p>

					<div className="catalogops-setup__tabs">
						<button
							type="button"
							className={ `catalogops-tab${
								platform === 'windows' ? ' is-active' : ''
							}` }
							onClick={ () => setPlatform( 'windows' ) }
						>
							{ __( 'Windows', 'catalogops' ) }
						</button>
						<button
							type="button"
							className={ `catalogops-tab${
								platform === 'linux' ? ' is-active' : ''
							}` }
							onClick={ () => setPlatform( 'linux' ) }
						>
							{ __( 'Server (cPanel / cron)', 'catalogops' ) }
						</button>
					</div>

					{ platform === 'windows' && (
						<div>
							<p>
								{ __(
									'Task Scheduler (Win+R → taskschd.msc):',
									'catalogops'
								) }
							</p>
							<ol className="catalogops-steps">
								<li>
									{ __(
										'Create Task… — not “Basic Task”.',
										'catalogops'
									) }
								</li>
								<li>
									{ __(
										'General: tick “Run whether user is logged on or not”.',
										'catalogops'
									) }
								</li>
								<li>
									{ sprintf(
										/* translators: %d: minutes between runs. */
										__(
											'Triggers → New: Daily, start 00:00, “Repeat task every” %d minutes, duration Indefinitely.',
											'catalogops'
										),
										minutes
									) }
								</li>
								<li>
									{ __(
										'Actions → New: Start a program, then paste the two boxes below.',
										'catalogops'
									) }
								</li>
								<li>
									{ __(
										'Settings: keep “Do not start a new instance”.',
										'catalogops'
									) }
								</li>
							</ol>
							<CommandBox
								label={ __( 'Program/script', 'catalogops' ) }
								code="curl.exe"
							/>
							<CommandBox
								label={ __( 'Add arguments', 'catalogops' ) }
								code={ windowsArgs }
							/>
							<p>
								{ __(
									'Or skip the dialog — run this in PowerShell as Administrator. It also runs when nobody is signed in, which the dialog asks for a password to allow:',
									'catalogops'
								) }
							</p>
							<CommandBox
								label={ __(
									'PowerShell (Administrator)',
									'catalogops'
								) }
								code={ windowsPs }
							/>
						</div>
					) }

					{ platform === 'linux' && (
						<div>
							<p>
								{ __(
									'cPanel → Cron Jobs (or crontab -e over SSH):',
									'catalogops'
								) }
							</p>
							<CommandBox
								label={ __( 'Command', 'catalogops' ) }
								code={ linuxCron }
							/>
						</div>
					) }
				</div>
			) }
		</div>
	);
}

/**
 * One row of the Schedules list, with its actions as icons and its own inline
 * delete confirmation.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.schedule The schedule.
 * @param {boolean}  props.busy     Whether a request for this row is in flight.
 * @param {Function} props.onAct    Runs a verb (run, pause, resume) on the row.
 * @param {Function} props.onDelete Deletes the row.
 */
function ScheduleRow( { schedule, busy, onAct, onDelete } ) {
	const [ confirming, setConfirming ] = useState( false );

	const done = schedule.status === 'completed';
	const name = schedule.name || `#${ schedule.id }`;

	return (
		<>
			<tr>
				<td>{ name }</td>
				<td>{ schedule.recurrence }</td>
				<td>
					<span
						className={ `catalogops-badge catalogops-status-badge is-${ schedule.status }` }
					>
						{ schedule.status }
					</span>
				</td>
				<td>
					{ done
						? '—'
						: schedule.next_run_local || schedule.next_run }
				</td>
				<td>{ schedule.last_run_local || schedule.last_run || '—' }</td>
				<td className="catalogops-cell--actions">
					<div className="catalogops-actions">
						{ /* Four actions, four meanings, no two alike: play runs it once
						     now, pause holds it, the cycle arrows put it back on its
						     schedule, the bin destroys it. */ }
						<IconButton
							icon="controls-play"
							variant="run"
							label={ __( 'Run now', 'catalogops' ) }
							onClick={ () => onAct( schedule.id, 'run' ) }
							disabled={ done || busy }
						/>
						{ schedule.status === 'active' && (
							<IconButton
								icon="controls-pause"
								variant="pause"
								label={ __( 'Pause', 'catalogops' ) }
								onClick={ () => onAct( schedule.id, 'pause' ) }
								disabled={ busy }
							/>
						) }
						{ schedule.status === 'paused' && (
							<IconButton
								icon="update"
								variant="accent"
								label={ __( 'Resume', 'catalogops' ) }
								onClick={ () => onAct( schedule.id, 'resume' ) }
								disabled={ busy }
							/>
						) }
						<IconButton
							icon="trash"
							variant="danger"
							label={ __( 'Delete schedule', 'catalogops' ) }
							onClick={ () => setConfirming( ! confirming ) }
							isActive={ confirming }
							disabled={ busy }
						/>
					</div>
				</td>
			</tr>
			{ confirming && (
				<tr className="catalogops-detail">
					<td colSpan="6">
						<div className="catalogops-confirm">
							<p className="catalogops-confirm__lead catalogops-confirm__lead--danger">
								{ sprintf(
									/* translators: %s: schedule name. */
									__(
										'Delete the schedule “%s”?',
										'catalogops'
									),
									name
								) }
							</p>
							<p>
								{ __(
									'It will stop running from now on. Operations it has already run stay in the history, and the products they changed are untouched. This cannot be reversed.',
									'catalogops'
								) }
							</p>
							<div className="catalogops-confirm__actions">
								<button
									className="button catalogops-button--danger"
									onClick={ () => {
										setConfirming( false );
										onDelete( schedule.id );
									} }
									disabled={ busy }
								>
									{ __( 'Delete permanently', 'catalogops' ) }
								</button>
								<button
									className="button"
									onClick={ () => setConfirming( false ) }
									disabled={ busy }
								>
									{ __( 'Cancel', 'catalogops' ) }
								</button>
							</div>
						</div>
					</td>
				</tr>
			) }
		</>
	);
}

function Schedules( { refreshKey, onRan } ) {
	const [ items, setItems ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ localKey, setLocalKey ] = useState( 0 );
	// The schedule row a request is in flight for, so its buttons show a spinner
	// and disable rather than leaving the user unsure anything happened.
	const [ busyId, setBusyId ] = useState( null );
	const [ runMsg, setRunMsg ] = useState( '' );

	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/schedules' } )
			.then( ( res ) => setItems( res.items ) )
			.catch( ( err ) => setError( err.message ) );
	}, [ refreshKey, localKey ] );

	const reload = () => setLocalKey( ( k ) => k + 1 );

	const act = ( id, verb ) => {
		setError( '' );
		setRunMsg( '' );
		setBusyId( id );
		apiFetch( {
			path: `/catalogops/v1/schedules/${ id }/${ verb }`,
			method: 'POST',
		} )
			.then( () => {
				reload();
				if ( verb === 'run' ) {
					setRunMsg(
						__(
							'Queued — the operation runs in the background. Watch its progress in Operation history below (it may take a moment to start).',
							'catalogops'
						)
					);
					if ( onRan ) {
						onRan();
					}
				}
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusyId( null ) );
	};

	// No window.confirm: a browser dialog cannot say what is actually lost, cannot
	// be styled to look destructive, and appears somewhere other than the row it
	// belongs to. The row expands into its own confirmation instead, the same way
	// deleting from Operation history does.
	const remove = ( id ) => {
		setError( '' );
		setBusyId( id );
		apiFetch( {
			path: `/catalogops/v1/schedules/${ id }`,
			method: 'DELETE',
		} )
			.then( reload )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusyId( null ) );
	};

	return (
		<div className="catalogops-card catalogops-schedules">
			<h2>{ __( 'Schedules', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Operations set to run later or on a recurring basis. Create one from the Bulk edit panel above (“Schedule instead…”). A completion report is emailed for each run.',
					'catalogops'
				) }
			</p>

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ runMsg && (
				<div className="notice notice-info">
					<p>{ runMsg }</p>
				</div>
			) }

			<div className="catalogops-overlay-wrap">
				<table className="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>{ __( 'Name', 'catalogops' ) }</th>
							<th>{ __( 'Repeat', 'catalogops' ) }</th>
							<th>{ __( 'Status', 'catalogops' ) }</th>
							<th>{ __( 'Next run', 'catalogops' ) }</th>
							<th>{ __( 'Last run', 'catalogops' ) }</th>
							<th className="catalogops-cell--actions">
								{ __( 'Actions', 'catalogops' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 ? (
							<tr className="catalogops-empty">
								<td colSpan="6">
									{ __( 'No schedules yet.', 'catalogops' ) }
								</td>
							</tr>
						) : (
							items.map( ( s ) => (
								<ScheduleRow
									key={ s.id }
									schedule={ s }
									busy={ busyId === s.id }
									onAct={ act }
									onDelete={ remove }
								/>
							) )
						) }
					</tbody>
				</table>
				{ busyId !== null && (
					<div className="catalogops-overlay">
						<span
							className="catalogops-spinner"
							aria-hidden="true"
						/>
					</div>
				) }
			</div>
		</div>
	);
}

/**
 * First-run walkthrough. Shows once per user (until dismissed) and teaches the
 * three-step pipeline so a newcomer can run their first operation unaided
 * (CONTEXT §4 M6 DoD). Dismissal is recorded server-side.
 *
 * @param {Object}      props           Component props.
 * @param {Object|null} props.data      Onboarding state ({ tour_done, retention_days }).
 * @param {Function}    props.onDismiss Called when the tour is dismissed.
 */
function Onboarding( { data, onDismiss } ) {
	if ( ! data || data.tour_done ) {
		return null;
	}

	const dismiss = () => {
		apiFetch( {
			path: '/catalogops/v1/settings/onboarding',
			method: 'POST',
			data: { tour_done: true },
		} ).catch( () => {} );
		onDismiss();
	};

	const days = data.retention_days || 30;

	return (
		<div className="catalogops-card catalogops-onboarding">
			<button
				type="button"
				className="catalogops-onboarding__close"
				aria-label={ __( 'Dismiss', 'catalogops' ) }
				onClick={ dismiss }
			>
				×
			</button>
			<h2 className="catalogops-onboarding__title">
				{ __( 'Welcome to CatalogOps', 'catalogops' ) }
			</h2>
			<p className="catalogops-onboarding__lead">
				{ sprintf(
					/* translators: %d: the number of days changes remain reversible. */
					__(
						'Change thousands of products at once — safely. Every change is previewed before it is written, and any operation can be undone for %d days.',
						'catalogops'
					),
					days
				) }
			</p>
			<ol className="catalogops-onboarding__steps">
				<li>
					<strong>{ __( '1. Filter', 'catalogops' ) }</strong>
					<span>
						{ __(
							'Choose exactly which products or variations to change — by category, brand, price, stock, attribute, or SKU.',
							'catalogops'
						) }
					</span>
				</li>
				<li>
					<strong>{ __( '2. Preview', 'catalogops' ) }</strong>
					<span>
						{ __(
							'See every old → new value before anything happens. Nothing is written until you choose Apply.',
							'catalogops'
						) }
					</span>
				</li>
				<li>
					<strong>{ __( '3. Apply & undo', 'catalogops' ) }</strong>
					<span>
						{ __(
							'Run it as a background operation. Changed your mind? Undo the whole thing from History below.',
							'catalogops'
						) }
					</span>
				</li>
			</ol>
			<button className="button button-primary" onClick={ dismiss }>
				{ __( 'Got it — start with a filter', 'catalogops' ) }
			</button>
		</div>
	);
}

function App() {
	const [ form, setForm ] = useState( emptyForm );
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	// { scope, total } when this filter found nothing here but something in the
	// other scope; null the rest of the time (the server only sends it then).
	const [ otherScope, setOtherScope ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ historyKey, setHistoryKey ] = useState( 0 );
	// Bumped after an operation settles or a schedule is created, to clear the
	// filter, bulk-edit, and schedule inputs for a fresh start.
	const [ resetKey, setResetKey ] = useState( 0 );
	// Discovery data for the category and brand dropdowns. brandField is the
	// filter field a brand maps to (catalog-specific; supplied by the API).
	const [ categories, setCategories ] = useState( [] );
	const [ tags, setTags ] = useState( [] );
	const [ brands, setBrands ] = useState( [] );
	const [ brandField, setBrandField ] = useState( '' );
	const [ attributes, setAttributes ] = useState( [] );
	// Bumped whenever a schedule is created or acted on, to reload the list.
	const [ schedulesKey, setSchedulesKey ] = useState( 0 );
	// Whether the filter, table, and bulk edit target parent products or their
	// variations (CONTEXT §4).
	const [ scope, setScope ] = useState( 'product' );
	// The filter that was actually applied to the table (frozen on Apply), so
	// bulk edits target what the user is looking at.
	const [ appliedFilter, setAppliedFilter ] = useState( () =>
		buildFilter( emptyForm(), 'product', '' )
	);

	// First-run onboarding + the mandatory backup acknowledgement (CONTEXT §9).
	// Fetched once; on error, fail safe — skip the tour but still show the
	// first-operation backup reminder.
	const [ onboarding, setOnboarding ] = useState( null );
	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/settings/onboarding' } )
			.then( setOnboarding )
			.catch( () =>
				setOnboarding( {
					tour_done: true,
					backup_ack: false,
					retention_days: 30,
				} )
			);
	}, [] );

	// Load the category and brand dropdowns once.
	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/fields/categories' } )
			.then( ( res ) => setCategories( res.categories || [] ) )
			.catch( () => {} );
		apiFetch( { path: '/catalogops/v1/fields/tags' } )
			.then( ( res ) => setTags( res.tags || [] ) )
			.catch( () => {} );
		apiFetch( { path: '/catalogops/v1/fields/brands' } )
			.then( ( res ) => {
				setBrands( res.brands || [] );
				setBrandField( res.field || '' );
			} )
			.catch( () => {} );
		apiFetch( { path: '/catalogops/v1/fields/attributes' } )
			.then( ( res ) => setAttributes( res.attributes || [] ) )
			.catch( () => {} );
	}, [] );

	// The terms of the currently-selected attribute, for the value dropdown.
	const selectedAttribute = attributes.find(
		( a ) => a.field === form.attribute
	);

	const run = useCallback(
		( toPage ) => {
			const filter = buildFilter( form, scope, brandField );
			setAppliedFilter( filter );
			setLoading( true );
			setError( '' );
			// Drop the previous run's suggestion up front: a failed request would
			// otherwise leave it pointing at a result that is no longer on screen.
			setOtherScope( null );
			apiFetch( {
				path: '/catalogops/v1/products/query',
				method: 'POST',
				data: {
					filter,
					page: toPage,
					per_page: PER_PAGE,
				},
			} )
				.then( ( res ) => {
					setItems( res.items );
					setTotal( res.total );
					setPage( res.page );
					setOtherScope( res.other_scope || null );
				} )
				.catch( ( err ) =>
					setError(
						err.message || __( 'Request failed.', 'catalogops' )
					)
				)
				.finally( () => setLoading( false ) );
		},
		[ form, scope, brandField ]
	);

	useEffect( () => {
		run( 1 );
		// Reload on mount and whenever the scope toggles.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ scope ] );

	// Refresh the product table and the history list after any operation.
	const refreshAll = useCallback( () => {
		run( page );
		setHistoryKey( ( k ) => k + 1 );
	}, [ run, page ] );

	// Clear the filter, its results, and (via resetKey) the bulk-edit and
	// schedule inputs — a clean slate for the next operation.
	const resetAll = useCallback( () => {
		const empty = emptyForm();
		setForm( empty );
		setAppliedFilter( buildFilter( empty, scope, brandField ) );
		setItems( [] );
		setTotal( 0 );
		setOtherScope( null );
		setPage( 1 );
		setResetKey( ( k ) => k + 1 );
	}, [ scope, brandField ] );

	// Apply: reset only once the operation has settled (its ProgressBar stays).
	const onApplyDone = useCallback( () => {
		setHistoryKey( ( k ) => k + 1 );
		resetAll();
	}, [ resetAll ] );

	// Schedule: reset once it has been created (the confirmation message stays).
	const onScheduleCreated = useCallback( () => {
		setSchedulesKey( ( k ) => k + 1 );
		resetAll();
	}, [ resetAll ] );

	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const update = ( key ) => ( event ) =>
		setForm( { ...form, [ key ]: event.target.value } );

	return (
		<div className="catalogops">
			<div className="catalogops-brand">
				<svg
					className="catalogops-brand__mark"
					viewBox="0 0 40 40"
					width="40"
					height="40"
					aria-hidden="true"
					focusable="false"
					xmlns="http://www.w3.org/2000/svg"
				>
					<defs>
						<linearGradient
							id="catalogops-brand-g"
							x1="0"
							y1="0"
							x2="40"
							y2="40"
							gradientUnits="userSpaceOnUse"
						>
							<stop offset="0" stopColor="#4f46e5" />
							<stop offset="1" stopColor="#4338ca" />
						</linearGradient>
					</defs>
					<rect
						width="40"
						height="40"
						rx="9"
						fill="url(#catalogops-brand-g)"
					/>
					<path
						d="M20 9 L31 15 L20 21 L9 15 Z"
						fill="#fff"
						fillOpacity="0.95"
					/>
					<path
						d="M9 20 L20 26 L31 20"
						fill="none"
						stroke="#fff"
						strokeWidth="2.2"
						strokeLinecap="round"
						strokeLinejoin="round"
						strokeOpacity="0.7"
					/>
					<path
						d="M9 25 L20 31 L31 25"
						fill="none"
						stroke="#fff"
						strokeWidth="2.2"
						strokeLinecap="round"
						strokeLinejoin="round"
						strokeOpacity="0.45"
					/>
				</svg>
				<span className="catalogops-brand__text">
					<span className="catalogops-brand__name">
						Catalog<b>Ops</b>
					</span>
					<span className="catalogops-brand__tag">
						{ __( 'Bulk catalog operations', 'catalogops' ) }
					</span>
				</span>
			</div>

			<Onboarding
				data={ onboarding }
				onDismiss={ () =>
					setOnboarding( ( o ) => ( { ...o, tour_done: true } ) )
				}
			/>

			<div className="catalogops-card catalogops-browse">
				<h2>{ __( 'Filter products', 'catalogops' ) }</h2>
				<div className="catalogops-controls">
					<div className="catalogops-control-group">
						<span className="catalogops-group-label">
							{ __( 'Target', 'catalogops' ) }
						</span>
						<div className="catalogops-segmented" role="group">
							<button
								type="button"
								className={ `catalogops-segmented__btn${
									scope === 'product' ? ' is-active' : ''
								}` }
								onClick={ () => setScope( 'product' ) }
							>
								{ __( 'Products', 'catalogops' ) }
							</button>
							<button
								type="button"
								className={ `catalogops-segmented__btn${
									scope === 'variation' ? ' is-active' : ''
								}` }
								onClick={ () => setScope( 'variation' ) }
							>
								{ __( 'Variations', 'catalogops' ) }
							</button>
						</div>
					</div>

					<div className="catalogops-control-group">
						<span className="catalogops-group-label">
							{ __( 'Filter', 'catalogops' ) }
						</span>
						<div className="catalogops-filter-rows">
							<div className="catalogops-filter-row">
								<div className="catalogops-field catalogops-field--multi">
									<MultiSelect
										label={ __( 'Category', 'catalogops' ) }
										options={ categories }
										value={ form.category }
										onChange={ ( ids ) =>
											setForm( {
												...form,
												category: ids,
											} )
										}
										mode={ form.categoryMode }
										onModeChange={ ( next ) =>
											setForm( {
												...form,
												categoryMode: next,
											} )
										}
									/>
								</div>

								<div className="catalogops-field catalogops-field--multi">
									<MultiSelect
										label={ __( 'Brand', 'catalogops' ) }
										options={ brands.map( ( b ) => ( {
											id: b,
											name: b,
										} ) ) }
										value={ form.brand }
										onChange={ ( ids ) =>
											setForm( { ...form, brand: ids } )
										}
										mode={ form.brandMode }
										onModeChange={ ( next ) =>
											setForm( {
												...form,
												brandMode: next,
											} )
										}
									/>
								</div>

								<div className="catalogops-field catalogops-field--multi">
									<MultiSelect
										label={ __( 'Tag', 'catalogops' ) }
										options={ tags }
										value={ form.tag }
										onChange={ ( ids ) =>
											setForm( {
												...form,
												tag: ids,
											} )
										}
										mode={ form.tagMode }
										onModeChange={ ( next ) =>
											setForm( {
												...form,
												tagMode: next,
											} )
										}
									/>
								</div>

								<div className="catalogops-field">
									<label htmlFor="catalogops-stock">
										{ __( 'Stock', 'catalogops' ) }
									</label>
									<select
										id="catalogops-stock"
										value={ form.stockStatus }
										onChange={ update( 'stockStatus' ) }
									>
										<option value="">
											{ __( 'Any', 'catalogops' ) }
										</option>
										<option value="instock">
											{ __( 'In stock', 'catalogops' ) }
										</option>
										<option value="outofstock">
											{ __(
												'Out of stock',
												'catalogops'
											) }
										</option>
									</select>
								</div>
							</div>

							<div className="catalogops-filter-row">
								{ 'variation' === scope &&
									attributes.length > 0 && (
										<div className="catalogops-field">
											<label htmlFor="catalogops-attribute">
												{ __(
													'Attribute',
													'catalogops'
												) }
											</label>
											<select
												id="catalogops-attribute"
												value={ form.attribute }
												onChange={ ( e ) =>
													setForm( {
														...form,
														attribute:
															e.target.value,
														attributeValues: [],
													} )
												}
											>
												<option value="">
													{ __(
														'Any',
														'catalogops'
													) }
												</option>
												{ attributes.map( ( a ) => (
													<option
														key={ a.field }
														value={ a.field }
													>
														{ a.label }
													</option>
												) ) }
											</select>
										</div>
									) }

								{ 'variation' === scope &&
									attributes.length > 0 &&
									selectedAttribute && (
										<div className="catalogops-field catalogops-field--multi">
											<MultiSelect
												label={
													'not_in' ===
													form.attributeMode
														? __(
																'Values (none if empty)',
																'catalogops'
														  )
														: __(
																'Values (any if empty)',
																'catalogops'
														  )
												}
												options={
													selectedAttribute.terms
												}
												value={ form.attributeValues }
												onChange={ ( ids ) =>
													setForm( {
														...form,
														attributeValues: ids,
													} )
												}
												mode={ form.attributeMode }
												onModeChange={ ( next ) =>
													setForm( {
														...form,
														attributeMode: next,
													} )
												}
											/>
										</div>
									) }

								<div className="catalogops-field catalogops-field--price">
									<label htmlFor="catalogops-price-min">
										{ __( 'Price range', 'catalogops' ) }
									</label>
									<div className="catalogops-price-inputs">
										<input
											id="catalogops-price-min"
											type="number"
											placeholder={ __(
												'Min',
												'catalogops'
											) }
											aria-label={ __(
												'Minimum price',
												'catalogops'
											) }
											value={ form.priceMin }
											onChange={ update( 'priceMin' ) }
										/>
										<input
											id="catalogops-price-max"
											type="number"
											placeholder={ __(
												'Max',
												'catalogops'
											) }
											aria-label={ __(
												'Maximum price',
												'catalogops'
											) }
											value={ form.priceMax }
											onChange={ update( 'priceMax' ) }
										/>
									</div>
								</div>
							</div>

							<div className="catalogops-filter-row">
								<button
									className="button button-primary"
									onClick={ () => run( 1 ) }
									disabled={ loading }
								>
									{ scope === 'variation'
										? __( 'Show variations', 'catalogops' )
										: __( 'Show products', 'catalogops' ) }
								</button>
							</div>
						</div>
					</div>
				</div>

				<hr className="catalogops-divider" />

				<div className="catalogops-results-bar">
					<p className="catalogops-status">
						{ loading && __( 'Loading…', 'catalogops' ) }
						{ ! loading &&
							scope === 'variation' &&
							sprintf(
								/* translators: %d: number of matching variations. */
								__( '%d matching variations', 'catalogops' ),
								total
							) }
						{ ! loading &&
							scope !== 'variation' &&
							sprintf(
								/* translators: %d: number of matching products. */
								__( '%d matching products', 'catalogops' ),
								total
							) }
					</p>
					<div className="catalogops-search">
						<input
							id="catalogops-sku"
							type="search"
							placeholder={ __(
								'SKU, e.g. COPS-1234',
								'catalogops'
							) }
							aria-label={ __( 'Find by SKU', 'catalogops' ) }
							value={ form.sku }
							onChange={ update( 'sku' ) }
							onKeyDown={ ( e ) => e.key === 'Enter' && run( 1 ) }
						/>
						<button
							className="button"
							onClick={ () => run( 1 ) }
							disabled={ loading }
						>
							{ __( 'Search', 'catalogops' ) }
						</button>
					</div>
				</div>

				{ error && (
					<div className="notice notice-error">
						<p>{ error }</p>
					</div>
				) }

				{ ! loading && otherScope && (
					<ScopeHint other={ otherScope } onSwitch={ setScope } />
				) }

				<table
					className={ `wp-list-table widefat fixed striped${
						loading ? ' catalogops-loading-dim' : ''
					}` }
				>
					<thead>
						<tr>
							<th>{ __( 'ID', 'catalogops' ) }</th>
							<th>{ __( 'Name', 'catalogops' ) }</th>
							<th>{ __( 'SKU', 'catalogops' ) }</th>
							<th className="catalogops-num">
								{ __( 'Cost', 'catalogops' ) }
							</th>
							<th className="catalogops-num">
								{ __( 'Price', 'catalogops' ) }
							</th>
							<th className="catalogops-num">
								{ __( 'Sale price', 'catalogops' ) }
							</th>
							<th>{ __( 'Stock', 'catalogops' ) }</th>
							<th className="catalogops-num">
								{ __( 'Qty', 'catalogops' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ items.length === 0 && ! loading ? (
							<tr>
								<td colSpan="8">
									{ __(
										'No items match this filter.',
										'catalogops'
									) }
								</td>
							</tr>
						) : (
							items.map( ( item ) => (
								<tr key={ item.id }>
									<td>{ item.id }</td>
									<td>{ item.name }</td>
									<td>{ item.sku }</td>
									<td className="catalogops-num">
										{ item.cost === null ||
										item.cost === undefined ? (
											<span className="catalogops-muted">
												—
											</span>
										) : (
											item.cost
										) }
									</td>
									<td className="catalogops-num">
										{ item.price }
									</td>
									<td className="catalogops-num">
										{ item.sale_price === null ||
										item.sale_price === undefined ? (
											<span className="catalogops-muted">
												—
											</span>
										) : (
											item.sale_price
										) }
									</td>
									<td>
										<span
											className={ `catalogops-badge catalogops-badge--${ stockBadge(
												item.stock_status
											) }` }
										>
											{ item.stock_status }
										</span>
									</td>
									<td className="catalogops-num">
										{ item.stock_quantity }
									</td>
								</tr>
							) )
						) }
					</tbody>
				</table>

				<div className="catalogops-pagination tablenav">
					<button
						className="button"
						disabled={ page <= 1 || loading }
						onClick={ () => run( page - 1 ) }
					>
						{ __( 'Previous', 'catalogops' ) }
					</button>
					<span className="catalogops-page">
						{ sprintf(
							/* translators: 1: current page, 2: total pages. */
							__( 'Page %1$d of %2$d', 'catalogops' ),
							page,
							pages
						) }
					</span>
					<button
						className="button"
						disabled={ page >= pages || loading }
						onClick={ () => run( page + 1 ) }
					>
						{ __( 'Next', 'catalogops' ) }
					</button>
				</div>
			</div>

			<BulkEdit
				filter={ appliedFilter }
				resetKey={ resetKey }
				onDone={ onApplyDone }
				onScheduleCreated={ onScheduleCreated }
				backupAck={ onboarding ? onboarding.backup_ack : true }
				onBackupAck={ () =>
					setOnboarding( ( o ) => ( { ...o, backup_ack: true } ) )
				}
				retentionDays={ onboarding ? onboarding.retention_days : 30 }
			/>

			<Schedules refreshKey={ schedulesKey } onRan={ refreshAll } />

			<History refreshKey={ historyKey } onChanged={ refreshAll } />

			<RetentionSetting />
		</div>
	);
}

const root = document.getElementById( 'catalogops-app' );
if ( root ) {
	if ( typeof createRoot === 'function' ) {
		createRoot( root ).render( <App /> );
	} else {
		render( <App />, root );
	}
}
