/**
 * §6.2 Row — editor.
 *
 * Edited where it is drawn: the title and second line are typed on the row
 * itself, and the aside takes real child blocks — an expiry, a status pill, a
 * price, a button, or several. That is what "inner blocks" has to mean in the
 * editor, not just in PHP.
 *
 * Only what cannot be shown in place is in the sidebar: the link behind the
 * title, the narrow-screen behaviour, and the button the row grows below
 * 782px, which by definition is not visible at the width you are editing at.
 */

import {
	useBlockProps,
	useInnerBlocksProps,
	InspectorControls,
	BlockControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToolbarGroup,
	ToolbarDropdownMenu,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { IconControl } from '../../assets/js/editor/controls';

const STATES = [
	{
		value: 'normal',
		title: __( 'Normal', 'gated-media-access' ),
		icon: 'marker',
	},
	{
		value: 'unavailable',
		title: __( 'Unavailable', 'gated-media-access' ),
		icon: 'hidden',
	},
	{
		value: 'loading',
		title: __( 'Loading', 'gated-media-access' ),
		icon: 'update',
	},
];

/**
 * What a row's aside usually holds. Offered as the starting point rather than
 * an empty slot, because an empty aside looks broken rather than deliberate.
 */
const ASIDE_ALLOWED = [
	'gated-media-access/expiry',
	'gated-media-access/status-pill',
	'gated-media-access/price',
	'gated-media-access/button',
];

export default function Edit( { attributes, setAttributes } ) {
	const {
		title,
		meta,
		href,
		state,
		variant,
		actionLabel,
		actionHref,
		actionIcon,
		unavailableLabel,
	} = attributes;

	const isLoading = 'loading' === state;
	const isUnavailable = 'unavailable' === state;
	const current =
		STATES.find( ( option ) => option.value === state ) || STATES[ 0 ];

	const blockProps = useBlockProps( {
		className: [
			'gatedmedia-row',
			'order' === variant ? 'gatedmedia-row--order' : '',
			isUnavailable ? 'is-unavailable' : '',
			isLoading ? 'is-loading' : '',
		]
			.filter( Boolean )
			.join( ' ' ),
	} );

	const innerBlocksProps = useInnerBlocksProps(
		{ className: 'gatedmedia-row__aside' },
		{
			allowedBlocks: ASIDE_ALLOWED,
			template: [
				[
					'gated-media-access/expiry',
					{ state: 'lifetime', label: 'Lifetime' },
				],
			],
			templateLock: false,
			orientation: 'horizontal',
		}
	);

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Row state', 'gated-media-access' ) }
						text={ current.title }
						controls={ STATES.map( ( option ) => ( {
							title: option.title,
							icon: option.icon,
							isActive: option.value === state,
							onClick: () =>
								setAttributes( { state: option.value } ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Link', 'gated-media-access' ) }>
					<TextControl
						label={ __( 'Title links to', 'gated-media-access' ) }
						value={ href }
						onChange={ ( value ) =>
							setAttributes( { href: value } )
						}
						help={ __(
							'Orders use this to reach the order. Leave empty for plain text.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Below 782px', 'gated-media-access' ) }>
					<SelectControl
						label={ __( 'How it reflows', 'gated-media-access' ) }
						value={ variant }
						options={ [
							{
								label: __(
									'Stacks (files, posts, groups)',
									'gated-media-access'
								),
								value: 'default',
							},
							{
								label: __(
									'Stays side by side (orders)',
									'gated-media-access'
								),
								value: 'order',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { variant: value } )
						}
						__nextHasNoMarginBottom
					/>

					{ ! isLoading && ! isUnavailable && (
						<>
							<Notice status="info" isDismissible={ false }>
								{ __(
									'Stacked rows grow a full-width button. You cannot see it at this width, which is why it is here.',
									'gated-media-access'
								) }
							</Notice>
							<TextControl
								label={ __(
									'Button text',
									'gated-media-access'
								) }
								value={ actionLabel }
								onChange={ ( value ) =>
									setAttributes( { actionLabel: value } )
								}
								help={ __(
									'Empty for no button.',
									'gated-media-access'
								) }
								__nextHasNoMarginBottom
							/>
							{ '' !== actionLabel && (
								<>
									<TextControl
										label={ __(
											'Button links to',
											'gated-media-access'
										) }
										value={ actionHref }
										onChange={ ( value ) =>
											setAttributes( {
												actionHref: value,
											} )
										}
										__nextHasNoMarginBottom
									/>
									<IconControl
										label={ __(
											'Button icon',
											'gated-media-access'
										) }
										value={ actionIcon }
										onChange={ ( value ) =>
											setAttributes( {
												actionIcon: value,
											} )
										}
									/>
								</>
							) }
						</>
					) }
				</PanelBody>

				{ isUnavailable && (
					<PanelBody
						title={ __( 'Unavailable', 'gated-media-access' ) }
					>
						<TextControl
							label={ __(
								'What the right-hand side says',
								'gated-media-access'
							) }
							value={ unavailableLabel }
							onChange={ ( value ) =>
								setAttributes( { unavailableLabel: value } )
							}
							placeholder={ __(
								'No longer available',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				{ isLoading ? (
					<>
						<div className="gatedmedia-row__main">
							<div className="gatedmedia-skeleton gatedmedia-skeleton--title" />
							<div className="gatedmedia-skeleton gatedmedia-skeleton--meta" />
						</div>
						<div className="gatedmedia-row__aside">
							<div className="gatedmedia-skeleton gatedmedia-skeleton--action" />
						</div>
					</>
				) : (
					<>
						<div className="gatedmedia-row__main">
							<RichText
								tagName="p"
								className="gatedmedia-row__title"
								value={ title }
								onChange={ ( value ) =>
									setAttributes( { title: value } )
								}
								placeholder={ __(
									'Annual report.pdf',
									'gated-media-access'
								) }
								allowedFormats={ [] }
							/>
							<RichText
								tagName="p"
								className="gatedmedia-row__meta"
								value={ meta }
								onChange={ ( value ) =>
									setAttributes( { meta: value } )
								}
								placeholder={ __(
									'PDF · 4.2 MB',
									'gated-media-access'
								) }
								allowedFormats={ [] }
							/>
						</div>

						{ isUnavailable ? (
							<div className="gatedmedia-row__aside">
								<span className="gatedmedia-text gatedmedia-text--meta">
									{ unavailableLabel ||
										__(
											'No longer available',
											'gated-media-access'
										) }
								</span>
							</div>
						) : (
							<div { ...innerBlocksProps } />
						) }
					</>
				) }
			</div>
		</>
	);
}
