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

	// The Access list's item filter searches whatever the type select says.
	const filterType = document.getElementById( 'gatedmedia_filter_item_type' );

	if ( filterType ) {
		filterType.addEventListener( 'change', () => {
			const search = document.getElementById(
				'gatedmedia_filter_item_search'
			);
			const hidden = document.getElementById( 'gatedmedia_filter_item' );

			if ( search ) {
				search.dataset.gatedmediaPicker =
					{
						group: 'gatedmedia_search_groups',
						file: 'gatedmedia_search_files',
					}[ filterType.value ] || 'gatedmedia_search_posts';
				search.value = '';
			}

			if ( hidden ) {
				hidden.value = '';
			}
		} );
	}

	// The product box's items: Add turns the current picker choice into a
	// removable row over a gatedmedia_items[] hidden input, so the editor's
	// own save carries the list.
	const addItem = document.getElementById( 'gatedmedia_add_item' );
	const itemList = document.getElementById( 'gatedmedia_product_items' );

	if ( addItem && itemList ) {
		addItem.addEventListener( 'click', () => {
			const type = document.getElementById(
				'gatedmedia_item_type'
			)?.value;
			const hidden = document.getElementById(
				'gatedmedia_product_' + type
			);
			const search = document.getElementById(
				'gatedmedia_product_' + type + '_search'
			);

			if ( ! type || ! hidden?.value ) {
				return;
			}

			const row = document.createElement( 'li' );
			row.append(
				type.charAt( 0 ).toUpperCase() +
					type.slice( 1 ) +
					': ' +
					( search?.value || hidden.value ) +
					' '
			);

			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'gatedmedia_items[]';
			input.value = type + ':' + hidden.value;
			row.append( input );

			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link gatedmedia-remove-item';
			remove.textContent = '×';
			row.append( remove );

			itemList.append( row );

			hidden.value = '';

			if ( search ) {
				search.value = '';
			}
		} );

		itemList.addEventListener( 'click', ( event ) => {
			event.target
				.closest?.( '.gatedmedia-remove-item' )
				?.closest( 'li' )
				?.remove();
		} );
	}

	// The item metabox's buttons: each turns its picker's choice into a
	// navigation to the nonced admin-post URL — no form inside the
	// editor's form.
	document.addEventListener( 'click', ( event ) => {
		const addToGroup = event.target.closest?.( '.gatedmedia-add-to-group' );

		if ( addToGroup ) {
			const hidden = addToGroup.parentElement.querySelector(
				'input[type="hidden"]'
			);

			if ( hidden?.value ) {
				window.location =
					addToGroup.dataset.gatedmediaUrl +
					'&group=' +
					encodeURIComponent( hidden.value );
			}

			return;
		}

		const grant = event.target.closest?.( '.gatedmedia-grant-access' );

		if ( ! grant ) {
			return;
		}

		const wrap = grant.closest( '.gatedmedia-inline-grant' );
		const user = wrap?.querySelector( 'input[type="hidden"]' );
		const days = wrap?.querySelector( '.gatedmedia-inline-grant-days' );

		if ( user?.value ) {
			window.location =
				grant.dataset.gatedmediaUrl +
				'&user=' +
				encodeURIComponent( user.value ) +
				'&days=' +
				encodeURIComponent( days?.value || '' );
		}
	} );
} );
