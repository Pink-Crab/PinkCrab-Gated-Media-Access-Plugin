/**
 * Product details — editor.
 *
 * The product's whole form, saving straight to its registered meta over
 * REST. The server stamps what the client must not choose — the UUID
 * identity and the shop currency — so neither has an input here; the
 * currency and its digits arrive as `window.gatedmediaProduct`, supplied by
 * Product_Meta beside the picker-search nonce the item search uses.
 */

import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ComboboxControl,
	Flex,
	FlexBlock,
	FlexItem,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { MoneyControl, formatMinor } from '../../assets/js/editor/controls';

const META = {
	uuid: 'gatedmedia_uuid',
	price: 'gatedmedia_price_amount',
	duration: 'gatedmedia_duration_days',
	visibility: 'gatedmedia_visibility',
	items: 'gatedmedia_items',
	emails: 'gatedmedia_allowed_email',
};

const ENDPOINTS = {
	group: 'gatedmedia_search_groups',
	post: 'gatedmedia_search_posts',
	file: 'gatedmedia_search_files',
};

/**
 * A stored `type:id` as a person reads it, given the labels learned this
 * session — rows loaded from meta show their identifier, which is honest:
 * the id is the stored fact.
 *
 * @param {string} row    The stored row.
 * @param {Object} labels Labels for rows added this session.
 * @return {string} The row's display text.
 */
function itemLabel( row, labels ) {
	const [ type, id ] = row.split( ':', 2 );
	const kind = type.charAt( 0 ).toUpperCase() + type.slice( 1 );

	return `${ kind }: ${ labels[ row ] || id }`;
}

