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
	dateForStorage,
	defaultModuleOperator,
	emptyForm,
	groupModuleFields,
	moduleOperators,
	moduleValue,
	NO_TAG,
	NO_VALUE,
	operatorFor,
	operatorTakesRange,
	operatorTakesValue,
	reconcileAbsence,
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

	/**
	 * The language is a sibling of the scope, so it has to travel exactly the way
	 * the scope does: on the payload, never among the conditions. A condition would
	 * be wrong twice over — it would appear in the filter summary as something the
	 * user chose, and the "remove this condition" repair path would offer to remove
	 * the frame the whole page is working inside.
	 */
	describe( 'the language', () => {
		it( 'rides beside the scope, not among the conditions', () => {
			const filter = buildFilter( form(), 'product', [], 'sr' );

			expect( filter.language ).toBe( 'sr' );
			expect( filter.conditions ).toEqual( [] );
		} );

		/**
		 * A shop without WPML must produce a payload byte-identical to the one it
		 * produced before any of this existed — not one carrying an explicit null,
		 * which would put evidence of a feature it does not have into every saved
		 * filter and every frozen operation it ever makes.
		 */
		it( 'is absent entirely when there is no language', () => {
			expect( buildFilter( form(), 'product' ) ).not.toHaveProperty(
				'language'
			);
			expect(
				buildFilter( form(), 'product', [], undefined )
			).not.toHaveProperty( 'language' );
			expect(
				buildFilter( form(), 'product', [], '' )
			).not.toHaveProperty( 'language' );
		} );

		it( 'survives alongside real conditions and the variation scope', () => {
			const filter = buildFilter(
				form( { priceMin: '10' } ),
				'variation',
				[],
				'sr'
			);

			expect( filter.language ).toBe( 'sr' );
			expect( filter.scope ).toBe( 'variation' );
			expect( conditionFor( filter, 'price' ).operator ).toBe( '>=' );
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

	it( 'sends the operator the row is set to, verbatim', () => {
		expect(
			conditions( { 'acf:supplier': { value: 'Globex', mode: '=' } } )
		).toEqual( [
			{ field: 'acf:supplier', operator: '=', value: 'Globex' },
		] );

		expect(
			conditions( {
				'acf:supplier': { value: 'Globex', mode: '!=' },
			} )[ 0 ].operator
		).toBe( '!=' );
	} );

	// A row the user has not touched still has to mean something: the value box
	// is what they type into first, and the operator is left where it opened.
	it( 'falls back to the default operator when the row names none', () => {
		expect(
			conditions( { 'acf:supplier': { value: 'Globex' } } )[ 0 ].operator
		).toBe( '=' );
	} );

	// The field list can change under a form that is already open — a licence
	// lapses, an ACF field is deleted and rebuilt with fewer operators. An
	// operator the field does not declare is refused by the registry, and a
	// refusal stops the WHOLE filter rather than just this row.
	it( 'drops a row whose operator the field does not declare', () => {
		expect(
			conditions( {
				'acf:supplier': { value: 'Globex', mode: 'between' },
			} )
		).toEqual( [] );
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

describe( 'module field operators', () => {
	const field = ( operators ) => ( {
		key: 'acf:x',
		control: 'text',
		operators,
		scopes: [ 'product' ],
		available: true,
	} );

	it( 'offers only what the field declares, in menu order', () => {
		// Declared in a deliberately jumbled order: the menu must not inherit it,
		// or the same field reads differently depending on how a provider happened
		// to list its operators.
		expect(
			moduleOperators( field( [ 'between', 'exists', '=', '>' ] ) )
		).toEqual( [ '=', '>', 'between', 'exists' ] );
	} );

	it( 'offers nothing for a field that declares nothing', () => {
		expect( moduleOperators( field( [] ) ) ).toEqual( [] );
		expect( moduleOperators( {} ) ).toEqual( [] );
	} );

	// The regression this whole change exists for. `>`, `>=`, `<`, `<=` and
	// `between` are declared by ACF's number and date fields, the compiler emits
	// them and the engine answers them — and the old mode-to-operator mapping
	// could only ever produce '=', '!=', 'in', 'not_in' or 'contains', so "cost
	// is more than 100" was unaskable from the UI.
	it( 'reaches the ordered operators a number field declares', () => {
		const number = field( [
			'=',
			'!=',
			'>',
			'>=',
			'<',
			'<=',
			'between',
			'exists',
			'not_exists',
		] );

		expect( moduleOperators( number ) ).toContain( '>' );
		expect( moduleOperators( number ) ).toContain( 'between' );
	} );

	it( 'starts on the first operator that takes a value, not on presence', () => {
		expect( defaultModuleOperator( field( [ '=', 'exists' ] ) ) ).toBe(
			'='
		);
		expect(
			defaultModuleOperator(
				field( [ 'exists', 'not_exists', 'contains' ] )
			)
		).toBe( 'contains' );
	} );

	// A field that can only be asked about presence has nothing else to open on.
	it( 'starts on presence when that is all the field has', () => {
		expect(
			defaultModuleOperator( field( [ 'exists', 'not_exists' ] ) )
		).toBe( 'exists' );
		expect( defaultModuleOperator( field( [] ) ) ).toBe( '' );
	} );

	it( 'knows which operators need a value and which need two', () => {
		expect( operatorTakesValue( '=' ) ).toBe( true );
		expect( operatorTakesValue( 'between' ) ).toBe( true );
		expect( operatorTakesValue( 'exists' ) ).toBe( false );
		expect( operatorTakesValue( 'not_exists' ) ).toBe( false );

		expect( operatorTakesRange( 'between' ) ).toBe( true );
		expect( operatorTakesRange( '>' ) ).toBe( false );
	} );
} );

describe( 'module conditions across the operator range', () => {
	const cost = {
		key: 'acf:cost',
		control: 'number',
		operators: [ '=', '>', '<', 'between', 'exists', 'not_exists' ],
		scopes: [ 'product' ],
		available: true,
	};

	const conditions = ( modules ) =>
		buildFilter( { ...emptyForm(), modules }, 'product', [ cost ] )
			.conditions;

	it( 'sends an ordered comparison with a number, not a string', () => {
		expect(
			conditions( { 'acf:cost': { value: '100', mode: '>' } } )
		).toEqual( [ { field: 'acf:cost', operator: '>', value: 100 } ] );
	} );

	it( 'sends a range as a two-element list', () => {
		expect(
			conditions( {
				'acf:cost': { value: '10', to: '90', mode: 'between' },
			} )
		).toEqual( [
			{ field: 'acf:cost', operator: 'between', value: [ 10, 90 ] },
		] );
	} );

	// Half a range is not a narrower range, it is a different question — and
	// BETWEEN with one bound missing is a condition the engine refuses, which
	// stops the whole filter rather than this row.
	it( 'sends nothing for half a range', () => {
		expect(
			conditions( { 'acf:cost': { value: '10', mode: 'between' } } )
		).toEqual( [] );
		expect(
			conditions( { 'acf:cost': { to: '90', mode: 'between' } } )
		).toEqual( [] );
	} );

	it( 'sends presence with no value at all', () => {
		expect(
			conditions( { 'acf:cost': { value: '', mode: 'not_exists' } } )
		).toEqual( [ { field: 'acf:cost', operator: 'not_exists' } ] );
	} );

	// Presence ignores the box rather than being blocked by it. The box is
	// removed on screen, so a value left behind when the operator changed must
	// not leak into the condition.
	it( 'ignores a stale value when the operator asks about presence', () => {
		expect(
			conditions( { 'acf:cost': { value: '100', mode: 'exists' } } )
		).toEqual( [ { field: 'acf:cost', operator: 'exists' } ] );
	} );
} );

describe( 'dates against the format the column actually holds', () => {
	const launch = {
		key: 'acf:launch',
		control: 'date',
		operators: [ '=', '>=', '<=', 'between', 'exists', 'not_exists' ],
		scopes: [ 'product' ],
		available: true,
		value_format: 'Ymd',
	};

	const conditions = ( modules, field = launch ) =>
		buildFilter( { ...emptyForm(), modules }, 'product', [ field ] )
			.conditions;

	// The whole reason this exists. A date input gives `2024-07-08`; ACF's date
	// picker stores `20240708`. Sent as typed, the filter reads perfectly and
	// matches nothing — and nothing is what an over-narrow filter looks like, so
	// the failure never announces itself.
	it( 'sends a date picker the compact form it stores', () => {
		expect(
			conditions( { 'acf:launch': { value: '2024-07-08', mode: '=' } } )
		).toEqual( [
			{ field: 'acf:launch', operator: '=', value: '20240708' },
		] );
	} );

	it( 'sends a date-and-time picker a time as well', () => {
		const stamped = { ...launch, value_format: 'Y-m-d H:i:s' };

		expect(
			conditions(
				{ 'acf:launch': { value: '2024-07-08', mode: '>=' } },
				stamped
			)[ 0 ].value
		).toBe( '2024-07-08 00:00:00' );
	} );

	// A range's far end has to reach the end of its day. Stopping at midnight
	// drops everything recorded during the last day of the range, which is an
	// off-by-one nobody can see: the answer is smaller, and still plausible.
	it( 'takes a range to the end of its last day when the column keeps a time', () => {
		const stamped = { ...launch, value_format: 'Y-m-d H:i:s' };

		expect(
			conditions(
				{
					'acf:launch': {
						value: '2024-01-01',
						to: '2024-12-31',
						mode: 'between',
					},
				},
				stamped
			)[ 0 ].value
		).toEqual( [ '2024-01-01 00:00:00', '2024-12-31 23:59:59' ] );
	} );

	it( 'has no end-of-day to add when the column keeps only a date', () => {
		expect(
			conditions( {
				'acf:launch': {
					value: '2024-01-01',
					to: '2024-12-31',
					mode: 'between',
				},
			} )[ 0 ].value
		).toEqual( [ '20240101', '20241231' ] );
	} );

	it( 'leaves a field that declares no format alone', () => {
		const plain = { ...launch, value_format: '' };

		expect(
			conditions(
				{ 'acf:launch': { value: '2024-07-08', mode: '=' } },
				plain
			)[ 0 ].value
		).toBe( '2024-07-08' );
	} );

	// A value left over from before the field was a date control, or from a saved
	// filter written by hand. Converting it would be inventing data; passing it
	// through lets the ordinary rules deal with it.
	it( 'passes anything that is not an ISO date straight through', () => {
		expect( dateForStorage( 'yesterday', 'Ymd' ) ).toBe( 'yesterday' );
		expect( dateForStorage( '', 'Ymd' ) ).toBe( '' );
		expect( dateForStorage( '20240708', 'Ymd' ) ).toBe( '20240708' );
	} );

	it( 'still drops a half-filled range', () => {
		expect(
			conditions( {
				'acf:launch': { value: '2024-01-01', mode: 'between' },
			} )
		).toEqual( [] );
	} );
} );

describe( 'the "Without a value" entry on a set field', () => {
	const badges = {
		key: 'acf:badges',
		control: 'value_set',
		operators: [ 'in', 'not_in', 'exists', 'not_exists' ],
		scopes: [ 'product' ],
		available: true,
	};

	const conditions = ( row, field = badges ) =>
		buildFilter(
			{ ...emptyForm(), modules: { 'acf:badges': row } },
			'product',
			[ field ]
		).conditions;

	// The question no choice can ask. `is not sale` deliberately KEEPS a product
	// carrying no badge at all — it is, definitively, not on sale — so without
	// this entry the empty ones cannot be selected by any combination at all.
	it( 'asks about the field rather than about which choices', () => {
		expect( conditions( { value: [ NO_VALUE ], mode: 'in' } ) ).toEqual( [
			{ field: 'acf:badges', operator: 'not_exists' },
		] );
	} );

	// The mode reads the way the rest of the row does: the selection is what to
	// keep, so excluding the empty ones leaves exactly those that carry a value.
	it( 'inverts to exists when the row is set to exclude', () => {
		expect(
			conditions( { value: [ NO_VALUE ], mode: 'not_in' } )[ 0 ].operator
		).toBe( 'exists' );
	} );

	// Same rule the tag row follows: asking about the field's absence and about
	// its values at once is two questions, and the absence is the one meant.
	it( 'wins the row when picked alongside real choices', () => {
		expect(
			conditions( { value: [ 'sale', NO_VALUE ], mode: 'in' } )
		).toEqual( [ { field: 'acf:badges', operator: 'not_exists' } ] );
	} );

	it( 'leaves an ordinary selection alone', () => {
		expect( conditions( { value: [ 'sale' ], mode: 'in' } ) ).toEqual( [
			{ field: 'acf:badges', operator: 'in', value: [ 'sale' ] },
		] );
	} );

	// A provider that does not declare presence must not have it smuggled in by a
	// sentinel the control happened to add.
	it( 'sends nothing when the field does not declare presence', () => {
		const plain = { ...badges, operators: [ 'in', 'not_in' ] };

		expect(
			conditions( { value: [ NO_VALUE ], mode: 'in' }, plain )
		).toEqual( [] );
	} );

	// The sentinel is a string and an ACF choice key is a string, so the two could
	// in principle collide. This is the half of the guard that lives here; the
	// control refuses to add the entry when a real option already answers to it.
	it( 'is a value no ordinary choice would be', () => {
		expect( NO_VALUE ).toMatch( /^__catalogops_/ );
		expect( NO_VALUE ).not.toBe( NO_TAG );
	} );
} );

describe( 'reconcileAbsence', () => {
	// The same rule the tag row has always had, now shared with a module's set
	// fields. Nothing both carries a value and carries none, so a selection saying
	// both would always match nothing — and the control let it be built.
	it( 'clears the values when the absence is picked', () => {
		expect(
			reconcileAbsence(
				[ 'sale', 'eco' ],
				[ 'sale', 'eco', NO_VALUE ],
				NO_VALUE
			)
		).toEqual( [ NO_VALUE ] );
	} );

	it( 'clears the absence when a value is picked', () => {
		expect(
			reconcileAbsence( [ NO_VALUE ], [ NO_VALUE, 'sale' ], NO_VALUE )
		).toEqual( [ 'sale' ] );
	} );

	it( 'leaves an ordinary selection alone', () => {
		expect(
			reconcileAbsence( [ 'sale' ], [ 'sale', 'eco' ], NO_VALUE )
		).toEqual( [ 'sale', 'eco' ] );
	} );

	it( 'leaves the absence alone when it was already the only choice', () => {
		expect(
			reconcileAbsence( [ NO_VALUE ], [ NO_VALUE ], NO_VALUE )
		).toEqual( [ NO_VALUE ] );
	} );

	it( 'survives an empty or missing previous selection', () => {
		expect( reconcileAbsence( [], [ NO_VALUE ], NO_VALUE ) ).toEqual( [
			NO_VALUE,
		] );
		expect( reconcileAbsence( undefined, [ 'sale' ], NO_VALUE ) ).toEqual( [
			'sale',
		] );
	} );

	// The tag row keeps its own name and its own sentinel, and must keep behaving
	// exactly as it did — its entry is `NO_TAG`, and term ids are numbers.
	it( 'still drives the tag row through its own sentinel', () => {
		expect( reconcileTagSelection( [ 3, 4 ], [ 3, 4, NO_TAG ] ) ).toEqual( [
			NO_TAG,
		] );
		expect( reconcileTagSelection( [ NO_TAG ], [ NO_TAG, 3 ] ) ).toEqual( [
			3,
		] );
	} );

	// Belt and braces: the control can no longer produce the pair, but a filter
	// restored from elsewhere could, and the builder must still answer sensibly
	// rather than send two contradictory halves.
	it( 'is backed up by the builder, which still lets the absence win', () => {
		const badges = {
			key: 'acf:badges',
			control: 'value_set',
			operators: [ 'in', 'not_in', 'exists', 'not_exists' ],
			scopes: [ 'product' ],
			available: true,
		};

		expect(
			buildFilter(
				{
					...emptyForm(),
					modules: {
						'acf:badges': {
							value: [ 'sale', NO_VALUE ],
							mode: 'in',
						},
					},
				},
				'product',
				[ badges ]
			).conditions
		).toEqual( [ { field: 'acf:badges', operator: 'not_exists' } ] );
	} );
} );
