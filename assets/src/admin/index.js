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
import { FormTokenField } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import './style.css';

const PER_PAGE = 10;

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
	{ key: 'weight', label: __( 'Weight', 'catalogops' ) },
];

/**
 * The numeric fields a formula or percentage change can target, mapped to the
 * formula variable each reads (the backend whitelist: stock_quantity is `stock`).
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
	{
		key: 'stock_quantity',
		variable: 'stock',
		label: __( 'Stock quantity', 'catalogops' ),
	},
	{ key: 'weight', variable: 'weight', label: __( 'Weight', 'catalogops' ) },
];

/**
 * The formula variable a numeric field key reads under.
 *
 * @param {string} key A numeric field key (e.g. stock_quantity).
 * @return {string} The formula variable name (e.g. stock).
 */
const fieldVariable = ( key ) =>
	( NUMERIC_FIELDS.find( ( f ) => f.key === key ) || {} ).variable || key;

/** A short, human hint of what a formula may reference. */
const FORMULA_HELP = __(
	'Variables: regular_price, sale_price, stock, weight, cost. Functions: round, ceil, floor, roundto, min, max, abs. Empty fields are skipped, never set to 0.',
	'catalogops'
);

/** The recurrence presets a schedule can use (mirrors the Recurrence enum). */
const RECURRENCES = [
	{ value: 'once', label: __( 'Once', 'catalogops' ) },
	{ value: 'hourly', label: __( 'Hourly', 'catalogops' ) },
	{ value: 'daily', label: __( 'Daily', 'catalogops' ) },
	{ value: 'weekly', label: __( 'Weekly', 'catalogops' ) },
	{ value: 'monthly', label: __( 'Monthly', 'catalogops' ) },
];

/**
 * Build the percentage-change factor as a clean decimal string, so a "-10%"
 * becomes the formula `<field> * 0.9` with no floating-point noise in the text.
 *
 * @param {number|string} percent The percentage delta (e.g. -10 or 15).
 * @return {string} The multiplier as a trimmed decimal string.
 */
function percentFactor( percent ) {
	const factor = 1 + Number( percent ) / 100;
	return String( Number( factor.toFixed( 6 ) ) );
}

const isTerminal = ( op ) => op && TERMINAL_STATUSES.includes( op.status );

/**
 * A WordPress token-field multiselect bound to an array of ids.
 *
 * {@link FormTokenField} works in label strings; this wraps it so the caller
 * keeps working with ids (what the filter sends). Unknown tokens are dropped, so
 * only real options end up selected.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.label    Field label.
 * @param {Array}    props.options  Selectable options as { id, name }.
 * @param {string[]} props.value    Currently-selected ids.
 * @param {Function} props.onChange Called with the new array of id strings.
 */
