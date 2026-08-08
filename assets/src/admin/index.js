/**
 * CatalogOps admin app.
 *
 * M1: a read-only filter + product table.
 * M2: a bulk-edit panel that previews a change, applies it as an operation, and
 * polls its progress. Deliberately rough — functionality first (see project
 * notes); visual polish comes later.
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

const PER_PAGE = 25;

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

/**
 * Build the filter payload from the form state.
 *
 * @param {Object} form Form values.
 * @return {Object} Filter in the API's shape.
 */
function buildFilter( form ) {
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

	return { relation: 'AND', conditions };
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
	const pollRef = useRef( null );

	const fieldKey = field === 'meta' ? `meta:${ metaKey }` : field;
	const buildActions = () => [ { type: 'set', field: fieldKey, value } ];

	// Poll the operation until it reaches a terminal status.
	useEffect( () => {
		if ( ! operation || TERMINAL_STATUSES.includes( operation.status ) ) {
			if ( operation && TERMINAL_STATUSES.includes( operation.status ) ) {
				onDone();
			}
			return undefined;
		}

		pollRef.current = setTimeout( () => {
			apiFetch( { path: `/catalogops/v1/operations/${ operation.id }` } )
				.then( setOperation )
				.catch( ( err ) => setError( err.message ) );
		}, 1500 );

		return () => clearTimeout( pollRef.current );
	}, [ operation, onDone ] );

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
			'Apply this change to all matching products? Take a backup first.',
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

	const running =
		operation && ! TERMINAL_STATUSES.includes( operation.status );

	return (
		<div className="catalogops-bulk-edit">
			<h2>{ __( 'Bulk edit', 'catalogops' ) }</h2>
			<p className="description">
				{ __(
					'Applies to every product matching the filter above.',
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
							/* translators: %d: number of products that would change. */
							__(
								'%d products would change. Sample:',
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

			{ operation && (
				<div className="catalogops-progress">
					<p>
						{ sprintf(
							/* translators: 1: status, 2: processed, 3: target. */
							__( 'Operation %1$s — %2$d / %3$d', 'catalogops' ),
							operation.status,
							operation.processed,
							operation.target_count
						) }
						{ operation.failed > 0 &&
							' ' +
								sprintf(
									/* translators: %d: number of failed objects. */
									__( '(%d failed)', 'catalogops' ),
									operation.failed
								) }
					</p>
					<div
						style={ {
							background: '#e0e0e0',
							borderRadius: '3px',
							overflow: 'hidden',
							height: '20px',
						} }
					>
						<div
							style={ {
								width: `${ operation.percent }%`,
								background: '#2271b1',
								height: '100%',
								transition: 'width .3s',
							} }
						/>
					</div>
				</div>
			) }
		</div>
	);
}

function App() {
	const [ form, setForm ] = useState( {
		priceMin: '',
		priceMax: '',
		stockStatus: '',
	} );
	const [ items, setItems ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	// The filter that was actually applied to the table (frozen on Apply), so
	// bulk edits target what the user is looking at.
	const [ appliedFilter, setAppliedFilter ] = useState( () =>
		buildFilter( { priceMin: '', priceMax: '', stockStatus: '' } )
	);

	const run = useCallback(
		( toPage ) => {
			const filter = buildFilter( form );
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
		[ form ]
	);

	useEffect( () => {
		run( 1 );
		// Load the whole catalog once on mount.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const update = ( key ) => ( event ) =>
		setForm( { ...form, [ key ]: event.target.value } );

	return (
		<div className="catalogops">
			<h1 className="wp-heading-inline">
				{ __( 'CatalogOps', 'catalogops' ) }
			</h1>

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

			<p className="catalogops-status">
				{ loading
					? __( 'Loading…', 'catalogops' )
					: sprintf(
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
						<th>{ __( 'Price', 'catalogops' ) }</th>
						<th>{ __( 'Stock', 'catalogops' ) }</th>
						<th>{ __( 'Qty', 'catalogops' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ items.length === 0 && ! loading ? (
						<tr>
							<td colSpan="6">
								{ __(
									'No products match this filter.',
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
								<td>{ item.price }</td>
								<td>{ item.stock_status }</td>
								<td>{ item.stock_quantity }</td>
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

			<hr />

			<BulkEdit filter={ appliedFilter } onDone={ () => run( page ) } />
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
