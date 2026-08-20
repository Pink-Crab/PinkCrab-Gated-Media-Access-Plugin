/**
 * wp-admin — build/js/admin.js
 *
 * Two behaviours, both for the admin pickers (src/Admin/Pickers):
 *
 * 1. Search pickers. Any input carrying `data-gatedmedia-picker` gets
 *    jQuery UI autocomplete against that admin-ajax action, writing the
 *    chosen id into the hidden input named by `data-gatedmedia-target`.
 *    Bound by delegation on focus, because quick edit clones its template
 *    row after load.
 *
 * 2. The Add Access form shows one item row at a time, following the item
 *    type select. Without JS every row shows, and the form still submits.
 *
 * The nonce arrives as `window.gatedmediaPicker` from Asset_Loader.
 */

import { onReady } from './shared/dom';

const $ = window.jQuery;

/**
 * Wires autocomplete onto one picker input, once.
 *
 * @param {HTMLInputElement} input The visible search input.
 */
function wirePicker( input ) {
	if ( input.dataset.gatedmediaWired ) {
		return;
	}

	input.dataset.gatedmediaWired = '1';

	const hidden = document.getElementById( input.dataset.gatedmediaTarget );

	$( input ).autocomplete( {
		minLength: 2,
		source( request, response ) {
			$.getJSON(
				window.ajaxurl,
				{
					action: input.dataset.gatedmediaPicker,
					_ajax_nonce: window.gatedmediaPicker?.nonce,
					term: request.term,
				},
				( results ) =>
					response(
						results.map( ( result ) => ( {
							label: result.label,
							value: result.label,
							id: result.id,
						} ) )
					)
			);
		},
		select( event, ui ) {
			if ( hidden ) {
				hidden.value = ui.item.id;
			}
		},
	} );

	// Typing again voids the previous choice until something is selected.
	input.addEventListener( 'input', () => {
		if ( hidden ) {
			hidden.value = '';
		}
	} );
}

/**
 * Shows the item row matching the selected type, hides the others.
 *
 * @param {HTMLSelectElement} typeSelect The item type select.
 */
function toggleItemRows( typeSelect ) {
	document.querySelectorAll( '[data-gatedmedia-row]' ).forEach( ( row ) => {
		row.style.display =
			row.dataset.gatedmediaRow === typeSelect.value ? '' : 'none';
	} );
}

onReady( () => {
	document.addEventListener(
		'focusin',
		( event ) => {
			if ( event.target.matches?.( '[data-gatedmedia-picker]' ) ) {
				wirePicker( event.target );
			}
		},
		true
	);

	const typeSelect = document.getElementById( 'gatedmedia_item_type' );

	if ( typeSelect ) {
		toggleItemRows( typeSelect );
		typeSelect.addEventListener( 'change', () =>
			toggleItemRows( typeSelect )
		);
	}
} );
