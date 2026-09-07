/**
 * Product details — editor.
 *
 * The product's whole form, saving straight to its registered meta over
 * REST. The server stamps what the client must not choose — the UUID
 * identity and the shop currency — so neither has an input here; the
 * currency and its digits arrive as `window.gatedmediaProduct`, supplied by
 * Product_Meta beside the picker-search nonce the item search uses.
 *
 * Drawn to Glynn's Stitch mock (stitch.withgoogle.com project
 * 17998674859270043397, "Artisanal Product Editor Card"): a serif display
 * voice over small-caps utility labels, the reference as a mono line, a
 * heavy rule under the header, typed left-column rows in hairline panels,
 * and the who-may-buy rows keeping a right-hand status column that round
 * 6's invites will fill. No save button — the editor's own Update is the
 * save. Styles are inline because the block renders inside the theme's
 * canvas, whose content styles are the wrong scale for a form.
 */

import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { currencyDigits } from '../../assets/js/editor/controls';
import { createItemSearch } from '../../assets/js/editor/item-search';
import { ITEM_TYPES, typeLabel } from '../../assets/js/editor/item-types';

const META = {
	uuid: 'gatedmedia_uuid',
	price: 'gatedmedia_price_amount',
	duration: 'gatedmedia_duration_days',
	visibility: 'gatedmedia_visibility',
	items: 'gatedmedia_items',
	emails: 'gatedmedia_allowed_email',
	sendInvites: 'gatedmedia_send_invites',
};

// Matches Product_Meta::DURATION_LIFETIME — the server normalises to it on
// write, so the block must write it too rather than an empty string.
const LIFETIME = '-1';

/**
 * What an empty or zero duration box stores: lifetime, never an empty string.
 *
 * @param {string} typed What is in the box.
 * @return {string} The value to save.
 */
function storedDays( typed ) {
	const days = typed.replace( /\D/g, '' );

	return '' === days || '0' === days ? LIFETIME : days;
}

const ENDPOINTS = {
	group: 'gatedmedia_search_groups',
	post: 'gatedmedia_search_posts',
	file: 'gatedmedia_search_files',
};

const INK = '#333235';
const INK_SOFT = '#605e61';
const HAIRLINE = '1px solid #e4e4e7';
const PANEL_RULE = '1px solid #dcdcde';
const SANS =
	'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
const SERIF = 'Georgia, "Iowan Old Style", "Times New Roman", serif';

/**
 * The currency's own symbol from the browser's ICU data — the £ in the
 * amount field's prefix.
 *
 * @param {string} currency ISO code.
 * @return {string} Its symbol, or the code when Intl does not know it.
 */
function currencySymbol( currency ) {
	try {
		const part = new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: ( currency || 'GBP' ).toUpperCase(),
		} )
			.formatToParts( 0 )
			.find( ( piece ) => 'currency' === piece.type );

		return part ? part.value : currency;
	} catch {
		return currency;
	}
}