function TokenSelect( { label, options, value, onChange } ) {
	const nameById = {};
	const idByName = {};
	options.forEach( ( o ) => {
		nameById[ o.id ] = o.name;
		idByName[ o.name ] = String( o.id );
	} );

	return (
		<FormTokenField
			label={ label }
			value={ value.map( ( id ) => nameById[ id ] ).filter( Boolean ) }
			suggestions={ options.map( ( o ) => o.name ) }
			onChange={ ( tokens ) =>
				onChange(
					tokens.map( ( name ) => idByName[ name ] ).filter( Boolean )
				)
			}
			__experimentalExpandOnFocus
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
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
	if ( form.category.length ) {
		conditions.push( {
			field: 'category',
			operator: 'in',
			value: form.category.map( Number ),
		} );
	}
	if ( form.brand.length && brandField ) {
		conditions.push( {
			field: brandField,
			operator: 'in',
			value: form.brand,
		} );
	}
	if ( form.attribute ) {
		// A value picked → match those attribute terms; none picked → match any
		// object that has this attribute at all. Values are term ids.
		if ( form.attributeValues.length ) {
			conditions.push( {
				field: form.attribute,
				operator: 'in',
				value: form.attributeValues.map( Number ),
			} );
		} else {
			conditions.push( { field: form.attribute, operator: 'exists' } );
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
 * A labelled progress bar for an in-flight or finished operation.
 *
 * @param {Object} props    Component props.
 * @param {Object} props.op The operation to render.
 */
function ProgressBar( { op } ) {
	return (
		<div className={ `catalogops-progress is-${ op.status }` }>
			<p>
				{ sprintf(
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
					style={ { width: `${ op.percent }%` } }
				/>
			</div>
		</div>
	);
}

/**
 * The bulk-edit panel: pick a field and value, preview the change over the
 * current filter, then apply it and watch progress.
 *
 * @param {Object}   props                   Component props.
 * @param {Object}   props.filter            The current filter payload.
 * @param {Function} props.onDone            Called when an operation finishes (to refresh).
 * @param {Function} props.onScheduleCreated Called after a schedule is created.
 */
function BulkEdit( { filter, onDone, onScheduleCreated } ) {
	const [ mode, setMode ] = useState( 'set' );
	const [ field, setField ] = useState( 'regular_price' );
	const [ value, setValue ] = useState( '' );
	const [ expression, setExpression ] = useState( '' );
	const [ percent, setPercent ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ operation, setOperation ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	// Scheduling: the same filter + action, deferred and possibly recurring.
	const [ showSchedule, setShowSchedule ] = useState( false );
	const [ name, setName ] = useState( '' );
	const [ recurrence, setRecurrence ] = useState( 'once' );
	const [ startsAt, setStartsAt ] = useState( '' );
	const [ notifyEmail, setNotifyEmail ] = useState( '' );
	const [ scheduleMsg, setScheduleMsg ] = useState( '' );

	// Percentage change is expressed as a formula, so it flows through the exact
	// same action path (and preview/skip semantics) as a typed formula.
	const percentExpression =
		percent === '' || Number.isNaN( Number( percent ) )
			? ''
			: `${ fieldVariable( field ) } * ${ percentFactor( percent ) }`;

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
		apiFetch( {
			path: '/catalogops/v1/operations/preview',
			method: 'POST',
			data: { filter, actions: buildActions() },
		} )
			.then( setPreview )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const runApply = () => {
		const message = __(
			'Apply this change to all matching items? Take a backup first.',
			'catalogops'
		);
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			return;
		}
		setBusy( true );
		setError( '' );
		apiFetch( {
			path: '/catalogops/v1/operations',
			method: 'POST',
			data: { filter, actions: buildActions() },
		} )
			.then( setOperation )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
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

	return (
		<div className="catalogops-bulk-edit">
			<h2>{ __( 'Bulk edit', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Change a field for every item in the filter above. Preview shows old → new; nothing is written until you Apply. You can also schedule it to run later or on a recurring basis.',
					'catalogops'
				) }
			</p>

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
					}` }
					onClick={ () => changeMode( 'percent' ) }
				>
					{ __( 'Percent', 'catalogops' ) }
				</button>
				<button
					type="button"
					className={ `catalogops-segmented__btn${
						mode === 'formula' ? ' is-active' : ''
					}` }
					onClick={ () => changeMode( 'formula' ) }
				>
					{ __( 'Formula', 'catalogops' ) }
				</button>
			</div>

			<div className="catalogops-bulk-controls">
				<label htmlFor="catalogops-field">
					{ __( 'Field', 'catalogops' ) }
				</label>
				<select
					id="catalogops-field"
					value={ field }
					onChange={ ( e ) => setField( e.target.value ) }
				>
					{ fieldOptions.map( ( f ) => (
						<option key={ f.key } value={ f.key }>
							{ f.label }
						</option>
					) ) }
				</select>

				{ mode === 'set' && (
					<>
						<label htmlFor="catalogops-value">
							{ __( 'to', 'catalogops' ) }
						</label>
						{ field === 'stock_status' ? (
							<select
								id="catalogops-value"
								value={ value }
								onChange={ ( e ) => setValue( e.target.value ) }
							>
								<option value="">
									{ __( 'Choose…', 'catalogops' ) }
								</option>
								<option value="instock">
									{ __( 'In stock', 'catalogops' ) }
								</option>
								<option value="outofstock">
									{ __( 'Out of stock', 'catalogops' ) }
								</option>
								<option value="onbackorder">
									{ __( 'On backorder', 'catalogops' ) }
								</option>
							</select>
						) : (
							<input
								id="catalogops-value"
								type="text"
								value={ value }
								onChange={ ( e ) => setValue( e.target.value ) }
							/>
						) }
					</>
				) }

				{ mode === 'percent' && (
					<>
						<label htmlFor="catalogops-percent">
							{ __( 'by', 'catalogops' ) }
						</label>
						<input
							id="catalogops-percent"
							className="small-text"
							type="number"
							step="any"
							value={ percent }
							onChange={ ( e ) => setPercent( e.target.value ) }
						/>
						<span>{ __( '%', 'catalogops' ) }</span>
					</>
				) }

				{ mode === 'formula' && (
					<>
						<label htmlFor="catalogops-expression">
							{ __( '=', 'catalogops' ) }
						</label>
						<input
							id="catalogops-expression"
							className="catalogops-formula-input"
							type="text"
							placeholder="roundto( cost * 1.35, 0.99 )"
							value={ expression }
							onChange={ ( e ) =>
								setExpression( e.target.value )
							}
						/>
					</>
				) }

				<button
					className="button"
					onClick={ runPreview }
					disabled={ busy || running || ! ready }
				>
					{ __( 'Preview', 'catalogops' ) }
				</button>
				<button
					className="button button-primary"
					onClick={ runApply }
					disabled={ busy || running || ! ready }
				>
					{ __( 'Apply', 'catalogops' ) }
				</button>
			</div>

			{ ( mode === 'formula' || mode === 'percent' ) && (
				<p className="description catalogops-formula-help">
					{ mode === 'percent' && percentExpression
						? sprintf(
								/* translators: %s: the generated formula. */
								__( 'Applies: %s', 'catalogops' ),
								percentExpression
						  ) + '. '
						: '' }
					{ FORMULA_HELP }
				</p>
			) }

			<p>
				<button
					type="button"
					className="button-link"
					onClick={ () => setShowSchedule( ! showSchedule ) }
				>
					{ showSchedule
						? __( 'Hide scheduling', 'catalogops' )
						: __( 'Schedule instead…', 'catalogops' ) }
				</button>
			</p>

			{ showSchedule && (
				<div className="catalogops-schedule-form">
					<div className="catalogops-bulk-controls">
						<label htmlFor="catalogops-sched-name">
							{ __( 'Name', 'catalogops' ) }
						</label>
						<input
							id="catalogops-sched-name"
							type="text"
							value={ name }
							onChange={ ( e ) => setName( e.target.value ) }
						/>

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
								<option key={ r.value } value={ r.value }>
									{ r.label }
								</option>
							) ) }
						</select>

						<label htmlFor="catalogops-sched-start">
							{ __( 'Start', 'catalogops' ) }
						</label>
						<input
							id="catalogops-sched-start"
							type="datetime-local"
							value={ startsAt }
							onChange={ ( e ) => setStartsAt( e.target.value ) }
						/>

						<label htmlFor="catalogops-sched-email">
							{ __( 'Email report to', 'catalogops' ) }
						</label>
						<input
							id="catalogops-sched-email"
							type="email"
							placeholder={ __( 'site admin', 'catalogops' ) }
							value={ notifyEmail }
							onChange={ ( e ) =>
								setNotifyEmail( e.target.value )
							}
						/>

						<button
							className="button"
							onClick={ createSchedule }
							disabled={ busy || ! ready }
						>
							{ __( 'Create schedule', 'catalogops' ) }
						</button>
					</div>
					<p className="description">
						{ __(
							'The filter is re-evaluated each time it runs, so a recurring schedule always acts on whatever matches then. Leave Start empty to run at the next opportunity.',
							'catalogops'
						) }
					</p>
					{ scheduleMsg && (
						<p className="catalogops-saved">{ scheduleMsg }</p>
					) }
				</div>
			) }

			{ error && (
				<div className="notice notice-error">
					<p>{ error }</p>
				</div>
			) }

			{ preview && ! operation && (
				<div className="catalogops-preview">
					<p>
						{ sprintf(
							/* translators: %d: number of items that would change. */
							__(
								'%d items would change. Sample:',
								'catalogops'
							),
							preview.target_count
						) }
					</p>
					<table className="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th>{ __( 'Product', 'catalogops' ) }</th>
								<th>{ __( 'Field', 'catalogops' ) }</th>
								<th>{ __( 'Old', 'catalogops' ) }</th>
								<th>{ __( 'New', 'catalogops' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ preview.sample.flatMap( ( row ) =>
								row.changes.map( ( c, i ) => (
									<tr key={ `${ row.id }-${ i }` }>
										<td>{ row.name }</td>
										<td>{ c.field }</td>
										<td>{ c.old }</td>
										<td>{ c.new }</td>
									</tr>
								) )
							) }
						</tbody>
					</table>
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

	const hasMore = data.items.length === CHANGES_PER_PAGE;

	return (
		<div>
			<div className="catalogops-search">
				<label htmlFor={ `changes-search-${ id }` }>
					{ __( 'Find by SKU', 'catalogops' ) }
				</label>
				<input
					id={ `changes-search-${ id }` }
					type="search"
					placeholder={ __( 'e.g. COPS-1234', 'catalogops' ) }
					value={ draft }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onKeyDown={ ( e ) => e.key === 'Enter' && applySearch() }
				/>
				<button
					className="button"
					onClick={ applySearch }
					disabled={ loading }
				>
					{ __( 'Search', 'catalogops' ) }
				</button>
			</div>

			{ loading && (
				<p className="catalogops-loading">
					{ __( 'Loading…', 'catalogops' ) }
				</p>
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
						</tr>
					</thead>
					<tbody>
						{ data.items.length === 0 ? (
							<tr className="catalogops-empty">
								<td colSpan="6">
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
						/* translators: %d: page number. */
						__( 'Page %d', 'catalogops' ),
						page
					) }
				</span>
				<button
					className="button"
					disabled={ ! hasMore || loading }
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
 * One row of the operation history, expandable to its audit detail or undo flow.
 *
 * @param {Object}   props           Component props.
 * @param {Object}   props.op        The operation.
 * @param {Function} props.onChanged Called when an undo from this row finishes.
 */
function OperationRow( { op, onChanged } ) {
	const [ open, setOpen ] = useState( null ); // 'changes' | 'undo' | null

	const toggle = ( which ) =>
		setOpen( ( cur ) => ( cur === which ? null : which ) );

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
								/* translators: %d: failed count. */
								__( '(%d failed)', 'catalogops' ),
								op.failed
							) }
				</td>
				<td>{ op.user_name || '—' }</td>
				<td>{ op.created_at }</td>
				<td>
					<div className="catalogops-actions">
						<button
							className="button button-small"
							onClick={ () => toggle( 'changes' ) }
						>
							{ __( 'Changes', 'catalogops' ) }
						</button>
						{ op.can_undo && (
							<button
								className="button button-small"
								onClick={ () => toggle( 'undo' ) }
							>
								{ __( 'Undo', 'catalogops' ) }
							</button>
						) }
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

	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/operations' } )
			.then( ( res ) => setItems( res.items ) )
			.catch( ( err ) => setError( err.message ) );
	}, [ refreshKey ] );

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
						<th>{ __( 'Actions', 'catalogops' ) }</th>
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
function Schedules( { refreshKey, onRan } ) {
	const [ items, setItems ] = useState( [] );
	const [ error, setError ] = useState( '' );
	const [ localKey, setLocalKey ] = useState( 0 );

	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/schedules' } )
			.then( ( res ) => setItems( res.items ) )
			.catch( ( err ) => setError( err.message ) );
	}, [ refreshKey, localKey ] );

	const reload = () => setLocalKey( ( k ) => k + 1 );

	const act = ( id, verb ) => {
		setError( '' );
		apiFetch( {
			path: `/catalogops/v1/schedules/${ id }/${ verb }`,
			method: 'POST',
		} )
			.then( () => {
				reload();
				if ( verb === 'run' && onRan ) {
					onRan();
				}
			} )
			.catch( ( err ) => setError( err.message ) );
	};

	const remove = ( id ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Delete this schedule?', 'catalogops' ) ) ) {
			return;
		}
		setError( '' );
		apiFetch( {
			path: `/catalogops/v1/schedules/${ id }`,
			method: 'DELETE',
		} )
			.then( reload )
			.catch( ( err ) => setError( err.message ) );
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

			<table className="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>{ __( 'Name', 'catalogops' ) }</th>
						<th>{ __( 'Repeat', 'catalogops' ) }</th>
						<th>{ __( 'Status', 'catalogops' ) }</th>
						<th>{ __( 'Next run', 'catalogops' ) }</th>
						<th>{ __( 'Last run', 'catalogops' ) }</th>
						<th>{ __( 'Actions', 'catalogops' ) }</th>
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
							<tr key={ s.id }>
								<td>{ s.name || `#${ s.id }` }</td>
								<td>{ s.recurrence }</td>
								<td>
									<span
										className={ `catalogops-badge catalogops-status-badge is-${ s.status }` }
									>
										{ s.status }
									</span>
								</td>
								<td>
									{ s.status === 'completed'
										? '—'
										: s.next_run }
								</td>
								<td>{ s.last_run || '—' }</td>
								<td>
									<div className="catalogops-actions">
										<button
											className="button button-small"
											onClick={ () => act( s.id, 'run' ) }
											disabled={
												s.status === 'completed'
											}
										>
											{ __( 'Run now', 'catalogops' ) }
										</button>
										{ s.status === 'active' ? (
											<button
												className="button button-small"
												onClick={ () =>
													act( s.id, 'pause' )
												}
											>
												{ __( 'Pause', 'catalogops' ) }
											</button>
										) : (
											s.status === 'paused' && (
												<button
													className="button button-small"
													onClick={ () =>
														act( s.id, 'resume' )
													}
												>
													{ __(
														'Resume',
														'catalogops'
													) }
												</button>
											)
										) }
										<button
											className="button button-small button-link-delete"
											onClick={ () => remove( s.id ) }
										>
											{ __( 'Delete', 'catalogops' ) }
										</button>
									</div>
								</td>
							</tr>
						) )
					) }
				</tbody>
			</table>
		</div>
	);
}

function App() {
	const [ form, setForm ] = useState( {
		priceMin: '',
		priceMax: '',
		stockStatus: '',
		sku: '',
		category: [],
		brand: [],
		attribute: '',
		attributeValues: [],
	} );
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ historyKey, setHistoryKey ] = useState( 0 );
	// Discovery data for the category and brand dropdowns. brandField is the
	// filter field a brand maps to (catalog-specific; supplied by the API).
	const [ categories, setCategories ] = useState( [] );
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
		buildFilter(
			{
				priceMin: '',
				priceMax: '',
				stockStatus: '',
				sku: '',
				category: [],
				brand: [],
				attribute: '',
				attributeValues: [],
			},
			'product',
			''
		)
	);

	// Load the category and brand dropdowns once.
	useEffect( () => {
		apiFetch( { path: '/catalogops/v1/fields/categories' } )
			.then( ( res ) => setCategories( res.categories || [] ) )
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

	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const update = ( key ) => ( event ) =>
		setForm( { ...form, [ key ]: event.target.value } );

	return (
		<div className="catalogops">
			<h1 className="wp-heading-inline">
				{ __( 'CatalogOps', 'catalogops' ) }
			</h1>

			<div className="catalogops-card catalogops-browse">
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
						<div className="catalogops-filters">
							<label htmlFor="catalogops-price-min">
								{ __( 'Min', 'catalogops' ) }
							</label>
							<input
								id="catalogops-price-min"
								className="small-text"
								type="number"
								value={ form.priceMin }
								onChange={ update( 'priceMin' ) }
							/>

							<label htmlFor="catalogops-price-max">
								{ __( 'Max', 'catalogops' ) }
							</label>
							<input
								id="catalogops-price-max"
								className="small-text"
								type="number"
								value={ form.priceMax }
								onChange={ update( 'priceMax' ) }
							/>

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
									{ __( 'Out of stock', 'catalogops' ) }
								</option>
							</select>

							<div className="catalogops-token">
								<TokenSelect
									label={ __( 'Category', 'catalogops' ) }
									options={ categories }
									value={ form.category }
									onChange={ ( ids ) =>
										setForm( {
											...form,
											category: ids,
										} )
									}
								/>
							</div>

							<div className="catalogops-token">
								<TokenSelect
									label={ __( 'Brand', 'catalogops' ) }
									options={ brands.map( ( b ) => ( {
										id: b,
										name: b,
									} ) ) }
									value={ form.brand }
									onChange={ ( ids ) =>
										setForm( { ...form, brand: ids } )
									}
								/>
							</div>

							{ attributes.length > 0 && (
								<>
									<label htmlFor="catalogops-attribute">
										{ __( 'Attribute', 'catalogops' ) }
									</label>
									<select
										id="catalogops-attribute"
										value={ form.attribute }
										onChange={ ( e ) =>
											setForm( {
												...form,
												attribute: e.target.value,
												attributeValues: [],
											} )
										}
									>
										<option value="">
											{ __( 'Any', 'catalogops' ) }
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

									{ selectedAttribute && (
										<div className="catalogops-token">
											<TokenSelect
												label={ __(
													'Values (any if empty)',
													'catalogops'
												) }
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
											/>
										</div>
									) }
								</>
							) }

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
					<div className="catalogops-control-group">
						<span className="catalogops-group-label">
							{ __( 'Find', 'catalogops' ) }
						</span>
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
				</div>

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

				{ error && (
					<div className="notice notice-error">
						<p>{ error }</p>
					</div>
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
								{ __( 'Price', 'catalogops' ) }
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
								<td colSpan="6">
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
										{ item.price }
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
				onDone={ refreshAll }
				onScheduleCreated={ () => setSchedulesKey( ( k ) => k + 1 ) }
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
