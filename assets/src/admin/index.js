/**
 * CatalogOps admin app.
 *
 * M1: a read-only filter + product table.
 * M2: a bulk-edit panel that previews a change, applies it as an operation, and
 * polls its progress.
 * M3: operation history with undo (drift preview + conflict policy), an audit
 * detail view of a run's recorded changes, and the retention-window setting.
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

/** Fields the bulk editor can set. `meta` reveals a key input. */
const EDITABLE_FIELDS = [
	{ key: 'regular_price', label: __( 'Regular price', 'catalogops' ) },
	{ key: 'sale_price', label: __( 'Sale price', 'catalogops' ) },
	{ key: 'stock_quantity', label: __( 'Stock quantity', 'catalogops' ) },
	{ key: 'stock_status', label: __( 'Stock status', 'catalogops' ) },
	{ key: 'meta', label: __( 'Custom field (meta)', 'catalogops' ) },
];

const isTerminal = ( op ) => op && TERMINAL_STATUSES.includes( op.status );

/**
 * Build the filter payload from the form state and target scope.
 *
 * @param {Object} form  Form values.
 * @param {string} scope 'product' or 'variation'.
 * @return {Object} Filter in the API's shape (scope included, so the same filter
 * drives the query, the preview, and the operation).
 */
function buildFilter( form, scope ) {
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
 * @param {Object}   props        Component props.
 * @param {Object}   props.filter The current filter payload.
 * @param {Function} props.onDone Called when an operation finishes (to refresh).
 */
function BulkEdit( { filter, onDone } ) {
	const [ field, setField ] = useState( 'regular_price' );
	const [ metaKey, setMetaKey ] = useState( '' );
	const [ value, setValue ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ operation, setOperation ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	const fieldKey = field === 'meta' ? `meta:${ metaKey }` : field;
	const buildActions = () => [ { type: 'set', field: fieldKey, value } ];

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

	const running = operation && ! isTerminal( operation );

	return (
		<div className="catalogops-bulk-edit">
			<h2>{ __( 'Bulk edit', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Applies to every item matching the filter above (products or variations).',
					'catalogops'
				) }
			</p>

			<div className="catalogops-bulk-controls">
				<label htmlFor="catalogops-field">
					{ __( 'Set field', 'catalogops' ) }
				</label>
				<select
					id="catalogops-field"
					value={ field }
					onChange={ ( e ) => setField( e.target.value ) }
				>
					{ EDITABLE_FIELDS.map( ( f ) => (
						<option key={ f.key } value={ f.key }>
							{ f.label }
						</option>
					) ) }
				</select>

				{ field === 'meta' && (
					<input
						type="text"
						placeholder={ __(
							'meta key, e.g. _catalogops_brand',
							'catalogops'
						) }
						value={ metaKey }
						onChange={ ( e ) => setMetaKey( e.target.value ) }
					/>
				) }

				<label htmlFor="catalogops-value">
					{ __( 'to', 'catalogops' ) }
				</label>
				<input
					id="catalogops-value"
					type="text"
					value={ value }
					onChange={ ( e ) => setValue( e.target.value ) }
				/>

				<button
					className="button"
					onClick={ runPreview }
					disabled={ busy || running }
				>
					{ __( 'Preview', 'catalogops' ) }
				</button>
				<button
					className="button button-primary"
					onClick={ runApply }
					disabled={ busy || running || value === '' }
				>
					{ __( 'Apply', 'catalogops' ) }
				</button>
			</div>

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
	const [ page, setPage ] = useState( 1 );
	// Draft is the input value; sku is the applied search (on Enter/Search).
	const [ draft, setDraft ] = useState( '' );
	const [ sku, setSku ] = useState( '' );

	useEffect( () => {
		const query = `page=${ page }&per_page=${ CHANGES_PER_PAGE }${
			sku ? `&sku=${ encodeURIComponent( sku ) }` : ''
		}`;
		apiFetch( {
			path: `/catalogops/v1/operations/${ id }/changes?${ query }`,
		} )
			.then( setData )
			.catch( ( err ) => setError( err.message ) );
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
		return <p>{ __( 'Loading…', 'catalogops' ) }</p>;
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
				<button className="button" onClick={ applySearch }>
					{ __( 'Search', 'catalogops' ) }
				</button>
				{ sku !== '' && (
					<button
						className="button-link"
						onClick={ () => {
							setDraft( '' );
							setSku( '' );
							setPage( 1 );
						} }
					>
						{ __( 'Clear', 'catalogops' ) }
					</button>
				) }
			</div>

			<div className="catalogops-table-scroll">
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
					disabled={ page <= 1 }
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
					disabled={ ! hasMore }
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

function App() {
	const [ form, setForm ] = useState( {
		priceMin: '',
		priceMax: '',
		stockStatus: '',
		sku: '',
	} );
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ historyKey, setHistoryKey ] = useState( 0 );
	// Whether the filter, table, and bulk edit target parent products or their
	// variations (CONTEXT §4).
	const [ scope, setScope ] = useState( 'product' );
	// The filter that was actually applied to the table (frozen on Apply), so
	// bulk edits target what the user is looking at.
	const [ appliedFilter, setAppliedFilter ] = useState( () =>
		buildFilter(
			{ priceMin: '', priceMax: '', stockStatus: '', sku: '' },
			'product'
		)
	);

	const run = useCallback(
		( toPage ) => {
			const filter = buildFilter( form, scope );
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
		[ form, scope ]
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
				<div className="catalogops-scope">
					<span className="catalogops-scope__label">
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

				<div className="catalogops-filters">
					<label htmlFor="catalogops-price-min">
						{ __( 'Min price', 'catalogops' ) }
					</label>
					<input
						id="catalogops-price-min"
						type="number"
						value={ form.priceMin }
						onChange={ update( 'priceMin' ) }
					/>

					<label htmlFor="catalogops-price-max">
						{ __( 'Max price', 'catalogops' ) }
					</label>
					<input
						id="catalogops-price-max"
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
						<option value="">{ __( 'Any', 'catalogops' ) }</option>
						<option value="instock">
							{ __( 'In stock', 'catalogops' ) }
						</option>
						<option value="outofstock">
							{ __( 'Out of stock', 'catalogops' ) }
						</option>
					</select>

					<button
						className="button button-primary"
						onClick={ () => run( 1 ) }
						disabled={ loading }
					>
						{ __( 'Apply', 'catalogops' ) }
					</button>
				</div>

				<div className="catalogops-search">
					<label htmlFor="catalogops-sku">
						{ __( 'Find by SKU', 'catalogops' ) }
					</label>
					<input
						id="catalogops-sku"
						type="search"
						placeholder={ __( 'e.g. COPS-1234', 'catalogops' ) }
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

				<table className="wp-list-table widefat fixed striped">
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

			<BulkEdit filter={ appliedFilter } onDone={ refreshAll } />

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
