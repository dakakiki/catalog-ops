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
	createContext,
	useContext,
	useState,
	useCallback,
	useEffect,
	useRef,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	buildFilter,
	defaultModuleOperator,
	emptyForm,
	groupModuleFields,
	moduleConditions,
	moduleOperators,
	NO_TAG,
	NO_VALUE,
	operatorTakesRange,
	operatorTakesValue,
	reconcileAbsence,
	reconcileTagSelection,
} from './filter';
import './style.css';

const PER_PAGE = 10;

/**
 * The onboarding record — whether this user has acknowledged the backup reminder,
 * and, when they have, who and when.
 *
 * A context rather than a prop because the two places that need it sit far apart:
 * the bulk-edit panel is a child of the app, and the undo panel is three levels
 * down inside a history row. Threading it through History and OperationRow would
 * make both of them carry something neither has any use for, and the two readers
 * have to agree — acknowledging in one has to stand the other down too.
 *
 * Defaults to acknowledged. A failed fetch must not put a gate in front of
 * someone's undo; the reminder is a courtesy, and the REST layer is where real
 * limits are enforced.
 */
const OnboardingContext = createContext( {
	backup_ack: true,
	backup_ack_by: '',
	backup_ack_at: '',
	backup_ack_version: '',
	retention_days: 30,
	// Carried alongside the data so whichever panel takes the acknowledgement can
	// stand the other one down without either knowing the other exists.
	onAcknowledge: () => {},
} );

/**
 * The backup reminder, in whichever of its two states applies: the gate before a
 * user's first destructive run, or the record of when they passed it.
 *
 * Showing the record rather than nothing is the point of storing one. An
 * acknowledgement from eight months ago is not evidence that a backup exists
 * today, and the only person who can judge that is looking at this panel.
 *
 * @param {Object}   props         Component props.
 * @param {boolean}  props.checked Whether the box is ticked this time round.
 * @param {Function} props.onCheck Called as the box is ticked or unticked.
 */
function BackupReminder( { checked, onCheck } ) {
	const onboarding = useContext( OnboardingContext );

	if ( onboarding.backup_ack ) {
		return (
			<p className="catalogops-muted">
				{ sprintf(
					/* translators: 1: who acknowledged, 2: when, 3: the plugin version then. */
					__(
						'Backup confirmed by %1$s on %2$s, in version %3$s. If that was a while ago, now is the moment to check it is still recent.',
						'catalogops'
					),
					onboarding.backup_ack_by,
					onboarding.backup_ack_at,
					onboarding.backup_ack_version
				) }
			</p>
		);
	}

	return (
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
					checked={ checked }
					onChange={ ( e ) => onCheck( e.target.checked ) }
				/>
				{ sprintf(
					/* translators: %d: the number of days changes remain reversible. */
					__(
						'I have a recent backup, and I understand this change can be undone for %d days from History.',
						'catalogops'
					),
					onboarding.retention_days || 30
				) }
			</label>
		</>
	);
}

/**
 * How often the operation history re-asks the server while something is running.
 */
const HISTORY_POLL_ACTIVE_MS = 2000;

/**
 * How often it asks while nothing is running and nothing is due.
 *
 * This is the interval that decides how long a run started by something other
 * than this browser tab stays invisible, and it has to be shorter than the runs
 * themselves or it reports history rather than progress: measured on the
 * 18,583-product catalogue, a 250-product schedule finished in 28 seconds and a
 * 12-product one in a single second. A first attempt at half a minute could
 * miss both from beginning to end, so the row appeared already completed and
 * nothing was ever seen to happen.
 *
 * Eight seconds catches anything that runs for longer than a moment, and the
 * expensive window — a schedule about to fire — does not rely on this at all;
 * see POLL_DUE_MS.
 */
const HISTORY_POLL_IDLE_MS = 8000;

/**
 * How often both lists ask while a schedule is due to fire.
 *
 * A due schedule is the one moment when a new row is about to appear without
 * anyone in the browser having asked for it, and it is knowable in advance:
 * the schedules list carries next_run. So the page speeds up before the run
 * starts rather than discovering it afterwards.
 */
const POLL_DUE_MS = 2000;

/**
 * How often the panel above the button asks about the run it just started.
 *
 * Faster than either list, because this is the one a user is watching on purpose:
 * they pressed Apply and are waiting for the bar to move.
 */
const OPERATION_POLL_MS = 1500;

/**
 * How soon it asks again after a request that never answered.
 *
 * Slower than the ordinary cadence, because a poll fails when the server is not
 * there — a host being restarted, a laptop that lost its network — and hammering
 * something that is down helps nobody. Still far shorter than a run, so the panel
 * catches up within seconds of the server coming back.
 */
const OPERATION_RETRY_MS = 4000;

/**
 * How long a run may go without reporting before the history mentions it.
 *
 * Chunks land seconds apart, so a minute of silence is already out of the
 * ordinary — but it is not yet news. The server decides when a run is genuinely
 * not responding; this only decides when it is worth saying that nothing has
 * happened for a while, which is a smaller claim and belongs to the screen.
 */
const QUIET_AFTER_SECONDS = 60;

/**
 * A number of seconds as a person would say it: "48s", "1m 20s".
 *
 * Minutes and seconds only. Anything longer than an hour without a heartbeat is
 * not a duration the reader is measuring any more, and by then the row says the
 * run is not responding instead.
 *
 * @param {number} seconds How long it has been quiet.
 * @return {string} The duration, spoken.
 */
const quietFor = ( seconds ) => {
	const whole = Math.max( 0, Math.floor( seconds || 0 ) );
	const mins = Math.floor( whole / 60 );

	return mins > 0 ? `${ mins }m ${ whole % 60 }s` : `${ whole }s`;
};

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
 * The shop's currency symbol, for labelling an amount in the unit it is counted
 * in. Empty when WooCommerce did not answer — the label then says "amount",
 * which is vaguer but never wrong.
 */
const CURRENCY =
	( window.catalogopsConfig && window.catalogopsConfig.currency ) || '';

/**
 * Whether this site has any module registered, answered by the server at page
 * load so the filter knows a section is coming before it has asked for one.
 *
 * Defaults to false, and that direction matters: an unknown answer must not draw
 * a placeholder for a section that will never arrive. A site that does have
 * modules simply gets its section without a placeholder, which is what happened
 * before this existed.
 */
const MODULES_EXPECTED = Boolean(
	window.catalogopsConfig && window.catalogopsConfig.hasModules
);

/**
 * The language this whole page is working in, captured by the server on THIS page
 * load and sent back with every request that resolves a filter or lists past work.
 *
 * `null` when WPML is not active, which is most shops: they send no language, see
 * no indicator, and get exactly the behaviour they had before any of this existed.
 *
 * When WPML is active this is `{ code, label }`, and `code` is itself null on
 * WPML's "All languages" — the setting that means "place no constraint". The two
 * nulls are told apart by which one it is: no LANGUAGE at all is a shop without
 * WPML, a LANGUAGE with a null code is a user deliberately working across every
 * language.
 *
 * It is captured server-side at page load rather than read per request because
 * WPML honours "all" only under is_admin(), and a REST call is not — see
 * Wpml_Context.
 */
const LANGUAGE =
	( window.catalogopsConfig && window.catalogopsConfig.language ) || null;

/**
 * The language code to send with a request, or undefined to send nothing.
 *
 * Undefined rather than null, so it drops out of a JSON body entirely instead of
 * arriving as an explicit null — a filter written before languages existed carries
 * no key at all, and a request from a shop without WPML should look exactly like
 * one of those.
 *
 * @return {string|undefined} The language code, or undefined.
 */
function currentLanguage() {
	return LANGUAGE && LANGUAGE.code ? LANGUAGE.code : undefined;
}

/**
 * Which language this page is working in, said out loud in the header.
 *
 * The plugin never asks the user to pick a language — it works in whichever one
 * they are already in — and that is exactly why it has to say which. An unstated
 * frame is invisible until it is wrong: a shopkeeper who switched to Serbian two
 * screens ago, filtered, previewed 12,000 products and pressed Apply has no other
 * way to know which 12,000 those were.
 *
 * Renders nothing at all where there is nothing to say. A shop with one language
 * must not be told it has one.
 *
 * @return {Object|null} The indicator element, or null.
 */
function LanguageIndicator() {
	if ( ! LANGUAGE ) {
		return null;
	}

	const all = ! LANGUAGE.code;

	return (
		<span
			className={ `catalogops-language${
				all ? ' catalogops-language--all' : ''
			}` }
			title={
				all
					? __(
							'CatalogOps is working across every language. Filters, edits and schedules will reach the whole catalogue.',
							'catalogops'
					  )
					: sprintf(
							/* translators: %s: language name, e.g. Srpski. */
							__(
								'CatalogOps is working in %s. Filters, edits and schedules reach that language only — switch with the language selector in the admin bar.',
								'catalogops'
							),
							LANGUAGE.label
					  )
			}
		>
			<span
				className="dashicons dashicons-translation"
				aria-hidden="true"
			/>
			{ all ? __( 'All languages', 'catalogops' ) : LANGUAGE.label }
		</span>
	);
}

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
 * The fields that hold money, and so cannot take a value below zero. Mirrors the
 * server-side rule in CatalogOps\Operations\Write_Rules, so the control refuses
 * what the write engine would refuse and the answer arrives before the request
 * rather than after it.
 *
 * Stock quantity is deliberately absent: WooCommerce uses negative quantities for
 * backorders, so a minus there is meaningful.
 */
const MONEY_FIELDS = [ 'regular_price', 'sale_price' ];

/**
 * The smallest step a price should land on.
 *
 * A percentage is arithmetic, and arithmetic on money produces thirds of a
 * penny: 10000.99 cut by 10% is 9000.891, and WooCommerce stores what it is
 * given. Percent mode therefore rounds to the cent. Anyone wanting a different
 * landing — prices ending in .99, say — writes it in Formula mode, where
 * `roundto` is theirs to aim.
 */
const MONEY_STEP = '0.01';

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
 * The label for an editable field key, for surfaces that show the field itself
 * rather than a control for it. Falls back to the key, which is at least stable
 * and searchable, for a field no built-in provider names.
 *
 * @param {string} key A field key (e.g. regular_price).
 * @return {string} Its label.
 */
const fieldLabel = ( key ) =>
	( EDITABLE_FIELDS.find( ( f ) => f.key === key ) || {} ).label || key;

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
 * @param {string} mode          'set' | 'amount' | 'percent' | 'formula'.
 * @param {string} expression    The formula being applied, for percent and formula
 *                               modes — the source of the fields it reads.
 * @param {Object} percentChange For the percent and amount modes,
 *                               { direction, amount }: the sentence names which
 *                               way the price moves rather than pointing at the
 *                               control that says so.
 * @return {string} The hint sentence.
 */
