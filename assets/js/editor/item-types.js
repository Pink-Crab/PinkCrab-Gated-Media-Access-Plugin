/**
 * The three kinds of thing a product can contain.
 *
 * The values are storage, not wording: a stored row is `"group:12"`, and
 * `Product_Meta` reads that pair back. They were being printed straight onto
 * the screen as the tab labels and in each row's left cell, so the editor said
 * "group", "post" and "file" in English whatever the site's language.
 *
 * One list, so the tabs and the rows cannot name the same thing differently.
 */

import { __ } from '@wordpress/i18n';

export const ITEM_TYPES = [
	{ value: 'group', label: __( 'Group', 'gated-media-access' ) },
	{ value: 'post', label: __( 'Post', 'gated-media-access' ) },
	{ value: 'file', label: __( 'File', 'gated-media-access' ) },
];

/**
 * Just the stored values, in order.
 *
 * @return {string[]} The type keys.
 */
export function typeValues() {
	return ITEM_TYPES.map( ( type ) => type.value );
}

/**
 * What one type is called.
 *
 * A stored row can name anything — an older version's type, a hand-edited
 * value — so an unknown one reads as itself rather than as nothing.
 *
 * @param {string} value The stored type.
 * @return {string} Its label.
 */
export function typeLabel( value ) {
	const known = ITEM_TYPES.find( ( type ) => type.value === value );

	return known ? known.label : value;
}
