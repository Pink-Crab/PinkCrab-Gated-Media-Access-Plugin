/**
 * The product editor's item picker, as one function.
 *
 * The editor chained `fetch → response.json() → setResults` with no `.catch`,
 * no `response.ok` check and nothing to order the answers. Three things fell
 * out of that:
 *
 * - `check_ajax_referer()` answers a stale nonce with the bare string `-1`,
 *   which is not JSON, so the chain rejected unhandled and the list simply
 *   stopped updating with nothing said;
 * - the same for any network failure or proxy error;
 * - the handler fired on every keystroke, so a slow early request could
 *   resolve after a later one and put stale matches back on screen.
 *
 * Every path here answers with a list, or with `null` to mean "a newer search
 * has already been asked, ignore me". Nothing rejects.
 */

/** Below this, a search matches too much to be worth asking for. */
const MIN_TERM = 2;

/**
 * Builds the search used by one editor instance.
 *
 * @param {Object}   config           What the picker needs.
 * @param {string}   config.ajaxurl   admin-ajax's URL.
 * @param {string}   config.nonce     The picker nonce.
 * @param {Object}   config.endpoints Action name per item type.
 * @param {Function} [config.fetch]   Injected for tests; defaults to window.fetch.
 * @return {Function} `( term, type ) => Promise<Array|null>`.
 */
export function createItemSearch( {
	ajaxurl,
	nonce,
	endpoints,
	fetch = ( ...args ) => window.fetch( ...args ),
} ) {
	// Each call takes the next number; only the newest may answer.
	let latest = 0;

	return async function search( term, type ) {
		const trimmed = ( term || '' ).trim();
		const action = endpoints[ type ];

		if ( trimmed.length < MIN_TERM || ! action ) {
			// Still counts as a search: it supersedes anything in flight, so
			// clearing the box cannot be undone by a late answer.
			latest += 1;

			return [];
		}

		latest += 1;
		const mine = latest;

		const url = `${ ajaxurl }?action=${ encodeURIComponent(
			action
		) }&_ajax_nonce=${ encodeURIComponent(
			nonce
		) }&term=${ encodeURIComponent( trimmed ) }`;

		let found;

		try {
			const response = await fetch( url );

			if ( ! response.ok ) {
				found = [];
			} else {
				found = await response.json();
			}
		} catch {
			// Offline, a proxy error, or admin-ajax's non-JSON "-1".
			found = [];
		}

		if ( mine !== latest ) {
			return null;
		}

		if ( ! Array.isArray( found ) ) {
			return [];
		}

		return found.map( ( result ) => ( {
			id: String( result.id ),
			label: result.label,
		} ) );
	};
}
