/**
 * The filter: a search box, and a type list drawn as a select at wide widths and as chips at narrow ones.
 *
 * Every row is already on the page, so matching happens here rather than in a request, and a section whose rows have all gone is hidden with them, or the view keeps a heading over nothing.
 *
 * Nothing here is required for the view to be readable: with this script absent every row is shown, which is the state the server rendered.
 */

import { all } from '../shared/dom';

/**
 * Shows the rows matching both controls and hides the rest.
 *
 * @param {Element} view  The view holding the filter and the rows.
 * @param {Object}  state The term typed and the type chosen.
 */
function apply( view, state ) {
	const needle = state.term.trim().toLowerCase();

	all( '.gatedmedia-row', view ).forEach( ( row ) => {
		const matchesTerm =
			'' === needle ||
			( row.textContent || '' ).toLowerCase().includes( needle );
		const matchesType =
			'all' === state.type || row.dataset.gatedmediaType === state.type;

		row.hidden = ! matchesTerm || ! matchesType;
	} );

	all( '.gatedmedia-section', view ).forEach( ( section ) => {
		const rows = all( '.gatedmedia-row', section );

		section.hidden =
			0 !== rows.length && rows.every( ( row ) => row.hidden );
	} );
}

/**
 * Marks the chosen chip, so the two controls never disagree.
 *
 * @param {Element} filter The filter block.
 * @param {string}  type   The chosen type.
 */
function markChips( filter, type ) {
	all( '[data-gatedmedia-chip]', filter ).forEach( ( chip ) => {
		const active = chip.dataset.gatedmediaChip === type;

		chip.classList.toggle( 'is-active', active );
		chip.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
	} );
}

/**
 * @param {Document|Element} scope Where to look for filters.
 */
export default function initFilter( scope ) {
	all( '.gatedmedia-filter', scope ).forEach( ( filter ) => {
		const view = filter.closest( '.gatedmedia-view' );

		if ( ! view ) {
			return;
		}

		const chips = all( '[data-gatedmedia-chip]', view );
		const select = view.querySelector( '[data-gatedmedia-filter="type"]' );
		const search = filter.querySelector(
			'[data-gatedmedia-filter="search"] input'
		);
		const state = { term: '', type: 'all' };

		const update = ( changes ) => {
			Object.assign( state, changes );

			if ( select ) {
				select.value = state.type;
			}

			markChips( view, state.type );
			apply( view, state );
		};

		if ( search ) {
			search.addEventListener( 'input', () =>
				update( { term: search.value } )
			);
		}

		if ( select ) {
			select.addEventListener( 'change', () =>
				update( { type: select.value } )
			);
		}

		chips.forEach( ( chip ) => {
			chip.addEventListener( 'click', () =>
				update( { type: chip.dataset.gatedmediaChip } )
			);
		} );
	} );
}
