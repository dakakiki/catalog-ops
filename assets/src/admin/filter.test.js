/**
 * The first JavaScript tests in this repository.
 *
 * `buildFilter()` is the single point where the results table, the preview, the
 * bulk edit and a saved schedule agree on which products they are all talking
 * about. If it is wrong, every one of them is wrong together and identically —
 * which is the failure mode nothing downstream can notice, because preview and
 * run agree perfectly on the wrong catalogue.
 *
 * So these are not coverage. Every case below is a mistake this filter has
 * actually made, or one the shape of the code invites next.
 */

import {
	buildFilter,
	emptyForm,
	NO_TAG,
	operatorFor,
	reconcileTagSelection,
} from './filter';

/**
 * A form with some fields filled in.
 *
 * @param {Object} overrides Fields to set.
 * @return {Object} The form.
 */
const form = ( overrides = {} ) => ( { ...emptyForm(), ...overrides } );

/**
 * The condition for a field, or undefined.
 *
 * @param {Object} filter The built filter.
 * @param {string} field  The field key.
 * @return {Object|undefined} The condition.
 */
const conditionFor = ( filter, field ) =>
	filter.conditions.find( ( c ) => c.field === field );

describe( 'buildFilter', () => {
	it( 'builds nothing from an empty form', () => {
		const filter = buildFilter( emptyForm(), 'product', '' );

		expect( filter ).toEqual( {
			relation: 'AND',
			scope: 'product',
			conditions: [],
		} );
	} );

	it( 'always ANDs, because an exclusion must narrow and never widen', () => {
		const filter = buildFilter(
			form( { priceMin: '10', category: [ 5 ], categoryMode: 'not_in' } ),
			'product',
			''
		);

		expect( filter.relation ).toBe( 'AND' );
	} );

	it( 'carries the scope, so one filter drives the query, the preview and the run', () => {
		expect( buildFilter( emptyForm(), 'variation', '' ).scope ).toBe(
			'variation'
		);
	} );

	describe( 'numbers', () => {
		it( 'sends prices as numbers, not as the strings the inputs hold', () => {
			const filter = buildFilter(
				form( { priceMin: '10', priceMax: '250' } ),
				'product',
				''
			);

			expect( filter.conditions ).toEqual( [
				{ field: 'price', operator: '>=', value: 10 },
				{ field: 'price', operator: '<=', value: 250 },
			] );
		} );

		it( 'keeps a zero, which is a real price and not an empty field', () => {
			const filter = buildFilter(
				form( { priceMin: '0' } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'price' ) ).toEqual( {
				field: 'price',
				operator: '>=',
				value: 0,
			} );
		} );
	} );

	describe( 'text', () => {
		it( 'trims a SKU, so a stray space does not become part of the search', () => {
			const filter = buildFilter(
				form( { sku: '  ABC ' } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'sku' ).value ).toBe( 'ABC' );
		} );

		it( 'drops a SKU that is only whitespace', () => {
			expect(
				buildFilter( form( { sku: '   ' } ), 'product', '' ).conditions
			).toHaveLength( 0 );
		} );
	} );

	describe( 'set-valued fields', () => {
		it( 'sends term ids as numbers', () => {
			const filter = buildFilter(
				form( { category: [ '5', '9' ] } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'category' ).value ).toEqual( [
				5, 9,
			] );
		} );

		it( 'maps the exclude mode to not_in', () => {
			const filter = buildFilter(
				form( { category: [ 5 ], categoryMode: 'not_in' } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'category' ).operator ).toBe(
				'not_in'
			);
		} );

		/**
		 * The rule that makes an empty selection safe. Excluding nothing excludes
		 * nobody, so an empty set is no condition — not a condition with an empty
		 * value, which the engine would have to interpret.
		 */
		it( 'omits an empty selection entirely, in either mode', () => {
			expect(
				buildFilter(
					form( { category: [], categoryMode: 'not_in' } ),
					'product',
					''
				).conditions
			).toHaveLength( 0 );
		} );

		/**
		 * The brand field's key comes from the API rather than being hardcoded,
		 * because a site can move brands to a different meta key. Without a key
		 * there is no condition to build — and building one against '' would be a
		 * condition the engine cannot answer.
		 */
		it( 'skips brands when the API gave no brand field', () => {
			expect(
				buildFilter( form( { brand: [ 'acme' ] } ), 'product', '' )
					.conditions
			).toHaveLength( 0 );
		} );

		it( 'sends brand values as given, since they are not term ids', () => {
			const filter = buildFilter(
				form( { brand: [ 'acme' ] } ),
				'product',
				'meta:_brand'
			);

			expect( conditionFor( filter, 'meta:_brand' ).value ).toEqual( [
				'acme',
			] );
		} );
	} );

	describe( 'the "Without tag" entry', () => {
		/**
		 * It is a string on purpose, so it cannot collide with a term id — those
		 * are numbers, and the real ones go through Number() while this never does.
		 * A generic control that mapped every selection uniformly would turn it
		 * into NaN.
		 */
		it( 'asks about the taxonomy rather than about terms, and carries no value', () => {
			const filter = buildFilter(
				form( { tag: [ NO_TAG ] } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'tag' ) ).toEqual( {
				field: 'tag',
				operator: 'not_exists',
			} );
		} );

		/**
		 * The mode still governs, and it reads the way the rest of the row does:
		 * the selection is what to KEEP, so excluding the untagged leaves exactly
		 * the products that do carry a tag.
		 */
		it( 'inverts to exists when the row is set to exclude', () => {
			const filter = buildFilter(
				form( { tag: [ NO_TAG ], tagMode: 'not_in' } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'tag' ).operator ).toBe( 'exists' );
		} );

		it( 'still sends real tags as numeric ids', () => {
			const filter = buildFilter(
				form( { tag: [ '3', '4' ] } ),
				'product',
				''
			);

			expect( conditionFor( filter, 'tag' ).value ).toEqual( [ 3, 4 ] );
		} );
	} );

	describe( 'attributes', () => {
		/**
		 * Attribute filtering targets variations, because a parent's price lives on
		 * its variations. The guard matters for a stale value left behind by a
		 * scope switch: without it, a filter would carry a condition for a scope it
		 * is no longer in.
		 */
		it( 'is ignored under the product scope', () => {
			expect(
				buildFilter(
					form( {
						attribute: 'attribute:pa_size',
						attributeValues: [ 7 ],
					} ),
					'product',
					''
				).conditions
			).toHaveLength( 0 );
		} );

		it( 'matches the chosen terms under the variation scope', () => {
			const filter = buildFilter(
				form( {
					attribute: 'attribute:pa_size',
					attributeValues: [ '7' ],
				} ),
				'variation',
				''
			);

			expect( conditionFor( filter, 'attribute:pa_size' ) ).toEqual( {
				field: 'attribute:pa_size',
				operator: 'in',
				value: [ 7 ],
			} );
		} );

		it( 'asks about the attribute itself when no value is chosen', () => {
			const filter = buildFilter(
				form( { attribute: 'attribute:pa_size' } ),
				'variation',
				''
			);

			expect( conditionFor( filter, 'attribute:pa_size' ) ).toEqual( {
				field: 'attribute:pa_size',
				operator: 'exists',
			} );
		} );

		it( 'inverts that to not_exists when the row excludes', () => {
			const filter = buildFilter(
				form( {
					attribute: 'attribute:pa_size',
					attributeMode: 'not_in',
				} ),
				'variation',
				''
			);

			expect( conditionFor( filter, 'attribute:pa_size' ).operator ).toBe(
				'not_exists'
			);
		} );
	} );
} );