export default function Edit() {
	const postType = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const shop = window.gatedmediaProduct || {
		currency: 'GBP',
		digits: 2,
		nonce: '',
	};

	const [ itemType, setItemType ] = useState( 'group' );
	const [ pending, setPending ] = useState( null );
	const [ options, setOptions ] = useState( [] );
	const [ labels, setLabels ] = useState( {} );

	const blockProps = useBlockProps( {
		className: 'gatedmedia-product-details',
	} );

	const items = meta[ META.items ] || [];
	const set = ( key, value ) => setMeta( { ...meta, [ key ]: value } );

	const search = ( term ) => {
		if ( ! term || term.length < 2 ) {
			setOptions( [] );
			return;
		}

		window
			.fetch(
				`${ window.ajaxurl }?action=${
					ENDPOINTS[ itemType ]
				}&_ajax_nonce=${ shop.nonce }&term=${ encodeURIComponent(
					term
				) }`
			)
			.then( ( response ) => response.json() )
			.then( ( results ) =>
				setOptions(
					results.map( ( result ) => ( {
						value: String( result.id ),
						label: result.label,
					} ) )
				)
			);
	};

	const addItem = () => {
		if ( ! pending ) {
			return;
		}

		const row = `${ itemType }:${ pending }`;
		const chosen = options.find( ( option ) => option.value === pending );

		if ( ! items.includes( row ) ) {
			set( META.items, [ ...items, row ] );

			if ( chosen ) {
				setLabels( { ...labels, [ row ]: chosen.label } );
			}
		}

		setPending( null );
		setOptions( [] );
	};

	return (
		<div { ...blockProps }>
			<Card>
				<CardHeader>
					<Flex>
						<FlexBlock>
							<strong>
								{ __( 'Product', 'gated-media-access' ) }
							</strong>
						</FlexBlock>
						<FlexItem>
							{ formatMinor(
								meta[ META.price ] || 0,
								shop.currency
							) }
						</FlexItem>
					</Flex>
				</CardHeader>
				<CardBody>
					{ meta[ META.uuid ] ? (
						<p className="components-base-control__help">
							{ sprintf(
								/* translators: %s: the product's UUID. */
								__(
									'Link identity: %s — its only public URL.',
									'gated-media-access'
								),
								meta[ META.uuid ]
							) }
						</p>
					) : (
						<p className="components-base-control__help">
							{ __(
								'The link identity is minted on first save.',
								'gated-media-access'
							) }
						</p>
					) }

					<MoneyControl
						label={ sprintf(
							/* translators: %s: the shop currency code. */
							__( 'Price (%s)', 'gated-media-access' ),
							shop.currency
						) }
						value={ meta[ META.price ] || 0 }
						currency={ shop.currency }
						onChange={ ( minor ) => set( META.price, minor ) }
						help={ __(
							'0 is a free product. The currency is the shop’s, set on the Settings screen.',
							'gated-media-access'
						) }
					/>

					<TextControl
						type="number"
						min="1"
						step="1"
						label={ __( 'Duration (days)', 'gated-media-access' ) }
						value={ meta[ META.duration ] || '' }
						onChange={ ( value ) => set( META.duration, value ) }
						help={ __(
							'Days of access a purchase grants. Empty for lifetime.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>

					<SelectControl
						label={ __( 'Visibility', 'gated-media-access' ) }
						value={ meta[ META.visibility ] || 'listed' }
						options={ [
							{
								value: 'listed',
								label: __( 'Listed', 'gated-media-access' ),
							},
							{
								value: 'unlisted',
								label: __(
									'Unlisted — direct link only',
									'gated-media-access'
								),
							},
						] }
						onChange={ ( value ) => set( META.visibility, value ) }
						__nextHasNoMarginBottom
					/>

					<hr />

					<strong>{ __( 'Items', 'gated-media-access' ) }</strong>
					<p className="components-base-control__help">
						{ __(
							'What buying this product gives access to. A group covers whatever it contains, now and later.',
							'gated-media-access'
						) }
					</p>

					{ items.length > 0 && (
						<ul>
							{ items.map( ( row ) => (
								<li key={ row }>
									<Flex>
										<FlexBlock>
											{ itemLabel( row, labels ) }
										</FlexBlock>
										<FlexItem>
											<Button
												isDestructive
												variant="link"
												onClick={ () =>
													set(
														META.items,
														items.filter(
															( kept ) =>
																kept !== row
														)
													)
												}
											>
												{ __(
													'Remove',
													'gated-media-access'
												) }
											</Button>
										</FlexItem>
									</Flex>
								</li>
							) ) }
						</ul>
					) }

					<Flex align="flex-end">
						<FlexItem>
							<SelectControl
								label={ __( 'Type', 'gated-media-access' ) }
								value={ itemType }
								options={ [
									{
										value: 'group',
										label: __(
											'Group',
											'gated-media-access'
										),
									},
									{
										value: 'post',
										label: __(
											'Post',
											'gated-media-access'
										),
									},
									{
										value: 'file',
										label: __(
											'File',
											'gated-media-access'
										),
									},
								] }
								onChange={ ( value ) => {
									setItemType( value );
									setPending( null );
									setOptions( [] );
								} }
								__nextHasNoMarginBottom
							/>
						</FlexItem>
						<FlexBlock>
							<ComboboxControl
								label={ __( 'Search', 'gated-media-access' ) }
								value={ pending }
								options={ options }
								onChange={ setPending }
								onFilterValueChange={ search }
								__nextHasNoMarginBottom
							/>
						</FlexBlock>
						<FlexItem>
							<Button variant="secondary" onClick={ addItem }>
								{ __( 'Add item', 'gated-media-access' ) }
							</Button>
						</FlexItem>
					</Flex>

					<hr />

					<TextareaControl
						label={ __(
							'Email allow-list',
							'gated-media-access'
						) }
						value={ ( meta[ META.emails ] || [] ).join( '\n' ) }
						onChange={ ( value ) =>
							set(
								META.emails,
								value
									.split( '\n' )
									.map( ( line ) => line.trim() )
									.filter( ( line ) => '' !== line )
							)
						}
						help={ __(
							'One address per line. Leave empty to let anyone buy. Who may buy and how it is found (visibility) are separate questions.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</CardBody>
			</Card>
		</div>
	);
}