function changeHint( field, mode, expression = '', percentChange = null ) {
	const noun = FIELD_NOUNS[ field ] || field;
	let sentence;
	if ( mode === 'amount' ) {
		const down =
			'decrease' === ( percentChange && percentChange.direction );
		const typed = percentChange ? percentChange.amount : '';
		const named = '' !== typed && ! Number.isNaN( Number( typed ) );
		const shown = CURRENCY
			? CURRENCY + Math.abs( Number( typed ) )
			: String( Math.abs( Number( typed ) ) );

		if ( named && down ) {
			sentence = sprintf(
				/* translators: 1: the field being changed, e.g. "the regular price". 2: an amount of money, e.g. "€200". */
				__(
					'Lowers %1$s by %2$s, for every matching product.',
					'catalogops'
				),
				noun,
				shown
			);
		} else if ( named ) {
			sentence = sprintf(
				/* translators: 1: the field being changed, e.g. "the regular price". 2: an amount of money, e.g. "€200". */
				__(
					'Raises %1$s by %2$s, for every matching product.',
					'catalogops'
				),
				noun,
				shown
			);
		} else if ( down ) {
			sentence = sprintf(
				/* translators: %s: the field being changed, e.g. "the regular price". */
				__(
					'Lowers %s by the amount above, for every matching product.',
					'catalogops'
				),
				noun
			);
		} else {
			sentence = sprintf(
				/* translators: %s: the field being changed, e.g. "the regular price". */
				__(
					'Raises %s by the amount above, for every matching product.',
					'catalogops'
				),
				noun
			);
		}
	} else if ( mode === 'percent' ) {
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
 * Previous / Next around a page count.
 *
 * Renders nothing for a single page: a pager with both buttons disabled is a
 * control whose only message is that there is nowhere to go.
 *
 * @param {Object}   props        Component props.
 * @param {number}   props.page   Current page, 1-based.
 * @param {number}   props.pages  How many pages there are.
 * @param {boolean}  props.busy   Whether a request is in flight.
 * @param {Function} props.onPage Called with the page to move to.
 */
function Pagination( { page, pages, busy = false, onPage } ) {
	if ( pages <= 1 ) {
		return null;
	}

	return (
		<div className="catalogops-pagination">
			<button
				className="button"
				disabled={ page <= 1 || busy }
				onClick={ () => onPage( page - 1 ) }
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
				disabled={ page >= pages || busy }
				onClick={ () => onPage( page + 1 ) }
			>
				{ __( 'Next', 'catalogops' ) }
			</button>
		</div>
	);
}

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
 * One module field's row.
 *
 * Appended below the eight built-in controls rather than replacing them. The
 * built-ins are 296 lines of tested JSX with bespoke behaviour each — a "Without
 * tag" sentinel that must not go through Number(), an attribute pair that only
 * exists under one scope — and rewriting them in the same change that introduces
 * the mechanism would have risked the whole filter to save a section boundary.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.field    The descriptor from /fields/filterable.
 * @param {Object}   props.row      This field's `{ value, mode }`, or undefined.
 * @param {Function} props.onChange Called with the next row.
 */
function ModuleField( { field, row, onChange } ) {
	const [ options, setOptions ] = useState( [] );

	// Whether this field's own options are still on their way. A set control with
	// nothing in it looks exactly like a set control whose module has no values to
	// offer, and the two want opposite things from the reader — one is worth
	// waiting for, the other is worth giving up on.
	const [ loadingOptions, setLoadingOptions ] = useState( false );

	// Fetched from the route the DESCRIPTOR names, not from a path this file
	// knows. That is the whole point of `options_route`: a module can serve its
	// own options without the client learning anything about it.
	useEffect( () => {
		if ( ! field.options_route || ! field.available ) {
			return undefined;
		}

		// Guards the two setState calls below against an answer that arrives after
		// this field is gone — a scope switch drops every module row at once.
		let live = true;

		setLoadingOptions( true );

		// The route already carries `?field=`, so the language is appended rather
		// than started. The values it offers are the CURRENT language's labels; the
		// ids behind them are ACF's stored keys and are the same in every language,
		// which is what keeps a filter saved in one readable in the other.
		const language = currentLanguage();

		apiFetch( {
			path: `${ field.options_route }${
				language
					? `${
							field.options_route.includes( '?' ) ? '&' : '?'
					  }language=${ encodeURIComponent( language ) }`
					: ''
			}`,
		} )
			.then( ( res ) => {
				if ( live ) {
					setOptions( res.terms || res.options || [] );
				}
			} )
			.catch( () => {} )
			.finally( () => {
				if ( live ) {
					setLoadingOptions( false );
				}
			} );

		return () => {
			live = false;
		};
	}, [ field.options_route, field.available ] );

	const value = row && undefined !== row.value ? row.value : '';
	const mode = row && row.mode ? row.mode : '';
	const id = `catalogops-module-${ field.key.replace( /[^a-z0-9]/gi, '-' ) }`;

	const set = ( next ) =>
		onChange( { value, mode, to: row && row.to ? row.to : '', ...next } );

	// A field the licence does not cover is shown rather than hidden, and shown
	// disabled rather than absent. A saved filter can already name it, and a
	// condition the user cannot see is one they cannot remove — while the engine
	// goes on refusing to run the filter that carries it.
	if ( ! field.available ) {
		return (
			<div className="catalogops-field">
				<label htmlFor={ id }>{ field.label }</label>
				<input id={ id } type="text" value="" disabled readOnly />
				<span className="catalogops-muted">
					{ __( 'Needs a paid plan', 'catalogops' ) }
				</span>
			</div>
		);
	}

	// A set control gets the same picker the built-in category and tag rows use,
	// so a module field looks and behaves like a first-party one. It carries its
	// own include/exclude toggle, which is why the mode select below is hidden
	// for it rather than shown twice.
	if ( 'term_set' === field.control || 'value_set' === field.control ) {
		// "Without a value" is offered as an entry in the list rather than as an
		// operator beside it, exactly as the tag row offers "Without tag". On a set
		// field it is the one question no choice can express: `is not sale` keeps
		// the products carrying no badge at all, because they are, definitively,
		// not on sale. Only added when the field declares it, and never when a real
		// option already answers to the sentinel — an ACF choice key is a string
		// and could in principle collide.
		const offersPresence =
			! loadingOptions &&
			( field.operators || [] ).includes( 'not_exists' ) &&
			! options.some( ( one ) => String( one.id ) === NO_VALUE );

		const withPresence = offersPresence
			? [
					{
						id: NO_VALUE,
						name: __( 'Without a value', 'catalogops' ),
					},
					...options,
			  ]
			: options;

		return (
			<div
				className={ `catalogops-field${
					loadingOptions ? ' is-loading' : ''
				}` }
			>
				<MultiSelect
					label={ field.label }
					options={ withPresence }
					value={ Array.isArray( value ) ? value : [] }
					placeholder={
						loadingOptions
							? __( 'Loading…', 'catalogops' )
							: __( 'Any', 'catalogops' )
					}
					mode={ 'not_in' === mode ? 'not_in' : 'in' }
					// "Without a value" and a real choice cannot both be
					// meaningful: nothing carries a badge and carries none. The
					// tag row has always reconciled the two rather than letting
					// the pair be built and then quietly dropping one; this is the
					// same rule, so the two set controls behave the same way.
					onChange={ ( next ) =>
						set( {
							value: reconcileAbsence(
								Array.isArray( value ) ? value : [],
								next,
								NO_VALUE
							),
						} )
					}
					onModeChange={ ( next ) => set( { mode: next } ) }
				/>
			</div>
		);
	}

	// A true/false field keeps its three-state Any/Yes/No box and gets no operator
	// control: "Any" already means "no condition", so an operator would only offer
	// ways of saying the same thing twice.
	if ( 'toggle' === field.control ) {
		return (
			<div className="catalogops-field">
				<label htmlFor={ id }>{ field.label }</label>
				<select
					id={ id }
					value={ '' === value ? '' : String( value ) }
					onChange={ ( e ) =>
						set( {
							value:
								'' === e.target.value
									? ''
									: 'true' === e.target.value,
						} )
					}
				>
					<option value="">{ __( 'Any', 'catalogops' ) }</option>
					<option value="true">{ __( 'Yes', 'catalogops' ) }</option>
					<option value="false">{ __( 'No', 'catalogops' ) }</option>
				</select>
			</div>
		);
	}

	const operators = moduleOperators( field );
	const operator = mode || defaultModuleOperator( field );

	// A date field gets a date input, not a text box. The descriptor says the
	// value is a date; a text box invites `8.7.2024.` against a column holding
	// `20240708`, which is a filter that reads correctly and matches nothing —
	// and nothing is exactly what an over-narrow filter looks like. `filter.js`
	// converts what this produces into the format the column actually holds.
	const inputType = INPUT_TYPES[ field.control ] || 'text';

	return (
		<div className="catalogops-field">
			{ /* The operator sits in the label row, where the multiselect already
			     puts its include/exclude switch, because it modifies the label's
			     question ("Cost price is more than…") rather than the value. Below
			     the box — which is where it used to be — it read as a second
			     control of equal weight and made every field three rows tall. */ }
			<span className="catalogops-field__label-row">
				<label htmlFor={ id } className="catalogops-field-label">
					{ field.label }
				</label>

				<select
					className="catalogops-field__op"
					value={ operator }
					aria-label={ sprintf(
						/* translators: %s: the filter field's name, e.g. "Cost price". */
						__( 'How to match %s', 'catalogops' ),
						field.label
					) }
					onChange={ ( e ) => set( { mode: e.target.value } ) }
				>
					{ operators.map( ( token ) => (
						<option key={ token } value={ token }>
							{ operatorLabel( token ) }
						</option>
					) ) }
				</select>
			</span>

			{ /* Removed, not disabled. A greyed-out box beside "has no value"
			     reads as something broken and invites the question of what the
			     text in it would have done. */ }
			{ operatorTakesValue( operator ) &&
				( operatorTakesRange( operator ) ? (
					<span className="catalogops-field__range">
						<input
							id={ id }
							type={ inputType }
							value={ value }
							aria-label={ __( 'From', 'catalogops' ) }
							placeholder={ __( 'From', 'catalogops' ) }
							onChange={ ( e ) =>
								set( { value: e.target.value } )
							}
						/>
						<input
							type={ inputType }
							value={ row && row.to ? row.to : '' }
							aria-label={ __( 'To', 'catalogops' ) }
							placeholder={ __( 'To', 'catalogops' ) }
							onChange={ ( e ) => set( { to: e.target.value } ) }
						/>
					</span>
				) : (
					<input
						id={ id }
						type={ inputType }
						value={ value }
						onChange={ ( e ) => set( { value: e.target.value } ) }
					/>
				) ) }
		</div>
	);
}

/**
 * One module's fields, under a heading that folds them away.
 *
 * A shop with a dozen ACF fields pushes the built-in controls — price, stock,
 * category, the ones used on most days — off the top of the filter. Folding the
 * section is what gives that space back, and the heading is the natural place to
 * click because it is already the thing that says where these fields come from.
 *
 * **A closed section still says how many of its fields are filled in.** The rest
 * of this filter is built on the rule that a condition the user cannot see is one
 * they cannot remove — it is why an unlicensed field renders disabled rather than
 * hidden. Folding hides conditions, so the count is what keeps that promise: the
 * filter never silently narrows behind a closed panel. It is counted with
 * `moduleConditions`, the same function that builds the payload, so the number is
 * what would actually be sent rather than a second opinion about it.
 *
 * The open state is per session, not stored. Nothing else in this app persists UI
 * state, and a filter that remembered a fold from last week would hide conditions
 * on a screen the user had not touched yet.
 *
 * @param {Object}   props         Component props.
 * @param {Object}   props.group   A group from `groupModuleFields`.
 * @param {string}   props.scope   'product' or 'variation'.
 * @param {Object}   props.form    The filter form.
 * @param {Function} props.setForm Setter for the form.
 */
function ModuleGroup( { group, scope, form, setForm } ) {
	// Closed to begin with: the point of the fold is the space it gives back to
	// price, stock and category, and a section that opens expanded gives none of
	// it until someone clicks. Nothing is hidden by this — a fresh form has no
	// module conditions, and the moment one exists the heading counts it.
	const [ open, setOpen ] = useState( false );

	// Whether the fields have ever been on screen. Once they have, they stay
	// mounted and CSS hides them, because `ModuleField` fetches its own options
	// when it mounts: unmounting on every fold threw those away, so reopening the
	// section put every set control back to "loading" and the values already
	// chosen had nothing to render themselves against. The choices were never
	// lost — they live in the form, not in the control — but a picker that goes
	// blank and fills in a moment later is indistinguishable from one that
	// forgot, and the user has no way to tell which happened.
	//
	// Not simply always-mounted: the section starts closed, and mounting it then
	// would fire an options request for every set field on a panel nobody has
	// opened. First open pays for the fetch, every fold after it is free.
	const [ everOpened, setEverOpened ] = useState( false );

	const toggle = () => {
		if ( ! open ) {
			setEverOpened( true );
		}

		setOpen( ! open );
	};

	const active = moduleConditions( form.modules, group.fields, scope ).length;

	return (
		<div
			className={ `catalogops-module-group${ open ? '' : ' is-closed' }` }
		>
			<button
				type="button"
				className="catalogops-module-heading"
				aria-expanded={ open }
				onClick={ toggle }
			>
				<span className="catalogops-module-heading__text">
					{ group.label || __( 'More fields', 'catalogops' ) }
				</span>

				{ ! open && active > 0 && (
					<span className="catalogops-module-count">
						{ sprintf(
							/* translators: %d: how many of this section's fields are filtering. */
							_n(
								'%d filter selected',
								'%d filters selected',
								active,
								'catalogops'
							),
							active
						) }
					</span>
				) }

				<span
					className="catalogops-module-chevron"
					aria-hidden="true"
				/>
			</button>

			{ everOpened && (
				<div className="catalogops-filter-row">
					{ group.fields.map( ( f ) => (
						<ModuleField
							key={ f.key }
							field={ f }
							row={ form.modules[ f.key ] }
							onChange={ ( next ) =>
								setForm( {
									...form,
									modules: {
										...form.modules,
										[ f.key ]: next,
									},
								} )
							}
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

/**
 * The HTML input a control needs. Anything unlisted is a text box.
 *
 * `date` is the one that matters. The descriptor says the value is a date, and a
 * text box would invite `8.7.2024.` against a column holding `20240708` — a
 * filter that reads correctly and matches nothing, which is indistinguishable
 * from an over-narrow filter. A date input can only produce `YYYY-MM-DD`, and
 * `filter.js` turns that into whatever the column actually keeps.
 */
const INPUT_TYPES = {
	number: 'number',
	money: 'number',
	date: 'date',
};

/**
 * How to say an operator token in the filter's own voice.
 *
 * Here rather than in `filter.js`: deciding *which* operators a field offers is
 * a rule and belongs with the other rules; saying them in the reader's language
 * is this file's job, and keeping the two apart is what lets `filter.js` stay
 * free of i18n and be tested as plain data.
 *
 * The wording is a sentence continuing the label — "Cost price · is more than",
 * "Launch date · is between" — rather than the mathematical symbol, because the
 * person reading it wrote the field in ACF and is not thinking in operators.
 *
 * @param {string} operator The operator token as the API persists it.
 * @return {string} A translated phrase.
 */
function operatorLabel( operator ) {
	switch ( operator ) {
		case '=':
			return __( 'is', 'catalogops' );
		case '!=':
			return __( 'is not', 'catalogops' );
		case 'contains':
			return __( 'contains', 'catalogops' );
		case 'in':
			return __( 'is any of', 'catalogops' );
		case 'not_in':
			return __( 'is none of', 'catalogops' );
		case '>':
			return __( 'is more than', 'catalogops' );
		case '>=':
			return __( 'is at least', 'catalogops' );
		case '<':
			return __( 'is less than', 'catalogops' );
		case '<=':
			return __( 'is at most', 'catalogops' );
		case 'between':
			return __( 'is between', 'catalogops' );
		case 'exists':
			return __( 'has any value', 'catalogops' );
		case 'not_exists':
			return __( 'has no value', 'catalogops' );
		default:
			return operator;
	}
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

	// Counts polls that never answered. It exists to restart the chain, because the
	// chain is driven by `operation` changing: each answer replaces the snapshot,
	// which re-runs this effect, which asks again. A request that fails replaces
	// nothing, so before this it scheduled no successor and the panel stopped asking
	// for the rest of the session — however long the run went on.
	//
	// Reported live on 2026-09-06: the host was stopped mid-run and started again
	// fifteen minutes later, the operation recovered by itself and finished all
	// 1,596 objects, and this panel still read 300 while the history a few
	// centimetres below it had moved on to 1,200 and then to done. Neither the run
	// nor the stored counter was wrong. The only thing that had died was this timer,
	// killed by the one dropped request at the moment the server went away.
	//
	// The list below had this same defect and was rewritten for it; this hook was
	// left behind. Counting the failures rather than swallowing them is what makes
	// the retry a state change, which is the only thing this effect responds to.
	const [ missed, setMissed ] = useState( 0 );

	useEffect( () => {
		if ( ! operation ) {
			return undefined;
		}
		if ( isTerminal( operation ) ) {
			onDone();
			return undefined;
		}

		let cancelled = false;

		timer.current = setTimeout(
			() => {
				apiFetch( {
					path: `/catalogops/v1/operations/${ operation.id }`,
				} )
					.then( ( next ) => {
						if ( cancelled ) {
							return;
						}
						// Both setters, and the order does not matter: React batches
						// them into one re-render, so the effect re-runs once and
						// schedules one successor. Resetting to nought when it is
						// already nought is a no-op, so an uninterrupted run pays
						// nothing for this.
						setOperation( next );
						setMissed( 0 );
					} )
					.catch( () => {
						if ( ! cancelled ) {
							setMissed( ( n ) => n + 1 );
						}
					} );
			},
			missed > 0 ? OPERATION_RETRY_MS : OPERATION_POLL_MS
		);

		return () => {
			cancelled = true;
			clearTimeout( timer.current );
		};
	}, [ operation, setOperation, onDone, missed ] );
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
		'the new price would come out negative, so their price is left as it is — subtracting a fixed amount does this to items cheaper than that amount',
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
 * The same reasons in a few words, for a table cell.
 *
 * The full clauses above are written to be read as the tail of "N items — …",
 * which is right for a summary and far too long for a column. A cell needs the
 * name of the reason; the summary above it carries the explanation.
 */
const SKIP_REASONS_BRIEF = {
	empty_input: __( 'no value to read', 'catalogops' ),
	negative_value: __( 'would be negative', 'catalogops' ),
	sale_not_below_regular: __( 'not below regular', 'catalogops' ),
	stock_managed: __( 'stock is managed', 'catalogops' ),
};

/**
 * A short explanation for a skip-reason code, for use in a table cell.
 *
 * @param {string} code The stored reason code.
 * @return {string} A few words, falling back to the full clause.
 */
function skipReasonBrief( code ) {
	return SKIP_REASONS_BRIEF[ code ] || skipReasonLabel( code );
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
 * A labelled progress bar for an operation that is still running, and the report
 * for one that finished with something to explain.
 *
 * Applying does not start the work — it queues it, and the background runner
 * picks it up on its own schedule. On a site where WP-Cron only fires on the
 * next page load that gap can be minutes, during which a plain 0% bar looks
 * indistinguishable from a stuck one. So an operation that has processed nothing
 * yet gets an explicitly indeterminate bar and a spinner: something is happening,
 * it just is not measurable yet. If the wait runs long, the bar says why.
 *
 * It renders nothing at all once a run has finished cleanly — see the guard
 * below.
 *
 * @param {Object} props    Component props.
 * @param {Object} props.op The operation to render.
 */
function ProgressBar( { op } ) {
	const settled = isTerminal( op );
	const waiting = ! settled && op.processed === 0;
	const waited = useWaitingSeconds( waiting );
	const skipped = ( op.skip_reasons || [] ).filter( ( r ) => r.count > 0 );

	// Once a run is over the bar has answered its question and every part of it that
	// measures progress is a leftover, sitting above a history row that carries the
	// same figures and keeps them. So the counter and the bar both go.
	//
	// What can outlive the run is what it could not do. That is not progress, it is
	// an outcome, and it is the one thing the history row does not say on its face.
	// It was tried the other way first — keeping the whole panel whenever anything
	// was skipped, on the grounds that the counter gives the explanation its scale —
	// and a real run settled the argument: 3,042 items changed and 4 left alone
	// because they already held the value, which is a footnote, and it held a full
	// green completed bar on the screen to say so.
	// What is left is a message, so it is shaped like every other message in this
	// app: a notice. It used to render into a bare `catalogops-progress` div,
	// which styles a progress bar and nothing else — so the one outcome that
	// outlives its run was also the one piece of text on the screen with no frame
	// around it, a heading and a bullet list loose under the Apply button. Every
	// other ReasonList in this file already sits inside a notice or the skip
	// summary card; this was the only one that did not.
	//
	// The tone follows the worse of the two facts. A failure is an error — the
	// change was meant to happen and did not — while a skip is a rule doing its
	// job, which is a warning at most. A run with both is reported as an error,
	// because that is the half the user has to act on.
	if ( settled ) {
		if ( op.failed === 0 && skipped.length === 0 ) {
			return null;
		}

		return (
			<div
				className={ `notice ${
					op.failed > 0 ? 'notice-error' : 'notice-warning'
				} catalogops-inline-notice catalogops-progress__outcome` }
			>
				{ op.failed > 0 && (
					<p>
						{ sprintf(
							/* translators: %d: number of items that could not be changed. */
							_n(
								'%d item could not be changed.',
								'%d items could not be changed.',
								op.failed,
								'catalogops'
							),
							op.failed
						) }
					</p>
				) }
				{ skipped.length > 0 && (
					<>
						<p>{ __( 'Not changed:', 'catalogops' ) }</p>
						<ReasonList items={ skipped } />
					</>
				) }
			</div>
		);
	}

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
	// Its own state, not shared with the percentage: 10 percent and 10 in money
	// are different quantities, and carrying one across the mode switch would
	// silently propose a change nobody asked for.
	const [ amount, setAmount ] = useState( '' );
	const [ direction, setDirection ] = useState( 'increase' );
	// Narrows the preview's worked-out sample to one product. It never touches the
	// counts, which describe the whole edit — this answers "and what about that
	// one", which a sample of ten cannot.
	const [ previewSku, setPreviewSku ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ operation, setOperation ] = useState( null );
	const [ error, setError ] = useState( '' );
	// Which action the error belongs to, so the answer can be shown beside the
	// button that asked for it. At the foot of the panel a message is easy to
	// miss entirely — the button is where the user is looking.
	const [ errorFrom, setErrorFrom ] = useState( '' );
	// Which request is in flight — 'preview', 'apply', 'schedule', or '' for none.
	// Not a boolean: every control has to be disabled while any of them runs, but
	// only the one that was pressed should say it is working. One shared flag put
	// the spinner beside Preview and Apply at the same time, so the panel claimed
	// to be doing two things at once.
	const [ busyWith, setBusyWith ] = useState( '' );
	const busy = '' !== busyWith;

	// Percent and Formula modes both compile to a formula action, which the free
	// tier cannot run (the REST layer returns 402); scheduling is paid too. Gate
	// both controls so the free tier sees an upsell instead of a dead end.
	const canFormulas = can( 'canUseFormulas' );
	const canSchedule = can( 'canSchedule' );

	// The apply confirmation. Before the very first operation the backup reminder
	// is mandatory (CONTEXT §9): a required acknowledgement, not a throwaway
	// dialog. Once acknowledged, applying just asks for a plain confirmation.
	const [ confirming, setConfirming ] = useState( false );
	// The user's reason for this run, kept beside the confirmation rather than in
	// the form: it is written while looking at the count, and cleared the moment
	// the run starts so the next one cannot inherit it. A stale reason is worse
	// than none — it reads as deliberate.
	const [ note, setNote ] = useState( '' );
	const [ backupChecked, setBackupChecked ] = useState( false );

	// When the change should run: straight away, or on a schedule (the same filter
	// and action, deferred and possibly recurring). One question with two answers,
	// not a primary action and an optional extra — which is what a collapsed
	// "Scheduling" section made it look like.
	const [ when, setWhen ] = useState( 'now' );
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
		setAmount( '' );
		setDirection( 'increase' );
		setPreviewSku( '' );
		setPreview( null );
		setError( '' );
		setName( '' );
		setRecurrence( 'once' );
		setStartsAt( '' );
		setNotifyEmail( '' );
		setWhen( 'now' );
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
			: `roundto( ${ fieldVariable( field ) } * ${ percentFactor(
					percent,
					direction
			  ) }, ${ MONEY_STEP } )`;

	// The amount as the server takes it: a signed delta. The sign comes from the
	// direction alone, as it does for the percentage — a typed minus under
	// Decrease would otherwise negate the negation.
	const amountReady = amount !== '' && ! Number.isNaN( Number( amount ) );
	const signedAmount =
		( 'decrease' === direction ? -1 : 1 ) * Math.abs( Number( amount ) );

	const buildActions = () => {
		if ( mode === 'formula' ) {
			return [ { type: 'formula', field, expression } ];
		}
		if ( mode === 'percent' ) {
			return [
				{ type: 'formula', field, expression: percentExpression },
			];
		}
		if ( mode === 'amount' ) {
			// Its own action type, not a generated formula: see Actions\Adjust —
			// the free tier can run this precisely because it is not a formula.
			return [ { type: 'adjust', field, amount: signedAmount } ];
		}
		return [ { type: 'set', field, value } ];
	};

	// The figure the hint should name. A percentage the panel has already refused
	// is not described as though it were about to run.
	const hintAmount = ( () => {
		if ( mode === 'amount' ) {
			return amount;
		}

		return percentTooDeep ? '' : percent;
	} )();

	// What the hint should read as the source of the fields this change depends
	// on. Amount has no expression, but it does read the field it writes — naming
	// it here earns the same "products where that field is empty are left out"
	// tail the formula modes get, and it is equally true.
	const hintExpression =
		( mode === 'percent' && percentExpression ) ||
		( mode === 'amount' && fieldVariable( field ) ) ||
		( mode === 'formula' && expression ) ||
		'';

	// Whether the current inputs form a runnable action.
	const ready =
		( mode === 'set' && value !== '' ) ||
		( mode === 'formula' && expression.trim() !== '' ) ||
		( mode === 'amount' && amountReady ) ||
		( mode === 'percent' && percentExpression !== '' );

	// Any edit to the change invalidates the last answer — the preview, and equally
	// whatever the server refused. Leaving a refusal on screen while the inputs
	// move on is how "a price cannot be negative" ends up sitting under a
	// percentage that has nothing to do with it.
	const invalidate = () => {
		setPreview( null );
		setError( '' );
		setErrorFrom( '' );
	};

	/**
	 * Record a failure against the action that caused it.
	 *
	 * @param {string} action 'preview', 'apply' or 'schedule'.
	 * @return {Function} A catch handler.
	 */
	const failed = ( action ) => ( err ) => {
		setError( err.message || __( 'Request failed.', 'catalogops' ) );
		setErrorFrom( action );
	};

	// Switching to a numeric-only mode off a non-numeric field (stock_status)
	// falls back to a sensible numeric field.
	const changeMode = ( next ) => {
		setMode( next );
		invalidate();
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
		setBusyWith( 'preview' );
		setError( '' );
		setErrorFrom( '' );
		// The previous answer stays on screen while the new one is fetched. The
		// search that triggers this lives inside the panel, and unmounting it
		// mid-search would take the field — and the caret — with it. The table is
		// dimmed instead, the way the filter's results are.
		// Drop any finished operation's result bar: the render gates the preview
		// on `! operation`, so a stale bar from a previous run would otherwise
		// hide the new preview and make Preview look like it did nothing.
		setOperation( null );
		apiFetch( {
			path: '/catalogops/v1/operations/preview',
			method: 'POST',
			data: { filter, actions: buildActions(), sku: previewSku.trim() },
		} )
			.then( setPreview )
			.catch( ( err ) => {
				// A refused preview has no answer to leave standing.
				setPreview( null );
				failed( 'preview' )( err );
			} )
			.finally( () => setBusyWith( '' ) );
	};

	// Actually queue the operation. Reached only after the apply confirmation
	// (and, the first time, the backup acknowledgement) is satisfied.
	const doApply = () => {
		setConfirming( false );
		setNote( '' );
		setBusyWith( 'apply' );
		setError( '' );
		// The preview is superseded by the running operation, and any previous
		// operation's bar is replaced by this one — clear both so the panel shows
		// the new run cleanly rather than a stale result.
		setPreview( null );
		setOperation( null );
		apiFetch( {
			path: '/catalogops/v1/operations',
			method: 'POST',
			data: {
				filter,
				actions: buildActions(),
				// Trimmed here so a box holding only spaces is the same as an
				// empty one: the column is nullable and the history shows nothing
				// rather than an empty line.
				note: note.trim(),
			},
		} )
			.then( setOperation )
			.catch( failed( 'apply' ) )
			.finally( () => setBusyWith( '' ) );
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
		setBusyWith( 'schedule' );
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
			.catch( failed( 'schedule' ) )
			.finally( () => setBusyWith( '' ) );
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

	// The schedule form's own summary is the last thing read before creating
	// something that will run unattended, so it is a notice like the preview
	// panel's rather than a loose paragraph of numbers under a form. Green only
	// when something would actually change: a schedule that would write nothing
	// is the case worth catching before it is saved, not after it has fired.
	let schedulePreviewTone = 'notice-info';

	if ( preview ) {
		schedulePreviewTone =
			preview.applicable > 0 ? 'notice-success' : 'notice-warning';
	}

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

	/**
	 * The error belonging to one action, rendered where that action lives. At the
	 * foot of the panel a message is easy to miss; beside the button that was
	 * pressed it is where the user is already looking.
	 *
	 * @param {string} action 'preview', 'apply' or 'schedule'.
	 * @return {Object|null} The notice, or nothing.
	 */
	const noticeFor = ( action ) =>
		error && errorFrom === action ? (
			<div className="notice notice-error catalogops-inline-notice">
				<p>{ error }</p>
			</div>
		) : null;

	const previewSample = ( preview && preview.sample ) || [];
	const searching = previewSku.trim() !== '';

	// Ten rows out of thousands is a sample, and saying so is the difference
	// between an illustration and a false promise of completeness. Unlike the
	// results table above, these rows have no pager behind them: the search is the
	// only way to reach a product that is not among the ten, so the caption says
	// so rather than leaving it to be discovered.
	const sampleCaption = searching
		? sprintf(
				/* translators: %s: the SKU searched for. */
				__( 'Matching “%s”.', 'catalogops' ),
				previewSku.trim()
		  )
		: sprintf(
				/* translators: 1: rows shown, 2: total that will change. */
				__(
					'A sample: %1$d of the %2$d that will change. Search by SKU to check a particular one.',
					'catalogops'
				),
				previewSample.length,
				preview ? preview.applicable : 0
		  );

	// The preview's answer, rendered under the button that asked for it. It is
	// dropped while an operation is on screen: the run supersedes the dry run.
	const previewPanel = preview && ! operation && (
		<div className="catalogops-preview">
			{ preview.matched === 0 && (
				<div className="notice notice-info">
					<p>
						{ __( 'No products match this filter.', 'catalogops' ) }
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
				<div className="notice notice-warning" key={ warning.code }>
					<p>{ warningText( warning.code, warning.count ) }</p>
				</div>
			) ) }

			{ /* The counts are the promise; this is the promise shown. A formula
			     or a percentage is invisible in a number — it only becomes
			     checkable when someone can watch 10.00 turn into 13.86.

			     The bar above it is the filter's results bar again: what is being
			     shown on the left, the search that narrows it on the right. */ }
			{ ( previewSample.length > 0 || searching ) && (
				<div className="catalogops-results-bar">
					<p className="catalogops-status">{ sampleCaption }</p>
					<div className="catalogops-search">
						<input
							id="catalogops-preview-sku"
							type="search"
							placeholder={ __(
								'SKU, e.g. COPS-1234',
								'catalogops'
							) }
							aria-label={ __(
								'Show one SKU in the preview',
								'catalogops'
							) }
							value={ previewSku }
							onChange={ ( e ) =>
								setPreviewSku( e.target.value )
							}
							onKeyDown={ ( e ) =>
								e.key === 'Enter' &&
								ready &&
								! busy &&
								runPreview()
							}
						/>
						<button
							className="button"
							onClick={ runPreview }
							disabled={ busy || running || ! ready }
						>
							{ __( 'Search', 'catalogops' ) }
						</button>
					</div>
				</div>
			) }

			{ previewSample.length > 0 && (
				<>
					<table
						className={ `wp-list-table widefat fixed striped${
							'preview' === busyWith
								? ' catalogops-loading-dim'
								: ''
						}` }
					>
						<thead>
							<tr>
								<th>{ __( 'SKU', 'catalogops' ) }</th>
								<th>{ __( 'Name', 'catalogops' ) }</th>
								<th>{ __( 'Field', 'catalogops' ) }</th>
								<th className="catalogops-num">
									{ __( 'Now', 'catalogops' ) }
								</th>
								<th className="catalogops-num">
									{ __( 'After', 'catalogops' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ previewSample.map( ( row ) =>
								row.changes.map( ( change, index ) => (
									<tr key={ `${ row.id }-${ change.field }` }>
										<td>
											{ index === 0 ? row.sku : '' }
											{ index === 0 &&
												! row.sku &&
												row.id }
										</td>
										<td>{ index === 0 ? row.name : '' }</td>
										<td>{ fieldLabel( change.field ) }</td>
										<td className="catalogops-num">
											{ change.old }
										</td>
										<td className="catalogops-num">
											{ null === change.new ? (
												<span className="catalogops-muted">
													{ '— ' +
														skipReasonBrief(
															change.reason
														) }
												</span>
											) : (
												<strong>{ change.new }</strong>
											) }
										</td>
									</tr>
								) )
							) }
						</tbody>
					</table>
				</>
			) }

			{ previewSample.length === 0 && searching && (
				<p className="catalogops-table-caption">
					{ sprintf(
						/* translators: %s: the SKU searched for. */
						__(
							'Nothing matching “%s” is in this change.',
							'catalogops'
						),
						previewSku.trim()
					) }
				</p>
			) }
		</div>
	);

	return (
		<div className="catalogops-bulk-edit">
			<h2>{ __( 'Bulk edit', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Change a field for every item in the filter above. Preview shows what it would do; nothing is written until you commit. Then choose when it runs — now, or on a schedule.',
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
								{ /* Between "Set to" and "Percent" because that is
								     the order of difficulty: a fixed value, then a
								     fixed step, then a proportion. */ }
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										mode === 'amount' ? ' is-active' : ''
									}` }
									onClick={ () => changeMode( 'amount' ) }
								>
									{ __( 'Amount', 'catalogops' ) }
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
										invalidate();
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
											onChange={ ( e ) => {
												invalidate();
												setValue( e.target.value );
											} }
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
										// Every other editable field is a number, and
										// a price is a number that cannot be negative.
										// The engine refuses one either way; saying so
										// in the control beats saying it in an error.
										<input
											id="catalogops-value"
											type="number"
											step={
												'stock_quantity' === field
													? '1'
													: 'any'
											}
											min={
												MONEY_FIELDS.includes( field )
													? '0'
													: undefined
											}
											value={ value }
											onChange={ ( e ) => {
												invalidate();
												setValue( e.target.value );
											} }
										/>
									) }
								</div>
							) }

							{ ( mode === 'percent' || mode === 'amount' ) && (
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
											onClick={ () => {
												invalidate();
												setDirection( 'increase' );
											} }
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
											onClick={ () => {
												invalidate();
												setDirection( 'decrease' );
											} }
										>
											{ __( 'Decrease', 'catalogops' ) }
										</button>
									</div>
								</div>
							) }

							{ mode === 'amount' && (
								<div className="catalogops-field">
									<label htmlFor="catalogops-amount">
										{ CURRENCY
											? sprintf(
													/* translators: %s: the shop's currency symbol. */
													__(
														'By (%s)',
														'catalogops'
													),
													CURRENCY
											  )
											: __(
													'By (amount)',
													'catalogops'
											  ) }
									</label>
									{ /* No ceiling, unlike the percentage: 200 off is
									     fine at 500 and nonsense at 50, and which is
									     which cannot be known without the product.
									     The write guard catches those one at a time,
									     and the preview shows them as skipped. */ }
									<input
										id="catalogops-amount"
										type="number"
										step="any"
										min="0"
										value={ amount }
										onChange={ ( e ) => {
											invalidate();
											setAmount( e.target.value );
										} }
									/>
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
										onChange={ ( e ) => {
											invalidate();
											setPercent( e.target.value );
										} }
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
										placeholder="roundto( cost * 1.35, 1 ) - 0.01"
										value={ expression }
										onChange={ ( e ) => {
											invalidate();
											setExpression( e.target.value );
										} }
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
												</code>{ ' ' }
												{ __(
													'— rounds to the nearest multiple of step',
													'catalogops'
												) }
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
												<code>sale_price</code> —{ ' ' }
												{ __(
													'make the discount permanent: the price becomes what the product is already selling for, and the sale stops showing. Products with no sale price are skipped.',
													'catalogops'
												) }
											</li>
											<li>
												<code>
													roundto( cost * 1.35, 1 ) -
													0.01
												</code>{ ' ' }
												—{ ' ' }
												{ __(
													'35% markup on cost, ending in .99',
													'catalogops'
												) }
											</li>
											<li>
												<code>cost / 0.7</code> —{ ' ' }
												{ __(
													'price for a 30% margin — a markup of 30% would be cost × 1.3, which leaves you 23%',
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
											<li>
												<code>
													regular_price / 1.2 * 1.25
												</code>{ ' ' }
												—{ ' ' }
												{ __(
													'carry a VAT change from 20% to 25% through, leaving the net price alone',
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
								{ changeHint( field, mode, hintExpression, {
									direction,
									amount: hintAmount,
								} ) }
							</p>
						</div>

						{ mode === 'percent' && percentExpression && (
							<div className="catalogops-filter-row">
								<p className="catalogops-field-hint catalogops-formula-help">
									{ sprintf(
										/* translators: %s: the generated formula. */
										__( 'Applies: %s', 'catalogops' ),
										percentExpression
									) }
								</p>
							</div>
						) }

						{ /* Preview belongs to the change, not to the commitment:
						     it asks what this would do, and the answer is the same
						     whether the change runs now or on a schedule. */ }
						<div className="catalogops-filter-row">
							<button
								className="button"
								onClick={ runPreview }
								disabled={ busy || running || ! ready }
							>
								{ __( 'Preview', 'catalogops' ) }
							</button>
							{ 'preview' === busyWith && (
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

						{ noticeFor( 'preview' ) }
						{ previewPanel }
					</div>
				</div>
			</div>

			{ /* When to run it. Apply and Schedule answer the same question, so
			     they are one choice with one commit button — not a primary action
			     and an optional extra hidden behind a collapsed section that never
			     mentioned the other. */ }
			<hr className="catalogops-divider" />

			<div className="catalogops-controls catalogops-schedule-form">
				<div className="catalogops-control-group">
					<span
						className="catalogops-group-label"
						id="catalogops-when-label"
					>
						{ __( 'When', 'catalogops' ) }
					</span>
					<div className="catalogops-filter-rows">
						<div className="catalogops-filter-row">
							<div
								className="catalogops-segmented"
								role="group"
								aria-labelledby="catalogops-when-label"
							>
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										when === 'now' ? ' is-active' : ''
									}` }
									onClick={ () => setWhen( 'now' ) }
								>
									{ __( 'Now', 'catalogops' ) }
								</button>
								<button
									type="button"
									className={ `catalogops-segmented__btn${
										when === 'schedule' ? ' is-active' : ''
									}${ canSchedule ? '' : ' is-locked' }` }
									onClick={ () => setWhen( 'schedule' ) }
									disabled={ ! canSchedule }
									title={
										canSchedule
											? undefined
											: __(
													'Available on a paid plan',
													'catalogops'
											  )
									}
								>
									{ __( 'On a schedule', 'catalogops' ) }
								</button>
							</div>
							{ ! canSchedule && (
								<UpsellNotice>
									{ __(
										'Schedule changes to run later or on a recurring basis with a paid plan.',
										'catalogops'
									) }
								</UpsellNotice>
							) }
						</div>

						{ 'now' === when && (
							<>
								<div className="catalogops-filter-row">
									<p className="catalogops-field-hint">
										{ sprintf(
											/* translators: %d: the number of days a change stays reversible. */
											__(
												'The change is written as soon as you confirm. It works through the catalog in the background, so you can leave this page — and the whole run can be undone from History for %d days.',
												'catalogops'
											),
											retentionDays || 30
										) }
									</p>
								</div>

								<div className="catalogops-filter-row">
									<button
										className="button button-primary"
										onClick={ () => setConfirming( true ) }
										disabled={
											busy ||
											running ||
											! ready ||
											confirming
										}
									>
										{ __( 'Apply', 'catalogops' ) }
									</button>
									{ 'apply' === busyWith && (
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
								{ noticeFor( 'apply' ) }
							</>
						) }

						{ 'schedule' === when && canSchedule && (
							<>
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

								<div
									className={ `catalogops-filter-row catalogops-schedule-preview notice ${ schedulePreviewTone }` }
								>
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
												<ReasonList
													items={ omittedBy }
												/>
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
										{ __(
											'Create schedule',
											'catalogops'
										) }
									</button>
									{ 'schedule' === busyWith && (
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
								{ noticeFor( 'schedule' ) }
								{ scheduleMsg && (
									<p className="catalogops-saved">
										{ scheduleMsg }
									</p>
								) }
							</>
						) }
					</div>
				</div>
			</div>

			{ confirming && (
				<div className="catalogops-confirm">
					{ backupAck && (
						<p className="catalogops-confirm__lead">
							{ __(
								'Apply this change to every matching item?',
								'catalogops'
							) }
						</p>
					) }
					<BackupReminder
						checked={ backupChecked }
						onCheck={ setBackupChecked }
					/>

					{ /* The history records what changed, when, by whom and how
					     many. This is the only place it can learn WHY, which is
					     the question asked months later when somebody wants to
					     know why three thousand prices moved.

					     Optional, and it stays optional: a note demanded on the
					     last step before a destructive action is a note filled in
					     with a full stop. It sits here rather than in the form
					     because this is already a deliberate pause, so it costs no
					     extra step — and because a reason written while looking at
					     the count is a better reason than one written before. */ }
					<div className="catalogops-field catalogops-confirm__note">
						<label htmlFor="catalogops-apply-note">
							{ __(
								'Note for the history (optional)',
								'catalogops'
							) }
						</label>
						<input
							id="catalogops-apply-note"
							type="text"
							maxLength={ 191 }
							value={ note }
							placeholder={ __(
								'e.g. supplier raised prices, approved by Ana',
								'catalogops'
							) }
							onChange={ ( e ) => setNote( e.target.value ) }
						/>
					</div>
					<div className="catalogops-confirm__actions">
						{ /* Green because this is the one that runs — the same
						     vocabulary the row actions already use, and the
						     counterpart of the red the delete confirmation wears. */ }
						<button
							className="button catalogops-button--go"
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

			<Pagination
				page={ page }
				pages={ pages }
				busy={ loading }
				onPage={ setPage }
			/>
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
	const [ page, setPage ] = useState( 1 );
	// Draft is what is typed; sku is the applied search, on Enter or Search —
	// the same split the audit table uses, so a half-typed SKU does not re-query.
	const [ draft, setDraft ] = useState( '' );
	const [ sku, setSku ] = useState( '' );
	const [ confirming, setConfirming ] = useState( false );
	const [ backupChecked, setBackupChecked ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const onboarding = useContext( OnboardingContext );

	useOperationPoll( operation, setOperation, onDone );

	// Its own flag, not `busy`: busy also covers starting the undo, and the table
	// must not say "Loading…" while the run it is about to launch is being queued.
	const loadPreview = useCallback(
		( withPolicy, atPage, forSku ) => {
			setLoading( true );
			setBusy( true );
			setError( '' );
			apiFetch( {
				path: `/catalogops/v1/operations/${ op.id }/undo/preview`,
				method: 'POST',
				data: {
					conflict_policy: withPolicy,
					page: atPage,
					per_page: CHANGES_PER_PAGE,
					sku: forSku,
				},
			} )
				.then( setPreview )
				.catch( ( err ) => setError( err.message ) )
				.finally( () => {
					setBusy( false );
					setLoading( false );
				} );
		},
		[ op.id ]
	);

	useEffect( () => {
		loadPreview( policy, page, sku );
	}, [ policy, page, sku, loadPreview ] );

	const applySearch = () => {
		setPage( 1 );
		setSku( draft.trim() );
	};

	const items = preview ? preview.items : [];
	const driftCount = items.filter( ( s ) => s.drift ).length;
	const pages = Math.max(
		1,
		Math.ceil( ( preview ? preview.matched : 0 ) / CHANGES_PER_PAGE )
	);

	// Confirmed on the panel, like deleting an operation or stopping a run, rather
	// than through window.confirm: a browser dialog cannot state the numbers or
	// which conflict policy is about to be used, which is the whole of what the
	// user is agreeing to here.
	const runUndo = () => {
		// The backup reminder guards this as it guards Apply, and with a sharper
		// reason: an undo writes at the same scale, it cannot itself be undone, and
		// under Force it discards work done after the operation — the one thing this
		// plugin can destroy that it never recorded and so can never give back.
		if ( ! onboarding.backup_ack ) {
			if ( ! backupChecked ) {
				return;
			}

			apiFetch( {
				path: '/catalogops/v1/settings/onboarding',
				method: 'POST',
				data: { backup_ack: true },
			} ).catch( () => {} );

			onboarding.onAcknowledge();
		}

		setConfirming( false );
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
				{ /* Drift is the one word here that means nothing until someone
				     explains it, and the choice above decides whether a later edit
				     survives — so the explanation is on the panel rather than
				     behind a tooltip, naming what each option does to it. */ }
				<div className="notice notice-info">
					<p>
						{ __(
							'“Drift” means the item changed after this operation ran — by hand, an import, or another plugin: Skip leaves those items exactly as they are now, while Force restores the value from before the operation and discards the later change.',
							'catalogops'
						) }
					</p>
				</div>
			</fieldset>

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ /* Outside the panel below, not inside it. The panel only exists once a
			     preview has arrived, so a notice placed within it could never appear
			     on the first open — which is the one time the wait is long enough to
			     need explaining, because the server is reading each object's current
			     value to work out what has drifted. */ }
			{ loading && ! operation && (
				<p className="catalogops-loading">
					{ __( 'Loading…', 'catalogops' ) }
				</p>
			) }

			{ preview && ! operation && (
				<div className="catalogops-preview">
					{ /* Count on the left, search on the right, one line above the
					     table — the shape the results table and the audit log already
					     use. The search earns its place here because this is the table
					     an undo is agreed to on: a fixed sample of the first rows left
					     "will the one I care about be skipped?" unanswerable on a
					     catalogue of any size. */ }
					<div className="catalogops-results-bar">
						<p>
							{ sprintf(
								/* translators: %d: number of recorded changes. */
								__(
									'%d changes will be reverted.',
									'catalogops'
								),
								preview.total
							) }
							{ sku !== '' &&
								' ' +
									sprintf(
										/* translators: 1: rows matching the search, 2: the SKU searched for. */
										__(
											'Showing the %1$d matching “%2$s” — the undo still covers all of them.',
											'catalogops'
										),
										preview.matched,
										sku
									) }
							{ driftCount > 0 &&
								' ' +
									sprintf(
										/* translators: %d: number of drifted objects on this page. */
										__(
											'%d on this page changed since the operation.',
											'catalogops'
										),
										driftCount
									) }
						</p>

						<div className="catalogops-search">
							<input
								id={ `undo-search-${ op.id }` }
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
								disabled={ busy }
							>
								{ __( 'Search', 'catalogops' ) }
							</button>
						</div>
					</div>

					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'SKU', 'catalogops' ) }</th>
								<th>{ __( 'Field', 'catalogops' ) }</th>
								<th>{ __( 'Now', 'catalogops' ) }</th>
								<th>{ __( 'Restore to', 'catalogops' ) }</th>
								<th>{ __( 'Outcome', 'catalogops' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ items.length === 0 && ! loading && (
								<tr>
									<td colSpan="5">
										{ sku === ''
											? __(
													'Nothing to revert.',
													'catalogops'
											  )
											: __(
													'No item with that SKU was changed by this run.',
													'catalogops'
											  ) }
									</td>
								</tr>
							) }
							{ items.map( ( s, i ) => (
								<tr
									key={ i }
									className={
										s.action === 'skip' ? 'is-drift' : ''
									}
								>
									<td>
										{ s.sku || (
											<span className="catalogops-muted">
												#{ s.id }
											</span>
										) }
									</td>
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

					<Pagination
						page={ page }
						pages={ pages }
						busy={ busy }
						onPage={ setPage }
					/>

					{ /* Everything above is the evidence — the rows, the search, the
					     pager. Everything below acts on it. The rule says which is
					     which, so the button does not read as another table control. */ }
					<hr className="catalogops-divider" />

					{ /* The button gives way to the question rather than sitting
					     above it: two "Run undo" controls on screen at once leaves
					     the user guessing which one is the real one. Disabled on the
					     whole undo being empty, never on the page or the search being
					     empty — searching narrows what is shown, not what would
					     run. */ }
					{ ! confirming && (
						<button
							className="button catalogops-button--undo"
							onClick={ () => setConfirming( true ) }
							disabled={ busy || running || preview.total === 0 }
						>
							{ __( 'Run undo', 'catalogops' ) }
						</button>
					) }

					{ confirming && (
						<div className="catalogops-confirm">
							<p className="catalogops-confirm__lead">
								{ sprintf(
									/* translators: 1: operation id, 2: number of recorded changes. */
									__(
										'Undo operation #%1$d — %2$d recorded changes?',
										'catalogops'
									),
									op.id,
									preview.total
								) }
							</p>
							<p>
								{ policy === 'skip'
									? __(
											'Every item still holding the value this run gave it goes back to what it was before. Anything changed since is left exactly as it is now and reported as skipped.',
											'catalogops'
									  )
									: __(
											'Every item goes back to what it was before this run — including those changed since, whose later value is discarded. This is the forcing option.',
											'catalogops'
									  ) }
							</p>
							<p>
								{ __(
									'It runs in the background and can be paused from the history while it works. Undoing cannot itself be undone.',
									'catalogops'
								) }
							</p>
							{ /* Only present when the server found a schedule behind this
							     run that is still active. Undoing pauses it, and saying so
							     here is the only place the two facts meet: without it the
							     next tick rebuilds the same operation from the same stored
							     template and writes back exactly what is being reverted,
							     and the schedules card is a different screen that would
							     contradict the history long after the change was already
							     back. */ }
							{ preview.schedule && (
								<p>
									{ sprintf(
										/* translators: %s: the schedule's name, or #id when it has none. */
										__(
											'This run came from the schedule “%s”, which is still active. Undoing pauses it, so it cannot put the change back — resume it from Schedules whenever you want it running again.',
											'catalogops'
										),
										preview.schedule.name ||
											`#${ preview.schedule.id }`
									) }
								</p>
							) }
							<BackupReminder
								checked={ backupChecked }
								onCheck={ setBackupChecked }
							/>
							<div className="catalogops-confirm__actions">
								{ /* Orange, like the undo icon in the row above: this
								     app gives reversing its own colour, and the
								     confirmation is not the place to change dialect. */ }
								<button
									className="button catalogops-button--undo"
									onClick={ runUndo }
									disabled={
										busy ||
										( ! onboarding.backup_ack &&
											! backupChecked )
									}
								>
									{ __( 'Run undo', 'catalogops' ) }
								</button>
								<button
									className="button"
									onClick={ () => setConfirming( false ) }
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
										{ __( 'Starting…', 'catalogops' ) }
									</span>
								) }
							</div>
						</div>
					) }
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
 * The two are the same string for every button but one. A button whose tooltip
 * carries CONTENT rather than a name — the history's note — needs them apart: the
 * bubble should show the note, while the accessible name has to say what the note
 * is before reading it out.
 *
 * @param {Object}   props             Component props.
 * @param {string}   props.icon        Dashicons name, without the `dashicons-` prefix.
 * @param {string}   props.label       What the button does; the accessible name.
 * @param {string}   props.variant     Colour role: 'view', 'undo' or 'danger'.
 * @param {Function} props.onClick     Click handler.
 * @param {boolean}  props.isActive    Whether its panel is currently open.
 * @param {boolean}  props.disabled    Whether it is unavailable.
 * @param {string}   props.tooltip     Bubble text, when it differs from the label.
 * @param {boolean}  props.wideTooltip Let the bubble wrap, for a sentence rather
 *                                     than a couple of words.
 */
function IconButton( {
	icon,
	label,
	variant,
	onClick,
	isActive = false,
	disabled = false,
	tooltip = '',
	wideTooltip = false,
} ) {
	return (
		<button
			type="button"
			className={ `catalogops-icon-button catalogops-icon-button--${ variant }${
				isActive ? ' is-active' : ''
			}${ wideTooltip ? ' has-wide-tooltip' : '' }` }
			onClick={ onClick }
			disabled={ disabled }
			data-tooltip={ tooltip || label }
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
 * What one run actually did: which objects it targeted, and what it changed.
 *
 * Both halves have been in the database since the first release and read by
 * nothing — the history could say a run touched 1,204 products and never what
 * made them the 1,204, or what happened to them. A record of a bulk edit that
 * cannot say what the edit was is a receipt, not an audit trail.
 *
 * Fetched when the panel opens rather than carried by the list, because the list
 * polls every few seconds and this is two long JSON columns plus the term lookups
 * that turn ids into names.
 *
 * The wording is the server's. Naming a condition needs term names from the
 * database and field labels from the module registry, neither of which this
 * bundle has or should have — the same reason a module serves its own options
 * rather than teaching the client about itself.
 *
 * @param {Object} props    Component props.
 * @param {number} props.id The operation to describe.
 */
function OperationSummary( { id } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let live = true;

		apiFetch( { path: `/catalogops/v1/operations/${ id }/summary` } )
			.then( ( res ) => {
				if ( live ) {
					setData( res );
				}
			} )
			.catch( ( err ) => {
				if ( live ) {
					setError( err.message );
				}
			} );

		return () => {
			live = false;
		};
	}, [ id ] );

	if ( error ) {
		return <p className="catalogops-field-error">{ error }</p>;
	}

	if ( ! data ) {
		return (
			<p className="catalogops-loading">
				{ __( 'Loading…', 'catalogops' ) }
			</p>
		);
	}

	return (
		<div className="catalogops-summary">
			<div className="catalogops-summary__part">
				<h4>{ __( 'It targeted', 'catalogops' ) }</h4>
				<p className="catalogops-muted">
					{ sprintf(
						/* translators: 1: "Products" or "Variations". 2: "all of these" or "any of these". */
						__( '%1$s matching %2$s:', 'catalogops' ),
						data.scope,
						data.relation
					) }
				</p>
				{ /* An empty filter is not an empty list — it is every object in
				     the scope, and saying nothing here would read as a summary
				     that failed to load rather than as a run that targeted the
				     whole catalogue. */ }
				{ data.conditions.length === 0 ? (
					<p className="catalogops-muted">
						{ __(
							'No conditions — the whole catalogue.',
							'catalogops'
						) }
					</p>
				) : (
					<ul>
						{ data.conditions.map( ( c, i ) => (
							<li key={ i }>
								<strong>{ c.label }</strong> { c.operator }
								{ c.value && <> { c.value }</> }
							</li>
						) ) }
					</ul>
				) }
			</div>

			<div className="catalogops-summary__part">
				<h4>{ __( 'It changed', 'catalogops' ) }</h4>
				<ul>
					{ data.actions.map( ( a, i ) => (
						<li key={ i }>
							<strong>{ a.label }</strong> { a.change }
						</li>
					) ) }
				</ul>
			</div>

			{ /* The filter was frozen when the run was queued, so this is what
			     ran — not what the same conditions would match today. Saying so
			     is the difference between a record and a guess, and the whole
			     preview-equals-run promise rests on that freeze. */ }
			<p className="catalogops-muted catalogops-summary__note">
				{ __(
					'This is the filter as it was frozen when the run started, not what it would match now.',
					'catalogops'
				) }
			</p>
		</div>
	);
}

/**
 * One row of the operation history, expandable to its audit detail or undo flow.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.op        The operation.
 * @param {Function} props.onChanged Called when an undo or delete from this row
 *                                   finishes, so the list reloads.
 * @param {boolean}  props.offline   Whether the last poll failed, in which case
 *                                   the row's numbers are stale and its controls
 *                                   are withheld rather than shown as usable.
 */
function OperationRow( { op, onChanged, offline = false } ) {
	const [ open, setOpen ] = useState( null ); // 'changes' | 'undo' | 'delete' | null
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const toggle = ( which ) =>
		setOpen( ( cur ) => ( cur === which ? null : which ) );

	// Deleting mid-write would strand the operation's remaining chunks, so the
	// control is closed off until the run is cancelled.
	const stillRunning = op.status === 'queued' || op.status === 'running';

	// One control, one result — a paused run with its frozen list intact — under
	// two names, because the user is not doing the same thing. Pausing a run that is
	// working is a considered interruption; stopping one that has died is how you
	// take it back, and it is the only way to, since a stalled run still holds the
	// write lock and Resume is therefore not offered. The stalled notice below tells
	// them to press Stop, so the button has to say Stop.
	const stopping = op.is_stalled;

	// Silence worth mentioning, well short of silence worth alarming about. Chunks
	// land seconds apart, so a minute without one is already unusual — but saying so
	// quietly, with a number that grows, is a different act from declaring the run
	// dead, and the reader can tell the two apart without being told which is which.
	const quiet =
		! op.is_stalled &&
		typeof op.quiet_seconds === 'number' &&
		op.quiet_seconds >= QUIET_AFTER_SECONDS;

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

	// The route has existed since M2 and nothing called it: the history offered a
	// disabled Delete whose tooltip told you to cancel the run first, and there was
	// nowhere to do that. Undo is refused while an operation is active, so without
	// this the only way to stop a run that is writing the wrong thing was to wait
	// for it to finish.
	const confirmResume = () => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: `/catalogops/v1/operations/${ op.id }/resume`,
			method: 'POST',
		} )
			.then( () => {
				setOpen( null );
				onChanged();
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const confirmCancel = () => {
		setBusy( true );
		setError( '' );
		apiFetch( {
			// Two routes, because they mean two different things to the schedule
			// behind the run: stopping a working run is the user's decision and
			// pauses it, taking back a dead one is a machine failure and must not.
			path: `/catalogops/v1/operations/${ op.id }/${
				stopping ? 'take-over' : 'cancel'
			}`,
			method: 'POST',
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
				<td>
					{ op.source }
					{ /* Which schedule made this run. The name is the one stored on
					     the row when it was created, not one looked up now: a
					     schedule that is deleted or renamed must not be able to
					     rewrite the history of the runs it made. An older run, or
					     one whose schedule was gone before the name was ever
					     recorded, falls back to the id — an unnamed attribution is
					     still an attribution, and blank would lose it. */ }
					{ op.schedule_id && (
						<span className="catalogops-source-schedule">
							{ op.schedule_name ||
								sprintf(
									/* translators: %d: the schedule's id, shown when its name was never recorded or the schedule is gone. */
									__( 'schedule #%d', 'catalogops' ),
									op.schedule_id
								) }
						</span>
					) }
				</td>
				<td>
					{ /* A run whose host died stays `running` with a progress bar that
					     has simply stopped — the same badge and the same numbers as a
					     slow one. The server decides which it is, from the threshold the
					     watchdog itself uses, so the two can never disagree. */ }
					<span
						className={ `catalogops-badge catalogops-status-badge is-${
							op.is_stalled ? 'stalled' : op.status
						}` }
					>
						{ op.is_stalled
							? __( 'Not responding', 'catalogops' )
							: op.status }
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
				{ /* The site's clock, not GMT — and the fallback keeps an older
				     payload readable rather than blank. The schedules card next to
				     this one has always shown local time, so a raw GMT stamp here
				     did not read as a timezone question: it read as the run having
				     fired at the wrong hour. */ }
				<td>{ op.created_at_local || op.created_at }</td>
				<td className="catalogops-cell--actions">
					<div className="catalogops-actions">
						{ /* First in the row, because it is the only one that
						     is not always there. The others sit in a fixed order
						     by consequence — look, undo, stop, delete — and a
						     button that appears and disappears in the middle of
						     that shifts every icon after it, so the same action
						     is under the pointer in one row and not the next.
						     Leading, it moves nothing.

						     Shown only when there is one, which is the whole
						     design: printing every note down the table buries the
						     history under its own explanations, but a note nobody
						     can tell is there is a note nobody wrote. The icon's
						     PRESENCE carries that — you can see which runs were
						     explained without reading any of them — and hovering
						     reads the note itself, at no height.

						     It still opens on click, and that is not redundant: a
						     tooltip does not exist on a touch screen, and text
						     inside one cannot be selected or copied. Hover is the
						     quick path; the panel is the one that always works. */ }
						{ op.note && (
							<IconButton
								// A speech bubble, not a document. `format-aside`
								// sat beside `list-view` in the same green and the
								// two were read as one control twice over — same
								// shape, same meaning-colour. This says "somebody
								// wrote something" and nothing else does.
								icon="admin-comments"
								variant="note"
								// The tooltip IS the note, so hovering reads it
								// without a click. The accessible name has to say
								// what it is rather than only what it says, and a
								// screen reader gets both in one string.
								label={ sprintf(
									/* translators: %s: the note the user wrote before running this operation. */
									__( 'Why this was run: %s', 'catalogops' ),
									op.note
								) }
								tooltip={ op.note }
								wideTooltip
								onClick={ () => toggle( 'note' ) }
								isActive={ open === 'note' }
							/>
						) }
						{ /* Always there, so it sits in the fixed run rather
						     than ahead of it. Grey for the same reason the note
						     is: it says something and changes nothing, and the
						     five colours all name a kind of change. */ }
						<IconButton
							icon="info-outline"
							variant="note"
							label={ __( 'What this run did', 'catalogops' ) }
							onClick={ () => toggle( 'summary' ) }
							isActive={ open === 'summary' }
						/>
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
						{ stillRunning && (
							<IconButton
								icon={ stopping ? 'dismiss' : 'controls-pause' }
								variant="pause"
								label={
									stopping
										? __( 'Stop this run', 'catalogops' )
										: __( 'Pause this run', 'catalogops' )
								}
								onClick={ () => toggle( 'cancel' ) }
								isActive={ open === 'cancel' }
								disabled={ offline }
							/>
						) }
						{ /* Only when there is frozen work left and nothing is
						     writing it — a run the watchdog failed after a restart,
						     or one that was stopped. Green: it is the one that
						     runs. */ }
						{ op.can_resume && (
							<IconButton
								icon="controls-play"
								variant="run"
								label={ __( 'Resume this run', 'catalogops' ) }
								onClick={ () => toggle( 'resume' ) }
								isActive={ open === 'resume' }
								disabled={ offline }
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
											'Pause the run before deleting it',
											'catalogops'
									  )
									: __( 'Delete from history', 'catalogops' )
							}
							onClick={ () => toggle( 'delete' ) }
							isActive={ open === 'delete' }
							disabled={ stillRunning || offline }
						/>
					</div>
				</td>
			</tr>
			{ /* Its own full-width row, never a cell. Put in the Status column this
			     text pushed Progress, By, Created and Actions into a narrow strip and
			     wrapped the icon buttons one under another — a table that reflows
			     because one row has something to say is worse than the silence it
			     replaced. Always visible rather than behind a toggle: a run nobody is
			     writing is not a detail to go looking for. */ }
			{ ( op.is_stalled || quiet ) && (
				<tr className="catalogops-detail">
					<td colSpan="6">
						<p className="catalogops-muted">
							{ op.is_stalled
								? sprintf(
										/* translators: 1: how long it has been quiet, e.g. "12m 4s". 2: number of items still frozen and unwritten. */
										__(
											'Nothing has written this for %1$s. It is picked up again automatically, and its schedule keeps running — or Stop it to take over, which keeps the %2$d frozen items for Resume to finish.',
											'catalogops'
										),
										quietFor( op.quiet_seconds ),
										op.pending
								  )
								: sprintf(
										/* translators: %s: how long it has been quiet, e.g. "1m 20s". */
										__(
											'No progress for %s. A pause between chunks is normal; if the run has stopped, it is restarted automatically.',
											'catalogops'
										),
										quietFor( op.quiet_seconds )
								  ) }
						</p>
					</td>
				</tr>
			) }
			{ open && (
				<tr className="catalogops-detail">
					<td colSpan="6">
						{ open === 'note' && (
							<p className="catalogops-op-note">{ op.note }</p>
						) }
						{ open === 'summary' && (
							<OperationSummary id={ op.id } />
						) }
						{ open === 'changes' && <ChangesTable id={ op.id } /> }
						{ open === 'resume' && (
							<div className="catalogops-confirm">
								<p className="catalogops-confirm__lead">
									{ sprintf(
										/* translators: 1: operation id, 2: items still waiting. */
										__(
											'Resume operation #%1$d — %2$d items still waiting?',
											'catalogops'
										),
										op.id,
										op.pending
									) }
								</p>
								<p>
									{ __(
										'It carries on down the list this run froze when it started, so it changes exactly what was approved then. Running the filter again instead would resolve it against the catalog as it is now, which may no longer be the same set of products.',
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
										className="button catalogops-button--go"
										onClick={ confirmResume }
										disabled={ busy }
									>
										{ __( 'Resume', 'catalogops' ) }
									</button>
									<button
										className="button"
										onClick={ () => setOpen( null ) }
										disabled={ busy }
									>
										{ __( 'Leave it', 'catalogops' ) }
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
											{ __( 'Starting…', 'catalogops' ) }
										</span>
									) }
								</div>
							</div>
						) }
						{ open === 'cancel' && (
							<div className="catalogops-confirm">
								{ /* Two whole calls rather than one with a computed format
								     string: the .pot scanner reads literals, so a ternary
								     inside sprintf() or __() extracts as nothing at all and
								     the sentence never reaches a translator. */ }
								<p className="catalogops-confirm__lead">
									{ stopping
										? sprintf(
												/* translators: %d: operation id. */
												__(
													'Stop operation #%d?',
													'catalogops'
												),
												op.id
										  )
										: sprintf(
												/* translators: %d: operation id. */
												__(
													'Pause operation #%d?',
													'catalogops'
												),
												op.id
										  ) }
								</p>
								<p>
									{ sprintf(
										/* translators: 1: objects already written, 2: objects targeted. */
										__(
											'It stops at the end of the chunk it is writing now. The %1$d of %2$d items already changed stay changed — this is not the same as undoing — but once it has stopped you can undo it from this row.',
											'catalogops'
										),
										op.processed,
										op.target_count
									) }
								</p>
								{ /* Said before the click, not discovered after it — and
								     the two cases say opposite things on purpose. Stopping
								     a working run is a decision about the change, so its
								     schedule pauses with it. Taking back a run whose
								     process died is not a decision about anything, so the
								     schedule keeps its hours: nobody should have to get up
								     in the night because a server restarted. */ }
								{ op.schedule_id && ! stopping && (
									<p>
										{ __(
											'This run came from a schedule. That schedule is paused too, so it does not begin the same work again on its next tick — resume it from Schedules when you want it running.',
											'catalogops'
										) }
									</p>
								) }
								{ op.schedule_id && stopping && (
									<p>
										{ __(
											'This run came from a schedule. The schedule keeps running on its usual hours — a run that stopped responding is a machine failure, not a change of mind, so nothing about the schedule is altered.',
											'catalogops'
										) }
									</p>
								) }
								{ error && (
									<div className="notice notice-error">
										<p>{ error }</p>
									</div>
								) }
								<div className="catalogops-confirm__actions">
									<button
										className="button"
										onClick={ confirmCancel }
										disabled={ busy }
									>
										{ stopping
											? __( 'Stop the run', 'catalogops' )
											: __(
													'Pause the run',
													'catalogops'
											  ) }
									</button>
									<button
										className="button"
										onClick={ () => setOpen( null ) }
										disabled={ busy }
									>
										{ __( 'Keep running', 'catalogops' ) }
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
											{ stopping
												? __(
														'Stopping…',
														'catalogops'
												  )
												: __(
														'Pausing…',
														'catalogops'
												  ) }
										</span>
									) }
								</div>
							</div>
						) }
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
 * @param {boolean}  props.firingSoon Whether a schedule is due, so a row is
 *                                    about to appear here without anyone in
 *                                    the browser having asked for it.
 */
function History( { refreshKey, onChanged, firingSoon = false } ) {
	const [ items, setItems ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ tick, setTick ] = useState( 0 );
	// The server decides the page size; the pager only needs to know how many
	// pages that makes, and never guesses when the list is still empty.
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ perPage, setPerPage ] = useState( 10 );
	const timer = useRef( null );

	// The list re-asks the server on a cadence that follows the work: fast while
	// something is moving, slow while nothing is — but never stopped.
	//
	// It used to schedule the next poll only when the response it had just received
	// already contained a running operation, which made it self-sustaining but not
	// self-starting. A tab left open while every operation was finished went
	// dormant for good, so a run a schedule began at 03:00 was invisible until
	// someone reloaded — exactly the unattended case schedules exist for. The slow
	// cadence is what notices a run beginning; the fast one is what follows it.
	//
	// A hidden tab does not fetch at all, and coming back to it asks immediately,
	// so returning shows the current state rather than the one it was left with.
	useEffect( () => {
		let cancelled = false;

		const again = ( ms ) => {
			if ( ! cancelled ) {
				timer.current = setTimeout(
					() => setTick( ( t ) => t + 1 ),
					ms
				);
			}
		};

		if ( document.hidden ) {
			again( HISTORY_POLL_IDLE_MS );

			return () => {
				cancelled = true;
				clearTimeout( timer.current );
			};
		}

		// The history a user sees is their own language's, plus the runs that
		// belong to no language — which includes every run made before the plugin
		// knew about languages, so upgrading never looks like the past was wiped.
		// On "All languages" nothing is sent and every run is listed.
		const language = currentLanguage();

		apiFetch( {
			path: `/catalogops/v1/operations?page=${ page }${
				language ? `&language=${ encodeURIComponent( language ) }` : ''
			}`,
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setItems( res.items );
				setTotal( res.total || res.items.length );
				setPerPage( res.per_page || 10 );
				setError( '' );
				// Fast while something is moving, and equally fast while a
				// schedule is due — that is the window in which a row is about to
				// appear here, and this list has no other way to know it is coming.
				const moving = res.items.some( ( op ) => ! isTerminal( op ) );
				again(
					moving || firingSoon
						? HISTORY_POLL_ACTIVE_MS
						: HISTORY_POLL_IDLE_MS
				);
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( err.message );
				// A failed poll must not be the end of polling: one dropped request
				// would otherwise freeze the list for the rest of the session, which
				// is the failure this whole effect was rewritten to remove.
				again( HISTORY_POLL_IDLE_MS );
			} );

		return () => {
			cancelled = true;
			clearTimeout( timer.current );
		};
		// firingSoon belongs here: when a schedule becomes due the pending slow
		// timer has to be replaced by a fast one, not waited out.
	}, [ refreshKey, tick, page, firingSoon ] );

	useEffect( () => {
		const onVisibility = () => {
			if ( ! document.hidden ) {
				setTick( ( t ) => t + 1 );
			}
		};

		document.addEventListener( 'visibilitychange', onVisibility );

		return () =>
			document.removeEventListener( 'visibilitychange', onVisibility );
	}, [] );

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
							<td colSpan="6">
								{ __( 'No operations yet.', 'catalogops' ) }
							</td>
						</tr>
					) : (
						items.map( ( op ) => (
							<OperationRow
								key={ op.id }
								op={ op }
								onChanged={ onChanged }
								// Every row's numbers are as old as the last poll
								// that worked. While polling is failing, offering
								// controls invites a click that cannot arrive — and
								// worse, one decided on a snapshot that may be
								// minutes stale.
								offline={ error !== '' }
							/>
						) )
					) }
				</tbody>
			</table>

			<Pagination
				page={ page }
				pages={ Math.ceil( total / perPage ) }
				onPage={ setPage }
			/>
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
			<div className="catalogops-retention__row">
				<input
					type="number"
					min={ data.min }
					max={ data.max }
					value={ days }
					onChange={ ( e ) => setDays( e.target.value ) }
				/>
				<button className="button" onClick={ save }>
					{ __( 'Save', 'catalogops' ) }
				</button>
				{ saved && (
					<span className="catalogops-saved">
						{ __( 'Saved.', 'catalogops' ) }
					</span>
				) }
			</div>
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
	// 'delete' | 'resume' | null — two questions worth asking before acting, and
	// only one of them can be open at a time.
	const [ confirming, setConfirming ] = useState( null );

	const done = schedule.status === 'completed';
	const name = schedule.name || `#${ schedule.id }`;

	// Pausing hides a schedule from the supervisor but does not stop its clock, and
	// resuming does not reset it: a schedule paused past its due time is due again
	// the moment it comes back, so Resume is a delayed Run now. Worth a question
	// first — but only when it is actually true, or the warning becomes noise on
	// every schedule that was paused early and is nowhere near its time.
	const resumesIntoARun = schedule.is_overdue;

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
								onClick={ () =>
									resumesIntoARun
										? setConfirming(
												confirming === 'resume'
													? null
													: 'resume'
										  )
										: onAct( schedule.id, 'resume' )
								}
								isActive={ confirming === 'resume' }
								disabled={ busy }
							/>
						) }
						<IconButton
							icon="trash"
							variant="danger"
							label={ __( 'Delete schedule', 'catalogops' ) }
							onClick={ () =>
								setConfirming(
									confirming === 'delete' ? null : 'delete'
								)
							}
							isActive={ confirming === 'delete' }
							disabled={ busy }
						/>
					</div>
				</td>
			</tr>
			{ /* A schedule that stopped has to say why, or the only control on offer —
			     Resume — just stops it again on the next tick. Its own full-width row
			     rather than the Status cell: these reasons are whole sentences now,
			     and in a cell they squeezed Repeat, Next run, Last run and Actions
			     into a strip and wrapped the buttons one under another. The text is
			     the server's own message, rendered as it arrives, like every other
			     error in this app. */ }
			{ schedule.paused_reason && (
				<tr className="catalogops-detail">
					<td colSpan="6">
						<p className="catalogops-muted">
							{ schedule.paused_reason }
						</p>
					</td>
				</tr>
			) }
			{ confirming === 'resume' && (
				<tr className="catalogops-detail">
					<td colSpan="6">
						<div className="catalogops-confirm">
							<p className="catalogops-confirm__lead">
								{ sprintf(
									/* translators: %s: schedule name. */
									__(
										'Resume “%s” — it will run shortly.',
										'catalogops'
									),
									name
								) }
							</p>
							<p>
								{ sprintf(
									/* translators: 1: the schedule's due time, 2: minutes between supervisor runs. */
									__(
										'Its next run was due at %1$s, which has passed — pausing hid it, but did not move it. Resuming makes it due again, so it will run within about %2$d minutes.',
										'catalogops'
									),
									schedule.next_run_local ||
										schedule.next_run,
									CRON.supervisorMinutes || 5
								) }
							</p>
							<p>
								{ __(
									'It runs once, not once for every run it missed, and then goes back to its normal times.',
									'catalogops'
								) }
							</p>
							<div className="catalogops-confirm__actions">
								<button
									className="button button-primary"
									onClick={ () => {
										setConfirming( null );
										onAct( schedule.id, 'resume' );
									} }
									disabled={ busy }
								>
									{ __( 'Resume it', 'catalogops' ) }
								</button>
								<button
									className="button"
									onClick={ () => setConfirming( null ) }
									disabled={ busy }
								>
									{ __( 'Leave it paused', 'catalogops' ) }
								</button>
							</div>
						</div>
					</td>
				</tr>
			) }
			{ confirming === 'delete' && (
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
										setConfirming( null );
										onDelete( schedule.id );
									} }
									disabled={ busy }
								>
									{ __( 'Delete permanently', 'catalogops' ) }
								</button>
								<button
									className="button"
									onClick={ () => setConfirming( null ) }
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

function Schedules( { refreshKey, onRan, onFiringSoon } ) {
	const canSchedule = can( 'canSchedule' );
	const [ items, setItems ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ localKey, setLocalKey ] = useState( 0 );
	const [ tick, setTick ] = useState( 0 );
	const timer = useRef( null );
	// The schedule row a request is in flight for, so its buttons show a spinner
	// and disable rather than leaving the user unsure anything happened.
	const [ busyId, setBusyId ] = useState( null );
	const [ runMsg, setRunMsg ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ perPage, setPerPage ] = useState( 10 );

	// This list had no timer at all, so a schedule that fired went on reading
	// "Active", with an empty Last run and a Next run that had already passed,
	// until someone reloaded the page — while the operation it had spawned was
	// finishing in the history below. It polls on the same terms as the history
	// now: quick while a schedule is due, unhurried otherwise, nothing at all
	// while the tab is hidden.
	useEffect( () => {
		let cancelled = false;

		const again = ( ms ) => {
			if ( ! cancelled ) {
				timer.current = setTimeout(
					() => setTick( ( t ) => t + 1 ),
					ms
				);
			}
		};

		if ( document.hidden ) {
			again( HISTORY_POLL_IDLE_MS );

			return () => {
				cancelled = true;
				clearTimeout( timer.current );
			};
		}

		// The schedules a user sees are their own language's, plus the ones that
		// belong to no language — which includes every schedule written before the
		// plugin knew about languages. On "All languages" nothing is sent and every
		// schedule is listed. This is a listing rule only: which schedules FIRE is
		// decided on the server by a cron tick that has no language at all.
		const language = currentLanguage();

		apiFetch( {
			path: `/catalogops/v1/schedules?page=${ page }${
				language ? `&language=${ encodeURIComponent( language ) }` : ''
			}`,
		} )
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				setItems( res.items );
				setTotal( res.total || res.items.length );
				setPerPage( res.per_page || 10 );
				// The history list has always cleared its own error here, and this one
				// did not: a single dropped request — a restarted server, a dropped
				// connection — left "Could not get a valid response from the server."
				// above a table that was, by then, refreshing perfectly. Worse than
				// untidy, because the notice sits beside a Paused badge and invites the
				// reader to blame the outage for a pause that has nothing to do with it.
				setError( '' );

				// Due and still active means it is about to fire, or is firing
				// right now. The history list cannot see this and needs telling,
				// because that is when its own new row is coming.
				const due = res.items.some(
					( s ) => 'active' === s.status && s.is_overdue
				);

				if ( onFiringSoon ) {
					onFiringSoon( due );
				}

				again( due ? POLL_DUE_MS : HISTORY_POLL_IDLE_MS );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError( err.message );
				again( HISTORY_POLL_IDLE_MS );
			} );

		return () => {
			cancelled = true;
			clearTimeout( timer.current );
		};
	}, [ refreshKey, localKey, page, tick, onFiringSoon ] );

	useEffect( () => {
		const onVisibility = () => {
			if ( ! document.hidden ) {
				setTick( ( t ) => t + 1 );
			}
		};

		document.addEventListener( 'visibilitychange', onVisibility );

		return () =>
			document.removeEventListener( 'visibilitychange', onVisibility );
	}, [] );

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

	// On a plan without scheduling the card would otherwise stand there with an
	// empty table and instructions for a control the user cannot reach. It says
	// what it is instead. Existing schedules still show: a plan can lapse, and the
	// endpoints that pause, run and delete are deliberately ungated so nobody is
	// locked out of cleaning up what they already made.
	const gated = ! canSchedule && items.length === 0;

	return (
		<div className="catalogops-card catalogops-schedules">
			<h2>{ __( 'Schedules', 'catalogops' ) }</h2>
			<p className="description">
				{ canSchedule
					? __(
							'Operations set to run later or on a recurring basis. Create one in Bulk edit above: set the change, then pick “On a schedule” under When. A completion report is emailed for each run.',
							'catalogops'
					  )
					: __(
							'Run a change later, or over and over — nightly repricing, a sale that starts on Friday — with a completion report emailed for each run.',
							'catalogops'
					  ) }
			</p>

			{ ! canSchedule && (
				<UpsellNotice>
					{ __(
						'Scheduling is available on a paid plan.',
						'catalogops'
					) }
				</UpsellNotice>
			) }

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

			{ ! gated && (
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
										{ __(
											'No schedules yet.',
											'catalogops'
										) }
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
			) }

			{ ! gated && (
				<Pagination
					page={ page }
					pages={ Math.ceil( total / perPage ) }
					busy={ busyId !== null }
					onPage={ setPage }
				/>
			) }
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
	// Discovery data for the category and brand dropdowns.
	const [ categories, setCategories ] = useState( [] );
	const [ tags, setTags ] = useState( [] );
	const [ brands, setBrands ] = useState( [] );
	const [ moduleFields, setModuleFields ] = useState( [] );
	const [ moduleFieldsLoaded, setModuleFieldsLoaded ] = useState( false );
	const [ attributes, setAttributes ] = useState( [] );
	// Bumped whenever a schedule is created or acted on, to reload the list.
	const [ schedulesKey, setSchedulesKey ] = useState( 0 );
	// Raised by the schedules list when one of its rows is due, and read by the
	// history list, which has no way of knowing a run is about to appear in it.
	// Passing the setter itself keeps the reference stable, so it can sit in the
	// schedules effect's dependencies without re-running it on every render.
	const [ firingSoon, setFiringSoon ] = useState( false );
	// Whether the filter, table, and bulk edit target parent products or their
	// variations (CONTEXT §4).
	const [ scope, setScope ] = useState( 'product' );
	// The filter that was actually applied to the table (frozen on Apply), so
	// bulk edits target what the user is looking at.
	const [ appliedFilter, setAppliedFilter ] = useState( () =>
		buildFilter( emptyForm(), 'product', [], currentLanguage() )
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
		// The pickers list the CURRENT language's terms, because a translated term
		// is a different term with a different id — Accessories is 18 in English
		// and 73 in Serbian. A picker filled in one language hands the engine ids
		// that no product in the other carries, and the filter comes back empty
		// with nothing about it looking wrong.
		const inLanguage = ( route ) => {
			const language = currentLanguage();

			return `/catalogops/v1/fields/${ route }${
				language ? `?language=${ encodeURIComponent( language ) }` : ''
			}`;
		};

		apiFetch( { path: inLanguage( 'categories' ) } )
			.then( ( res ) => setCategories( res.categories || [] ) )
			.catch( () => {} );
		apiFetch( { path: inLanguage( 'tags' ) } )
			.then( ( res ) => setTags( res.tags || [] ) )
			.catch( () => {} );
		apiFetch( { path: inLanguage( 'brands' ) } )
			.then( ( res ) => setBrands( res.brands || [] ) )
			.catch( () => {} );
		apiFetch( { path: inLanguage( 'attributes' ) } )
			.then( ( res ) => setAttributes( res.attributes || [] ) )
			.catch( () => {} );
		// The fields modules register. An installation with none answers an empty
		// list, and the section below then renders nothing at all — which is what
		// every site looks like until a module ships.
		//
		// The `finally` is what separates "not asked yet" from "asked, nothing
		// came back". Both are an empty list, and they must not look alike: the
		// first is worth holding a place for, the second is worth forgetting.
		apiFetch( { path: inLanguage( 'filterable' ) } )
			.then( ( res ) => setModuleFields( res.fields || [] ) )
			.catch( () => {} )
			.finally( () => setModuleFieldsLoaded( true ) );
	}, [] );

	// The terms of the currently-selected attribute, for the value dropdown.
	const selectedAttribute = attributes.find(
		( a ) => a.field === form.attribute
	);

	const run = useCallback(
		( toPage ) => {
			const filter = buildFilter(
				form,
				scope,
				moduleFields,
				currentLanguage()
			);
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
		[ form, scope, moduleFields ]
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
		// The schedules list too, because an undo can now change it: reverting a
		// scheduled run pauses the schedule that made it. Without this the
		// confirmation says the schedule is about to be paused, the history row
		// flips at once, and the card that would prove it goes on showing Active
		// until its own poll comes round — the one screen that settles the question
		// being the last to answer it.
		setSchedulesKey( ( k ) => k + 1 );
	}, [ run, page ] );

	// Clear the filter, its results, and (via resetKey) the bulk-edit and
	// schedule inputs — a clean slate for the next operation.
	const resetAll = useCallback( () => {
		const empty = emptyForm();
		setForm( empty );
		setAppliedFilter(
			buildFilter( empty, scope, moduleFields, currentLanguage() )
		);
		setItems( [] );
		setTotal( 0 );
		setOtherScope( null );
		setPage( 1 );
		setResetKey( ( k ) => k + 1 );
	}, [ scope, moduleFields ] );

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

	// The onboarding record reaches the bulk-edit panel and the undo panel, which
	// sit far apart in the tree and have to agree: acknowledging in one stands the
	// other down. Falls back to acknowledged while the fetch is in flight or after
	// it fails — a gate that appears because a request was slow is worse than none.
	const onboardingValue = {
		backup_ack: onboarding ? onboarding.backup_ack : true,
		backup_ack_by: onboarding ? onboarding.backup_ack_by : '',
		backup_ack_at: onboarding ? onboarding.backup_ack_at : '',
		backup_ack_version: onboarding ? onboarding.backup_ack_version : '',
		retention_days: onboarding ? onboarding.retention_days : 30,
		onAcknowledge: () =>
			setOnboarding( ( o ) => ( { ...o, backup_ack: true } ) ),
	};

	return (
		<OnboardingContext.Provider value={ onboardingValue }>
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
					<LanguageIndicator />
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
										scope === 'variation'
											? ' is-active'
											: ''
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
											label={ __(
												'Category',
												'catalogops'
											) }
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
											label={ __(
												'Brand',
												'catalogops'
											) }
											options={ brands }
											value={ form.brand }
											onChange={ ( ids ) =>
												setForm( {
													...form,
													brand: ids,
												} )
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
											options={ [
												{
													id: NO_TAG,
													name: __(
														'Without tag',
														'catalogops'
													),
												},
												...tags,
											] }
											value={ form.tag }
											onChange={ ( ids ) =>
												setForm( {
													...form,
													tag: reconcileTagSelection(
														form.tag,
														ids
													),
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
								</div>

								{ /* Stock and price are the two conditions about an
							     item's own numbers, so they sit together and last —
							     after the terms that say *which* items, and after
							     the attribute pair that only exists for variations. */ }
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
													value={
														form.attributeValues
													}
													onChange={ ( ids ) =>
														setForm( {
															...form,
															attributeValues:
																ids,
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
										</select>
									</div>

									<div className="catalogops-field catalogops-field--price">
										<label htmlFor="catalogops-price-min">
											{ __(
												'Price range',
												'catalogops'
											) }
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
												onChange={ update(
													'priceMin'
												) }
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
												onChange={ update(
													'priceMax'
												) }
											/>
										</div>
									</div>
								</div>

								{ /* The fields modules add, below the built-in
								     controls rather than mixed into them, and
								     under a heading of their own. Appending them
								     bare read as eight more built-in controls
								     that had simply been added badly — nothing
								     said where WooCommerce stopped and ACF began.
								     A field that means nothing in this scope is
								     not rendered, for the same reason the
								     attribute row is hidden under the product
								     scope: a control that cannot produce a
								     condition is a control that lies. */ }
								{ /* A module section is coming, so the filter holds
								     its place rather than reflowing when the
								     descriptors land. Only when the server said at
								     page load that a module is registered: a site
								     with none must never see a section appear and
								     be taken away again, which is the whole reason
								     `hasModules` is asked of the registry rather
								     than guessed from an empty list. Same
								     treatment the results table uses below, since
								     it is the same kind of wait. */ }
								{ ! moduleFieldsLoaded && MODULES_EXPECTED && (
									<div className="catalogops-module-group">
										<p className="catalogops-loading">
											{ __( 'Loading…', 'catalogops' ) }
										</p>
									</div>
								) }

								{ groupModuleFields( moduleFields, scope ).map(
									( group ) => (
										<ModuleGroup
											key={ group.module }
											group={ group }
											scope={ scope }
											form={ form }
											setForm={ setForm }
										/>
									)
								) }

								<div className="catalogops-filter-row">
									<button
										className="button button-primary"
										onClick={ () => run( 1 ) }
										disabled={ loading }
									>
										{ scope === 'variation'
											? __(
													'Show variations',
													'catalogops'
											  )
											: __(
													'Show products',
													'catalogops'
											  ) }
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
									__(
										'%d matching variations',
										'catalogops'
									),
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
								onKeyDown={ ( e ) =>
									e.key === 'Enter' && run( 1 )
								}
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

					{ /* On a catalogue of thousands the table shows ten and the pager
				     says "of 1859", which nobody is going to walk. Naming the
				     window makes the table honest, and points at the control that
				     answers a question about one product. */ }
					{ ! loading && items.length > 0 && total > items.length && (
						<p className="catalogops-table-caption">
							{ sprintf(
								/* translators: 1: first row shown, 2: last row shown, 3: total matches. */
								__(
									'Showing %1$d–%2$d of %3$d. Search by SKU to check a particular one.',
									'catalogops'
								),
								( page - 1 ) * PER_PAGE + 1,
								( page - 1 ) * PER_PAGE + items.length,
								total
							) }
						</p>
					) }

					<table
						className={ `wp-list-table widefat fixed striped${
							loading ? ' catalogops-loading-dim' : ''
						}` }
					>
						<thead>
							<tr>
								{ /* SKU leads, as it does in the preview and the audit
							     log: it is how a product is named out loud. The id
							     is addressing, not information. Category, brand and
							     tags earn their columns by being filterable —
							     filtering on something the results do not show is a
							     guess — and they run in the order the filter's own
							     controls do. */ }
								<th>{ __( 'SKU', 'catalogops' ) }</th>
								<th>{ __( 'Name', 'catalogops' ) }</th>
								<th>{ __( 'Categories', 'catalogops' ) }</th>
								<th>{ __( 'Brand', 'catalogops' ) }</th>
								<th>{ __( 'Tags', 'catalogops' ) }</th>
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
									<td colSpan="10">
										{ __(
											'No items match this filter.',
											'catalogops'
										) }
									</td>
								</tr>
							) : (
								items.map( ( item ) => (
									<tr key={ item.id }>
										<td>{ item.sku }</td>
										<td>{ item.name }</td>
										<td>
											{ item.categories &&
											item.categories.length > 0 ? (
												item.categories.join( ', ' )
											) : (
												<span className="catalogops-muted">
													—
												</span>
											) }
										</td>
										<td>
											{ item.brand || (
												<span className="catalogops-muted">
													—
												</span>
											) }
										</td>
										<td>
											{ item.tags &&
											item.tags.length > 0 ? (
												item.tags.join( ', ' )
											) : (
												<span className="catalogops-muted">
													—
												</span>
											) }
										</td>
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

					<Pagination
						page={ page }
						pages={ pages }
						busy={ loading }
						onPage={ run }
					/>
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
					retentionDays={
						onboarding ? onboarding.retention_days : 30
					}
				/>

				<Schedules
					refreshKey={ schedulesKey }
					onRan={ refreshAll }
					onFiringSoon={ setFiringSoon }
				/>

				<History
					refreshKey={ historyKey }
					onChanged={ refreshAll }
					firingSoon={ firingSoon }
				/>

				<RetentionSetting />
			</div>
		</OnboardingContext.Provider>
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
