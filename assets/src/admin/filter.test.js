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
	groupModuleFields,
	moduleValue,
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
		const filter = buildFilter( emptyForm(), 'product' );

		expect( filter ).toEqual( {
			relation: 'AND',
			scope: 'product',
			conditions: [],
		} );
	} );

	it( 'always ANDs, because an exclusion must narrow and never widen', () => {
		const filter = buildFilter(
			form( { priceMin: '10', category: [ 5 ], categoryMode: 'not_in' } ),
			'product'
		);

		expect( filter.relation ).toBe( 'AND' );
	} );

	it( 'carries the scope, so one filter drives the query, the preview and the run', () => {
		expect( buildFilter( emptyForm(), 'variation' ).scope ).toBe(
			'variation'
		);
	} );

	describe( 'numbers', () => {
		it( 'sends prices as numbers, not as the strings the inputs hold', () => {
			const filter = buildFilter(
				form( { priceMin: '10', priceMax: '250' } ),
				'product'
			);

			expect( filter.conditions ).toEqual( [
				{ field: 'price', operator: '>=', value: 10 },
				{ field: 'price', operator: '<=', value: 250 },
			] );
		} );

		it( 'keeps a zero, which is a real price and not an empty field', () => {
			const filter = buildFilter( form( { priceMin: '0' } ), 'product' );

			expect( conditionFor( filter, 'price' ) ).toEqual( {
				field: 'price',
				operator: '>=',
				value: 0,
			} );
		} );
	} );

	describe( 'text', () => {
		it( 'trims a SKU, so a stray space does not become part of the search', () => {
			const filter = buildFilter( form( { sku: '  ABC ' } ), 'product' );

			expect( conditionFor( filter, 'sku' ).value ).toBe( 'ABC' );
		} );

		it( 'drops a SKU that is only whitespace', () => {
			expect(
				buildFilter( form( { sku: '   ' } ), 'product' ).conditions
			).toHaveLength( 0 );
		} );
	} );

	describe( 'set-valued fields', () => {
		it( 'sends term ids as numbers', () => {
			const filter = buildFilter(
				form( { category: [ '5', '9' ] } ),
				'product'
			);

			expect( conditionFor( filter, 'category' ).value ).toEqual( [
				5, 9,
			] );
		} );

		it( 'maps the exclude mode to not_in', () => {
			const filter = buildFilter(
				form( { category: [ 5 ], categoryMode: 'not_in' } ),
				'product'
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
					'product'
				).conditions
			).toHaveLength( 0 );
		} );

		/**
		 * Brand is WooCommerce's own taxonomy, so it behaves exactly like category
		 * and tag: a fixed key and numeric term ids.
		 *
		 * It used to be neither. The seed command invented a `_catalogops_brand`
		 * meta key for its fake catalogue, the UI was built over whichever key the
		 * API named, and the values travelled as strings — so on a shop using
		 * WooCommerce's brands the control listed nothing and matched nothing.
		 */
		it( 'sends brands as numeric term ids under a fixed key', () => {
			const filter = buildFilter(
				form( { brand: [ '7', 9 ] } ),
				'product'
			);

			expect( conditionFor( filter, 'brand' ) ).toEqual( {
				field: 'brand',
				operator: 'in',
				value: [ 7, 9 ],
			} );
		} );

		it( 'excludes brands with not_in', () => {
			const filter = buildFilter(
				form( { brand: [ 7 ], brandMode: 'not_in' } ),
				'product'
			);

			expect( conditionFor( filter, 'brand' ).operator ).toBe( 'not_in' );
		} );

		it( 'omits an empty brand selection', () => {
			expect(
				buildFilter( form( { brand: [] } ), 'product' ).conditions
			).toHaveLength( 0 );
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
				'product'
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
				'product'
			);

			expect( conditionFor( filter, 'tag' ).operator ).toBe( 'exists' );
		} );

		it( 'still sends real tags as numeric ids', () => {
			const filter = buildFilter(
				form( { tag: [ '3', '4' ] } ),
				'product'
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
					'product'
				).conditions
			).toHaveLength( 0 );
		} );

		it( 'matches the chosen terms under the variation scope', () => {
			const filter = buildFilter(
				form( {
					attribute: 'attribute:pa_size',
					attributeValues: [ '7' ],
				} ),
				'variation'
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
				'variation'
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
				'variation'
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

describe( 'module fields', () => {
	const supplier = {
		key: 'acf:supplier',
		control: 'text',
		operators: [ '=', '!=', 'in', 'exists', 'not_exists' ],
		scopes: [ 'product' ],
		available: true,
	};

	const conditions = ( modules, fields = [ supplier ], scope = 'product' ) =>
		buildFilter( { ...emptyForm(), modules }, scope, fields ).conditions;

	it( 'adds a condition for a filled row', () => {
		expect(
			conditions( { 'acf:supplier': { value: 'Globex', mode: 'in' } } )
		).toEqual( [
			{ field: 'acf:supplier', operator: '=', value: 'Globex' },
		] );
	} );

	it( 'uses the exclusion operator the field declares', () => {
		expect(
			conditions( {
				'acf:supplier': { value: 'Globex', mode: 'not_in' },
			} )[ 0 ].operator
		).toBe( '!=' );
	} );

	it( 'adds nothing for a row nobody filled in', () => {
		expect(
			conditions( { 'acf:supplier': { value: '', mode: 'in' } } )
		).toHaveLength( 0 );
	} );

	/**
	 * A field the licence does not cover is still SERVED, so the UI can show it
	 * locked. It must never reach the payload — the engine refuses it, and a
	 * refusal stops the whole filter rather than just that row.
	 */
	it( 'never sends a field the licence does not cover', () => {
		expect(
			conditions( { 'acf:supplier': { value: 'Globex', mode: 'in' } }, [
				{ ...supplier, available: false },
			] )
		).toHaveLength( 0 );
	} );

	/**
	 * The same guard the built-in attribute row has, for the same reason: a value
	 * left behind by a scope switch must not travel into a scope where the field
	 * means nothing.
	 */
	it( 'drops a field that does not apply to the current scope', () => {
		expect(
			conditions(
				{ 'acf:supplier': { value: 'Globex', mode: 'in' } },
				[ supplier ],
				'variation'
			)
		).toHaveLength( 0 );
	} );

	/**
	 * The descriptor is the authority. A field that declares no operator this row
	 * could use contributes nothing rather than a condition the engine refuses.
	 */
	it( 'sends nothing when the field declares no usable operator', () => {
		expect(
			conditions( { 'acf:supplier': { value: 'Globex', mode: 'in' } }, [
				{ ...supplier, operators: [ 'between' ] },
			] )
		).toHaveLength( 0 );
	} );

	it( 'asks about presence without a value', () => {
		expect(
			conditions( { 'acf:supplier': { value: '', mode: 'not_exists' } } )
		).toEqual( [ { field: 'acf:supplier', operator: 'not_exists' } ] );
	} );

	/**
	 * A presence-only field declares neither '=' nor 'in'. Resolving an
	 * include/exclude operator before handling presence dropped it entirely.
	 */
	it( 'still answers presence for a field that declares only presence', () => {
		expect(
			conditions( { 'acf:supplier': { value: '', mode: 'exists' } }, [
				{ ...supplier, operators: [ 'exists', 'not_exists' ] },
			] )
		).toEqual( [ { field: 'acf:supplier', operator: 'exists' } ] );
	} );

	it( 'appends module conditions after the built-in ones', () => {
		const filter = buildFilter(
			{
				...emptyForm(),
				priceMin: '10',
				modules: { 'acf:supplier': { value: 'Globex', mode: 'in' } },
			},
			'product',
			[ supplier ]
		);

		expect( filter.conditions.map( ( c ) => c.field ) ).toEqual( [
			'price',
			'acf:supplier',
		] );
		expect( filter.relation ).toBe( 'AND' );
	} );

	it( 'is a no-op when no descriptors have arrived yet', () => {
		expect( buildFilter( emptyForm(), 'product' ).conditions ).toHaveLength(
			0
		);
	} );
} );

describe( 'moduleValue', () => {
	it( 'sends term ids as numbers and value sets as strings', () => {
		expect( moduleValue( 'term_set', [ '5', '9' ] ) ).toEqual( [ 5, 9 ] );
		expect( moduleValue( 'value_set', [ 'eco', 'sale' ] ) ).toEqual( [
			'eco',
			'sale',
		] );
	} );

	it( 'sends a number control as a number', () => {
		expect( moduleValue( 'number', '10' ) ).toBe( 10 );
		expect( moduleValue( 'money', '9.99' ) ).toBe( 9.99 );
	} );

	it( 'keeps a zero, which is a value and not an empty box', () => {
		expect( moduleValue( 'number', '0' ) ).toBe( 0 );
	} );

	it( 'trims text', () => {
		expect( moduleValue( 'text', '  Globex ' ) ).toBe( 'Globex' );
		expect( moduleValue( 'text', '   ' ) ).toBeUndefined();
	} );

	/**
	 * A toggle has three states, not two. An explicit "off" is a real question;
	 * only "not asked" means no condition.
	 */
	it( 'tells an unanswered toggle from one answered no', () => {
		expect( moduleValue( 'toggle', '' ) ).toBeUndefined();
		expect( moduleValue( 'toggle', false ) ).toBe( '0' );
		expect( moduleValue( 'toggle', true ) ).toBe( '1' );
	} );

	it( 'sends nothing for an empty set', () => {
		expect( moduleValue( 'term_set', [] ) ).toBeUndefined();
		expect( moduleValue( 'value_set', [ '' ] ) ).toBeUndefined();
	} );
} );

describe( 'groupModuleFields', () => {
	const field = ( key, module, label, scopes = [ 'product' ] ) => ( {
		key,
		module,
		module_label: label,
		scopes,
		control: 'text',
		operators: [ '=' ],
		available: true,
	} );

	it( 'puts every field of one module under a single heading', () => {
		const groups = groupModuleFields(
			[
				field( 'acf:a', 'acf', 'ACF fields' ),
				field( 'acf:b', 'acf', 'ACF fields' ),
				field( 'acf:c', 'acf', 'ACF fields' ),
			],
			'product'
		);

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].label ).toBe( 'ACF fields' );
		expect( groups[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [
			'acf:a',
			'acf:b',
			'acf:c',
		] );
	} );

	it( 'keeps modules apart and keeps the order the server sent', () => {
		const groups = groupModuleFields(
			[
				field( 'acf:a', 'acf', 'ACF fields' ),
				field( 'wpml:lang', 'wpml', 'Translation' ),
				field( 'acf:b', 'acf', 'ACF fields' ),
			],
			'product'
		);

		// Two groups, not three: a module interleaved with another still gets one
		// heading, and the first appearance fixes where it sits.
		expect( groups.map( ( g ) => g.module ) ).toEqual( [ 'acf', 'wpml' ] );
		expect( groups[ 0 ].fields ).toHaveLength( 2 );
		expect( groups[ 1 ].fields ).toHaveLength( 1 );
	} );

	// A control that cannot produce a condition is a control that lies — the same
	// rule that hides the attribute row under the product scope.
	it( 'drops fields that mean nothing in this scope', () => {
		const groups = groupModuleFields(
			[
				field( 'acf:a', 'acf', 'ACF fields', [ 'product' ] ),
				field( 'acf:v', 'acf', 'ACF fields', [ 'variation' ] ),
			],
			'variation'
		);

		expect( groups ).toHaveLength( 1 );
		expect( groups[ 0 ].fields.map( ( f ) => f.key ) ).toEqual( [
			'acf:v',
		] );
	} );

	it( 'yields no group at all when nothing applies to the scope', () => {
		const groups = groupModuleFields(
			[ field( 'acf:a', 'acf', 'ACF fields', [ 'product' ] ) ],
			'variation'
		);

		expect( groups ).toEqual( [] );
	} );

	// The heading is the server's to write. A client that fell back to the module
	// slug would print "acf" as a heading, and would have to be taught every
	// future module's name — the coupling `options_route` exists to avoid.
	it( 'leaves the heading empty rather than inventing one from the slug', () => {
		const groups = groupModuleFields(
			[ { key: 'x:a', module: 'x', scopes: [ 'product' ] } ],
			'product'
		);

		expect( groups[ 0 ].label ).toBe( '' );
		expect( groups[ 0 ].module ).toBe( 'x' );
	} );

	it( 'survives an empty or missing field list', () => {
		expect( groupModuleFields( [], 'product' ) ).toEqual( [] );
		expect( groupModuleFields( undefined, 'product' ) ).toEqual( [] );
	} );
} );