const STYLES = {
	card: {
		'--wp-admin-theme-color': INK_SOFT,
		'--wp-components-color-accent': INK_SOFT,
		fontFamily: SANS,
		fontSize: '13px',
		lineHeight: 1.5,
		color: INK,
		background: '#ffffff',
		border: PANEL_RULE,
		padding: '40px 48px 44px',
	},
	caps: {
		fontFamily: SANS,
		fontSize: '10px',
		fontWeight: 600,
		letterSpacing: '1.5px',
		textTransform: 'uppercase',
		color: INK_SOFT,
	},
	header: {
		display: 'flex',
		alignItems: 'flex-end',
		justifyContent: 'space-between',
		gap: '24px',
		paddingBottom: '18px',
		borderBottom: `2px solid ${ INK }`,
	},
	ref: {
		fontFamily: 'monospace',
		fontSize: '11px',
		letterSpacing: '0.5px',
		color: INK_SOFT,
		margin: '10px 0 0',
		textTransform: 'uppercase',
	},
	fare: {
		display: 'flex',
		alignItems: 'baseline',
		gap: '8px',
		whiteSpace: 'nowrap',
	},
	fareAmount: {
		fontFamily: SERIF,
		fontSize: '30px',
		color: INK,
	},
	fields: {
		display: 'flex',
		alignItems: 'flex-end',
		gap: '32px',
		padding: '28px 0 36px',
	},
	fieldLabel: {
		display: 'block',
		marginBottom: '8px',
	},
	inputWrap: {
		display: 'flex',
		alignItems: 'center',
		height: '40px',
		border: PANEL_RULE,
		background: '#ffffff',
	},
	inputPrefix: {
		padding: '0 2px 0 12px',
		color: INK_SOFT,
		fontSize: '13px',
		lineHeight: '38px',
		alignSelf: 'center',
	},
	input: {
		// No `outline: none`. The wrap draws the border, but taking the
		// browser's own ring away leaves a keyboard user with nothing at all
		// — §6.3, "never removed, never replaced with a colour change alone".
		border: 'none',
		background: 'transparent',
		height: '100%',
		width: '100%',
		padding: '0 12px',
		fontFamily: SANS,
		fontSize: '13px',
		color: INK,
	},
	checkboxWrap: {
		display: 'flex',
		alignItems: 'center',
		height: '40px',
		marginLeft: '8px',
	},
	sectionHead: {
		display: 'flex',
		alignItems: 'baseline',
		justifyContent: 'space-between',
		gap: '24px',
		paddingBottom: '10px',
		borderBottom: HAIRLINE,
		marginTop: '8px',
	},
	sectionTitle: {
		fontFamily: SERIF,
		fontSize: '21px',
		color: INK,
		margin: 0,
	},
	toolbar: {
		display: 'flex',
		alignItems: 'center',
		gap: '16px',
		margin: '20px 0 0',
	},
	tab: ( active ) => ( {
		fontFamily: SANS,
		fontSize: '11px',
		fontWeight: 600,
		letterSpacing: '2px',
		textTransform: 'uppercase',
		color: active ? INK : INK_SOFT,
		background: active ? '#f0f0f1' : 'transparent',
		border: active ? PANEL_RULE : '1px solid transparent',
		cursor: 'pointer',
		padding: '0 14px',
		height: '48px',
		marginRight: '4px',
	} ),
	panel: {
		border: PANEL_RULE,
		margin: '20px 0 0',
	},
	itemRow: ( last ) => ( {
		display: 'flex',
		alignItems: 'center',
		margin: '0 20px',
		padding: '22px 4px',
		borderBottom: last ? 'none' : HAIRLINE,
	} ),
	typeCell: {
		flex: '0 0 110px',
		display: 'flex',
		alignItems: 'center',
	},
	nameCell: {
		flex: '1 1 auto',
		display: 'flex',
		alignItems: 'center',
		fontFamily: SERIF,
		fontSize: '16px',
		color: INK,
		overflow: 'hidden',
	},
	rowAction: {
		flex: '0 0 auto',
		display: 'flex',
		alignItems: 'center',
		padding: 0,
		border: 'none',
		background: 'transparent',
		cursor: 'pointer',
		opacity: 0.7,
	},
	resultRow: ( last ) => ( {
		display: 'flex',
		alignItems: 'center',
		gap: '12px',
		width: '100%',
		textAlign: 'left',
		border: 'none',
		borderBottom: last ? 'none' : HAIRLINE,
		background: 'transparent',
		cursor: 'pointer',
		padding: '12px 16px',
		fontFamily: SERIF,
		fontSize: '15px',
		color: INK,
	} ),
	empty: {
		margin: '20px 0 0',
		fontSize: '12px',
		color: INK_SOFT,
	},
	inviteBar: {
		display: 'flex',
		alignItems: 'stretch',
		gap: '12px',
		margin: '20px 0 0',
	},
	darkButton: ( enabled ) => ( {
		fontFamily: SANS,
		fontSize: '11px',
		fontWeight: 600,
		letterSpacing: '2px',
		textTransform: 'uppercase',
		border: 'none',
		cursor: enabled ? 'pointer' : 'not-allowed',
		background: '#2b2b2e',
		color: '#ffffff',
		padding: '0 32px',
		height: '48px',
	} ),
	emailRow: ( last ) => ( {
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'space-between',
		gap: '16px',
		margin: '0 20px',
		padding: '22px 4px',
		borderBottom: last ? 'none' : HAIRLINE,
		fontSize: '15px',
	} ),
	spacer: { height: '40px' },
};

