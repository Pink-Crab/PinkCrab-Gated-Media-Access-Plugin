/**
 * Shared editor controls.
 *
 * Not a generator — these are the two or three controls that genuinely recur,
 * built once so that an icon picker behaves the same in every component that
 * has an icon. Each component's own panel is designed in its edit.js.
 */

import { SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * The sprite symbols we ship, in assets/icons.svg.
 *
 * A component naming a symbol we do not have renders no icon rather than
 * breaking, but there is no reason to make someone guess — so this is a list
 * rather than a text box.
 */
export const ICONS = [
	{
		group: 'Navigation',
		ids: [ 'i-access', 'i-files', 'i-orders', 'i-profile' ],
	},
	{ group: 'Notices', ids: [ 'i-info', 'i-error', 'i-success' ] },
	{
		group: 'Expiry',
		ids: [
			'i-infinity',
			'i-calendar',
			'i-warning',
			'i-blocked',
			'i-clock',
		],
	},
	{
		group: 'Status',
		ids: [ 'i-check', 'i-refund', 'i-active', 'i-revoked' ],
	},
	{ group: 'Contents', ids: [ 'i-doc', 'i-article', 'i-groups', 'i-video' ] },
	{ group: 'Actions', ids: [ 'i-download', 'i-back', 'i-search' ] },
	{ group: 'Misc', ids: [ 'i-empty' ] },
];

/**
 * Every symbol as a flat option list, grouped by what it is for.
 *
 * @param {boolean} optional Whether "no icon" is a valid answer.
 * @return {Array} Options for a SelectControl.
 */
function iconOptions( optional ) {
	const options = optional
		? [ { label: __( '— None —', 'gated-media-access' ), value: '' } ]
		: [];

	ICONS.forEach( ( { group, ids } ) => {
		options.push( {
			label: group,
			options: ids.map( ( id ) => ( {
				label: id.replace( /^i-/, '' ),
				value: id,
			} ) ),
		} );
	} );

	return options;
}

/**
 * Picks one of the sprite symbols.
 *
 * @param {Object}   props
 * @param {string}   props.label    Field label.
 * @param {string}   props.value    Current symbol id.
 * @param {Function} props.onChange Receives the new id.
 * @param {boolean}  props.optional Whether an empty choice is offered.
 * @param {string}   props.help     Extra guidance.
 */
export function IconControl( {
	label,
	value,
	onChange,
	optional = true,
	help,
} ) {
	return (
		<SelectControl
			label={ label || __( 'Icon', 'gated-media-access' ) }
			value={ value || '' }
			options={ iconOptions( optional ) }
			onChange={ onChange }
			help={ help }
			__nextHasNoMarginBottom
		/>
	);
}

/**
 * Currencies offered in the editor's price controls.
 *
 * A shortlist for the dropdown, not a limit on what the plugin sells in:
 * Settings offers every ISO 4217 code and Support\Money formats all of them
 * through ICU. A site selling in another one adds it here.
 */
export const CURRENCIES = [ 'GBP', 'EUR', 'USD', 'AUD', 'CAD', 'NZD', 'JPY' ];

/**
 * How many decimal places a currency has, from the browser's own ICU data —
 * the JS mirror of Support\Money: no hand-kept lists, 2 for a code Intl
 * does not know.
 *
 * @param {string} currency ISO code.
 * @return {number} Its fraction digits.
 */
export function currencyDigits( currency ) {
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: ( currency || 'GBP' ).toUpperCase(),
		} ).resolvedOptions().maximumFractionDigits;
	} catch {
		return 2;
	}
}

/**
 * Formats minor units for the editor preview.
 *
 * Mirrors Support\Money::format() — including that **zero is the word "Free"**,
 * which §6.7 and §6.13 both state, and that the currency data comes from ICU
 * (here the browser's Intl) rather than a hand-kept list. The server remains
 * the authority; this exists so the canvas shows the answer as you type. A
 * site filtering `gatedmedia_format_price` will differ here, which is the
 * accepted cost of a live preview.
 *
 * @param {number} minorUnits Amount in minor units.
 * @param {string} currency   ISO code.
 * @return {string} The amount as a person reads it.
 */
export function formatMinor( minorUnits, currency = 'GBP' ) {
	if ( ! minorUnits ) {
		return __( 'Free', 'gated-media-access' );
	}

	const code = ( currency || 'GBP' ).toUpperCase();

	try {
		const formatter = new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: code,
		} );

		return formatter.format(
			minorUnits / 10 ** formatter.resolvedOptions().maximumFractionDigits
		);
	} catch {
		return `${ code } ${ minorUnits }`;
	}
}

/**
 * An amount of money, entered the way a person thinks about it.
 *
 * Amounts are stored in minor units throughout, which is right for the data
 * and wrong for a text box — typing "49" and getting 49p is the kind of thing
 * nobody notices until an order is wrong. So this shows major units and
 * converts.
 *
 * @param {Object}   props
 * @param {string}   props.label    Field label.
 * @param {number}   props.value    Amount in minor units.
 * @param {Function} props.onChange Receives minor units.
 * @param {string}   props.help     Extra guidance.
 * @param {string}   props.currency ISO code — its digits drive the conversion.
 */
export function MoneyControl( {
	label,
	value,
	onChange,
	help,
	currency = 'GBP',
} ) {
	const digits = currencyDigits( currency );
	const major = ( ( value || 0 ) / 10 ** digits ).toFixed( digits );

	return (
		<TextControl
			type="number"
			step="any"
			min="0"
			label={ label }
			value={ major }
			onChange={ ( next ) =>
				onChange( Math.round( parseFloat( next || 0 ) * 10 ** digits ) )
			}
			help={ help }
			__nextHasNoMarginBottom
			__next40pxDefaultSize
		/>
	);
}

/**
 * A note explaining an attribute the editor deliberately does not expose.
 *
 * Several components take a list that is filled at render time — the section
 * list, the resolver's answer, a product's contents. Those have no control,
 * and saying so is better than a panel that looks like it is missing options.
 *
 * @param {Object} props
 * @param {string} props.children What fills it, and from where.
 */
export function FilledAtRender( { children } ) {
	return (
		<p className="components-base-control__help" style={ { marginTop: 0 } }>
			{ children }
		</p>
	);
}
