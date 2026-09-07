/**
 * The product editor's item picker.
 *
 * The original chained fetch → response.json() → setResults with no .catch,
 * no response.ok check and no ordering guard. admin-ajax answers a failed
 * nonce with the bare string "-1", which is not JSON, so the chain rejected
 * unhandled and the list silently stopped updating; and because search() fired
 * on every keystroke, a slow early request could resolve after a later one and
 * overwrite newer matches with stale ones.
 */

import { createItemSearch } from '../../assets/js/editor/item-search';

const CONFIG = {
	ajaxurl: 'https://example.test/wp-admin/admin-ajax.php',
	nonce: 'abc123',
	endpoints: { group: 'gatedmedia_search_groups' },
};

/**
 * A fetch that resolves with the given body after a delay.
 *
 * @param {Object} body   What response.json() should give.
 * @param {number} delay  Milliseconds before it resolves.
 * @param {Object} extras Overrides, e.g. { ok: false }.
 */
function respondWith( body, delay = 0, extras = {} ) {
	return () =>
		new Promise( ( resolve ) => {
			setTimeout(
				() =>
					resolve( {
						ok: true,
						status: 200,
						json: async () => body,
						...extras,
					} ),
				delay
			);
		} );
}

describe( 'the item search', () => {
	it( 'asks the endpoint for the chosen type, with the nonce and the term', async () => {
		const fetch = jest.fn( respondWith( [] ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await search( 'brief', 'group' );

		const url = fetch.mock.calls[ 0 ][ 0 ];

		expect( url ).toContain( 'action=gatedmedia_search_groups' );
		expect( url ).toContain( '_ajax_nonce=abc123' );
		expect( url ).toContain( 'term=brief' );
	} );

	it( 'encodes a term that would otherwise break the query string', async () => {
		const fetch = jest.fn( respondWith( [] ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await search( 'a&b=c d', 'group' );

		expect( fetch.mock.calls[ 0 ][ 0 ] ).toContain(
			`term=${ encodeURIComponent( 'a&b=c d' ) }`
		);
	} );

	it( 'maps what came back to id and label pairs', async () => {
		const fetch = jest.fn(
			respondWith( [ { id: 12, label: 'Analyst briefings' } ] )
		);
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [
			{ id: '12', label: 'Analyst briefings' },
		] );
	} );

	it( 'answers with nothing when the request fails, rather than rejecting', async () => {
		const fetch = jest.fn( () => Promise.reject( new Error( 'offline' ) ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [] );
	} );

	it( 'answers with nothing on a non-OK response', async () => {
		const fetch = jest.fn(
			respondWith( null, 0, { ok: false, status: 502 } )
		);
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [] );
	} );

	it( 'answers with nothing when the body is not JSON, as a failed nonce is', async () => {
		// admin-ajax replies "-1" with a 200 when check_ajax_referer dies.
		const fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				status: 200,
				json: async () => {
					throw new SyntaxError( 'Unexpected token -' );
				},
			} )
		);
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [] );
	} );

	it( 'answers with nothing when the body is not a list', async () => {
		const fetch = jest.fn( respondWith( { error: 'nope' } ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [] );
	} );

	it( 'asks nothing at all for a term too short to search on', async () => {
		const fetch = jest.fn( respondWith( [ { id: 1, label: 'x' } ] ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'a', 'group' ) ).resolves.toEqual( [] );
		await expect( search( '', 'group' ) ).resolves.toEqual( [] );

		expect( fetch ).not.toHaveBeenCalled();
	} );

	it( 'discards a slow earlier answer so it cannot overwrite a newer one', async () => {
		// The race the undebounced keystroke handler made reachable: "an" is
		// asked first and answers last.
		const fetch = jest.fn( ( url ) =>
			url.includes( 'term=analyst' )
				? respondWith( [ { id: 2, label: 'Analyst' } ], 0 )()
				: respondWith( [ { id: 1, label: 'Annual' } ], 50 )()
		);

		const search = createItemSearch( { ...CONFIG, fetch } );

		const stale = search( 'an', 'group' );
		const fresh = search( 'analyst', 'group' );

		await expect( fresh ).resolves.toEqual( [
			{ id: '2', label: 'Analyst' },
		] );

		// The earlier one still settles, but says it has been superseded.
		await expect( stale ).resolves.toBeNull();
	} );

	it( 'keeps answering after a failure, so one blip does not kill the picker', async () => {
		let call = 0;
		const fetch = jest.fn( () => {
			call += 1;

			return 1 === call
				? Promise.reject( new Error( 'offline' ) )
				: respondWith( [ { id: 3, label: 'Later' } ] )();
		} );

		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [] );
		await expect( search( 'brief', 'group' ) ).resolves.toEqual( [
			{ id: '3', label: 'Later' },
		] );
	} );

	it( 'answers with nothing for a type it has no endpoint for', async () => {
		const fetch = jest.fn( respondWith( [] ) );
		const search = createItemSearch( { ...CONFIG, fetch } );

		await expect( search( 'brief', 'nonsense' ) ).resolves.toEqual( [] );
		expect( fetch ).not.toHaveBeenCalled();
	} );
} );
