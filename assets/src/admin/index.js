/**
 * CatalogOps admin app: a read-only filter + product table (M1).
 */
import {
	createRoot,
	render,
	useState,
	useCallback,
	useEffect,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const PER_PAGE = 25;

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

	const run = useCallback(
		( toPage ) => {
			setLoading( true );
			setError( '' );
			apiFetch( {
				path: '/catalogops/v1/products/query',
				method: 'POST',
				data: {
					filter: buildFilter( form ),
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