describe( 'operatorFor', () => {
	/**
	 * Anything that is not an explicit exclusion reads as an inclusion, so a
	 * filter saved before modes existed keeps its original meaning rather than
	 * silently inverting.
	 */
	it( 'treats anything but not_in as an inclusion', () => {
		expect( operatorFor( 'not_in' ) ).toBe( 'not_in' );
		expect( operatorFor( 'in' ) ).toBe( 'in' );
		expect( operatorFor( undefined ) ).toBe( 'in' );
		expect( operatorFor( '' ) ).toBe( 'in' );
	} );
} );

describe( 'reconcileTagSelection', () => {
	/**
	 * No product both carries a tag and carries none, so a filter saying both
	 * always matches nothing. Rather than refuse the combination and make the user
	 * undo it, whichever was chosen last wins.
	 */
	it( 'clears the tags when "Without tag" is picked', () => {
		expect( reconcileTagSelection( [ 3, 4 ], [ 3, 4, NO_TAG ] ) ).toEqual( [
			NO_TAG,
		] );
	} );

	it( 'clears "Without tag" when a real tag is picked', () => {
		expect( reconcileTagSelection( [ NO_TAG ], [ NO_TAG, 3 ] ) ).toEqual( [
			3,
		] );
	} );

	it( 'leaves an ordinary selection alone', () => {
		expect( reconcileTagSelection( [ 3 ], [ 3, 4 ] ) ).toEqual( [ 3, 4 ] );
	} );

	it( 'leaves "Without tag" alone when it was already the only choice', () => {
		expect( reconcileTagSelection( [ NO_TAG ], [ NO_TAG ] ) ).toEqual( [
			NO_TAG,
		] );
	} );
} );