/**
 * One row's parts: its type for the left cell, and what to show for it.
 *
 * Labels come from this session's choices first, then the server-supplied
 * labels for stored rows (Product_Meta::item_labels()), and only fall back
 * to the raw identifier when neither knows the row.
 *
 * @param {string} row    The stored row.
 * @param {Object} labels Labels for rows added this session.
 * @param {Object} stored Server-supplied labels for stored rows.
 * @return {{type: string, label: string}} The row's parts.
 */
function itemParts( row, labels, stored ) {
	const [ type, id ] = row.split( ':', 2 );

	return { type, label: labels[ row ] || stored[ row ] || id };
}

export default function Edit() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ rawMeta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	// Undefined until the store hydrates — never index it raw.
	const meta = rawMeta || {};
	const shop = window.gatedmediaProduct || {
		currency: 'GBP',
		digits: 2,
		nonce: '',
	};

	const [ itemType, setItemType ] = useState( 'group' );
	const [ query, setQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );

	// One per editor instance, so its ordering guard is its own.
	const [ askItems ] = useState( () =>
		createItemSearch( {
			ajaxurl: window.ajaxurl,
			nonce: shop.nonce,
			endpoints: ENDPOINTS,
		} )
	);
	const [ labels, setLabels ] = useState( {} );
	const [ emailDraft, setEmailDraft ] = useState( '' );

	const blockProps = useBlockProps( {
		className: 'gatedmedia-product-details',
	} );

	const items = meta[ META.items ] || [];
	const emails = meta[ META.emails ] || [];
	// Lifetime is stored as -1, never as an empty string: an empty box and an
	// unset key used to be the same falsey thing. The box still shows empty
	// for it, with the placeholder saying what empty means.
	const storedDuration = meta[ META.duration ] ?? '';
	const duration = LIFETIME === storedDuration ? '' : storedDuration;
	const digits = currencyDigits( shop.currency );
	const minor = meta[ META.price ] || 0;
	const set = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const emailValid = /^\S+@\S+\.\S+$/.test( emailDraft.trim() );

	const addEmail = () => {
		if ( ! emailValid ) {
			return;
		}

		const address = emailDraft.trim().toLowerCase();

		if ( ! emails.includes( address ) ) {
			set( META.emails, [ ...emails, address ] );
		}

		setEmailDraft( '' );
	};

	const search = ( term, type ) => {
		setQuery( term );

		// null means a newer search has already been asked for, so this
		// answer is stale and must not replace what is on screen.
		askItems( term, type ).then( ( found ) => {
			if ( null !== found ) {
				setResults( found );
			}
		} );
	};

	const addItem = ( result ) => {
		const row = `${ itemType }:${ result.id }`;

		if ( ! items.includes( row ) ) {
			set( META.items, [ ...items, row ] );
			setLabels( { ...labels, [ row ]: result.label } );
		}

		setQuery( '' );
		setResults( [] );
	};

	const removeFrom = ( key, list, value ) =>
		set(
			key,
			list.filter( ( kept ) => kept !== value )
		);

	return (
		<div { ...blockProps }>
			<div style={ STYLES.card }>
				<div style={ STYLES.header }>
					<div>
						<span style={ STYLES.caps }>
							{ __( 'Product settings', 'gated-media-access' ) }
						</span>
						<p style={ STYLES.ref }>
							{ meta[ META.uuid ]
								? `${ __( 'Ref:', 'gated-media-access' ) } ${
										meta[ META.uuid ]
								  }`
								: __(
										'Ref: minted on first save',
										'gated-media-access'
								  ) }
						</p>
					</div>
					<div style={ STYLES.fare }>
						<span style={ STYLES.fareAmount }>
							{ 0 === minor
								? __( 'Free', 'gated-media-access' )
								: `${ currencySymbol( shop.currency ) }${ (
										minor /
										10 ** digits
								  ).toFixed( digits ) }` }
						</span>
						<span style={ STYLES.caps }>
							{ '' === duration
								? `/ ${ __(
										'lifetime',
										'gated-media-access'
								  ) }`
								: `/ ${ duration } ${ __(
										'days',
										'gated-media-access'
								  ) }` }
						</span>
					</div>
				</div>

				<div style={ STYLES.fields }>
					<div>
						<span
							style={ {
								...STYLES.caps,
								...STYLES.fieldLabel,
							} }
						>
							{ __( 'Amount', 'gated-media-access' ) }
						</span>
						<div style={ { ...STYLES.inputWrap, width: '150px' } }>
							<span style={ STYLES.inputPrefix }>
								{ currencySymbol( shop.currency ) }
							</span>
							<input
								type="number"
								step="any"
								min="0"
								style={ STYLES.input }
								value={ ( minor / 10 ** digits ).toFixed(
									digits
								) }
								onChange={ ( event ) =>
									set(
										META.price,
										Math.round(
											parseFloat(
												event.target.value || 0
											) *
												10 ** digits
										)
									)
								}
								aria-label={ __(
									'Price amount',
									'gated-media-access'
								) }
							/>
						</div>
					</div>
					<div>
						<span
							style={ {
								...STYLES.caps,
								...STYLES.fieldLabel,
							} }
						>
							{ __( 'Duration', 'gated-media-access' ) }
						</span>
						<div style={ { ...STYLES.inputWrap, width: '130px' } }>
							<input
								type="text"
								inputMode="numeric"
								style={ STYLES.input }
								placeholder={ __(
									'lifetime',
									'gated-media-access'
								) }
								value={ duration }
								onChange={ ( event ) =>
									set(
										META.duration,
										storedDays( event.target.value )
									)
								}
								aria-label={ __(
									'Duration in days',
									'gated-media-access'
								) }
							/>
							{ '' !== duration && (
								<span
									style={ {
										...STYLES.inputPrefix,
										padding: '0 12px 0 0',
									} }
								>
									{ __( 'days', 'gated-media-access' ) }
								</span>
							) }
						</div>
					</div>
					<div style={ STYLES.checkboxWrap }>
						<CheckboxControl
							label={
								<span
									style={ {
										...STYLES.caps,
										display: 'inline-block',
										marginLeft: '6px',
										lineHeight: 1.7,
									} }
								>
									{ __(
										'Hidden from public listings',
										'gated-media-access'
									) }
								</span>
							}
							checked={ 'unlisted' === meta[ META.visibility ] }
							onChange={ ( unlisted ) =>
								set(
									META.visibility,
									unlisted ? 'unlisted' : 'listed'
								)
							}
							__nextHasNoMarginBottom
						/>
					</div>
				</div>

				<div style={ STYLES.sectionHead }>
					<h3 style={ STYLES.sectionTitle }>
						{ __( 'Grants access to', 'gated-media-access' ) }
					</h3>
					<span style={ STYLES.caps }>
						{ __(
							'A group covers whatever it contains',
							'gated-media-access'
						) }
					</span>
				</div>

				<div style={ STYLES.toolbar }>
					<span
						style={ {
							display: 'inline-flex',
							flex: '0 0 auto',
						} }
					>
						{ ITEM_TYPES.map( ( type ) => (
							<button
								key={ type.value }
								type="button"
								style={ STYLES.tab( itemType === type.value ) }
								onClick={ () => {
									setItemType( type.value );
									search( query, type.value );
								} }
							>
								{ type.label }
							</button>
						) ) }
					</span>
					<div
						style={ {
							...STYLES.inputWrap,
							flex: '1 1 auto',
							height: '48px',
							border: '1px solid #b4b1b4',
						} }
					>
						<svg
							width="16"
							height="16"
							viewBox="0 0 24 24"
							fill="none"
							stroke={ INK_SOFT }
							strokeWidth="2"
							style={ { margin: '0 0 0 12px', flexShrink: 0 } }
							aria-hidden="true"
						>
							<circle cx="11" cy="11" r="7" />
							<line x1="21" y1="21" x2="16.5" y2="16.5" />
						</svg>
						<input
							style={ STYLES.input }
							placeholder={ __(
								'Search resources…',
								'gated-media-access'
							) }
							value={ query }
							onChange={ ( event ) =>
								search( event.target.value, itemType )
							}
							aria-label={ __(
								'Search for an item to add',
								'gated-media-access'
							) }
						/>
					</div>
				</div>

				{ results.length > 0 && (
					<div style={ STYLES.panel }>
						{ results.map( ( result, index ) => (
							<button
								key={ result.id }
								type="button"
								style={ STYLES.resultRow(
									index === results.length - 1
								) }
								onClick={ () => addItem( result ) }
							>
								<span style={ STYLES.caps }>+</span>
								{ result.label }
							</button>
						) ) }
					</div>
				) }

				{ items.length > 0 ? (
					<div style={ STYLES.panel }>
						{ items.map( ( row, index ) => {
							const part = itemParts(
								row,
								labels,
								shop.itemLabels || {}
							);

							return (
								<div
									key={ row }
									style={ STYLES.itemRow(
										index === items.length - 1
									) }
								>
									<span style={ STYLES.typeCell }>
										<span style={ STYLES.caps }>
											{ typeLabel( part.type ) }
										</span>
									</span>
									<span style={ STYLES.nameCell }>
										{ part.label }
									</span>
									<button
										type="button"
										style={ STYLES.rowAction }
										onClick={ () =>
											removeFrom( META.items, items, row )
										}
									>
										<span style={ STYLES.caps }>
											{ __(
												'Remove',
												'gated-media-access'
											) }
										</span>
									</button>
								</div>
							);
						} ) }
					</div>
				) : (
					<p style={ STYLES.empty }>
						{ __(
							'Nothing yet. A search result is added the moment you choose it.',
							'gated-media-access'
						) }
					</p>
				) }

				<div style={ { ...STYLES.sectionHead, marginTop: '36px' } }>
					<h3 style={ STYLES.sectionTitle }>
						{ __( 'Purchasing rules', 'gated-media-access' ) }
					</h3>
					<span style={ STYLES.caps }>
						{ __(
							'Leave empty for public access',
							'gated-media-access'
						) }
					</span>
				</div>

				<div style={ { ...STYLES.checkboxWrap, marginLeft: 0 } }>
					<CheckboxControl
						label={
							<span
								style={ {
									...STYLES.caps,
									display: 'inline-block',
									marginLeft: '6px',
									lineHeight: 1.7,
								} }
							>
								{ __(
									'Send invite emails to new addresses',
									'gated-media-access'
								) }
							</span>
						}
						checked={ '0' !== ( meta[ META.sendInvites ] || '1' ) }
						onChange={ ( send ) =>
							set( META.sendInvites, send ? '1' : '0' )
						}
						__nextHasNoMarginBottom
					/>
				</div>

				<div style={ STYLES.inviteBar }>
					<div
						style={ {
							...STYLES.inputWrap,
							flex: '1 1 auto',
							height: '48px',
							border: '1px solid #b4b1b4',
						} }
					>
						<input
							type="email"
							style={ STYLES.input }
							placeholder={ __(
								'Enter email address…',
								'gated-media-access'
							) }
							value={ emailDraft }
							onChange={ ( event ) =>
								setEmailDraft( event.target.value )
							}
							onKeyDown={ ( event ) => {
								if ( 'Enter' === event.key ) {
									event.preventDefault();
									addEmail();
								}
							} }
							aria-label={ __(
								'Add an email address to the allow-list',
								'gated-media-access'
							) }
						/>
					</div>
					<button
						type="button"
						style={ STYLES.darkButton( emailValid ) }
						disabled={ ! emailValid }
						onClick={ addEmail }
					>
						{ __( 'Add', 'gated-media-access' ) }
					</button>
				</div>

				{ emails.length > 0 && (
					<div style={ STYLES.panel }>
						{ emails.map( ( address, index ) => (
							<div
								key={ address }
								style={ STYLES.emailRow(
									index === emails.length - 1
								) }
							>
								<span>{ address }</span>
								<span
									style={ {
										display: 'flex',
										alignItems: 'center',
										gap: '16px',
									} }
								>
									{ ( shop.invites || {} )[ address ] && (
										<span style={ STYLES.caps }>
											{ sprintf(
												/* translators: %s: date the invite email went out. */
												__(
													'Invited %s',
													'gated-media-access'
												),
												shop.invites[ address ]
											) }
										</span>
									) }
									<button
										type="button"
										style={ {
											border: 'none',
											background: 'transparent',
											cursor: 'pointer',
											padding: 0,
										} }
										onClick={ () =>
											removeFrom(
												META.emails,
												emails,
												address
											)
										}
									>
										<span style={ STYLES.caps }>
											{ __(
												'Remove',
												'gated-media-access'
											) }
										</span>
									</button>
								</span>
							</div>
						) ) }
					</div>
				) }
			</div>
		</div>
	);
}
