/**
 * The button block, editor side.
 *
 * The label is typed on the button. Everything else is what cannot be shown in place: where it goes, and, when it is a real button rather than a link, what pressing it does to the form around it.
 *
 * Drawn client-side, because a button is a span in a box and asking the server to render one on every keystroke would be a round trip for nothing.
 */

import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	ToggleControl,
	ToolbarGroup,
	ToolbarDropdownMenu,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { IconControl } from '../../assets/js/editor/controls';

const VARIANTS = [
	{
		value: 'primary',
		title: __( 'Primary', 'gated-media-access' ),
		icon: 'button',
	},
	{
		value: 'secondary',
		title: __( 'Secondary', 'gated-media-access' ),
		icon: 'marker',
	},
	{
		value: 'link',
		title: __( 'Text link', 'gated-media-access' ),
		icon: 'admin-links',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { label, href, variant, icon, full, type, name, value } = attributes;
	const isLink = 'link' === variant;
	const current =
		VARIANTS.find( ( option ) => option.value === variant ) ||
		VARIANTS[ 0 ];

	const className = isLink
		? 'gatedmedia-text-link'
		: [
				'gatedmedia-button',
				`gatedmedia-button--${ variant }`,
				full ? 'gatedmedia-button--full' : '',
		  ]
				.filter( Boolean )
				.join( ' ' );

	// Block-level wrapper, matching render.php, so a theme can centre it.
	const blockProps = useBlockProps( { className: 'gatedmedia-inline-host' } );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Style', 'gated-media-access' ) }
						text={ current.title }
						controls={ VARIANTS.map( ( option ) => ( {
							title: option.title,
							icon: option.icon,
							isActive: option.value === variant,
							onClick: () =>
								setAttributes( { variant: option.value } ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody
					title={ __( 'Where it goes', 'gated-media-access' ) }
				>
					<TextControl
						label={ __( 'Links to', 'gated-media-access' ) }
						value={ href }
						onChange={ ( next ) => setAttributes( { href: next } ) }
						help={
							'' !== href
								? __(
										'Renders as a link.',
										'gated-media-access'
								  )
								: __(
										'Empty renders a real button, for submitting a form rather than going somewhere.',
										'gated-media-access'
								  )
						}
						__nextHasNoMarginBottom
					/>

					<IconControl
						value={ icon }
						onChange={ ( next ) => setAttributes( { icon: next } ) }
						help={ __(
							'Sits before the text.',
							'gated-media-access'
						) }
					/>

					{ ! isLink && (
						<ToggleControl
							label={ __( 'Full width', 'gated-media-access' ) }
							checked={ !! full }
							onChange={ ( next ) =>
								setAttributes( { full: next } )
							}
							help={ __(
								'Buttons already go full width below 782px. This makes it full width everywhere.',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
					) }
				</PanelBody>

				{ '' === href && (
					<PanelBody
						title={ __( 'Form behaviour', 'gated-media-access' ) }
						initialOpen={ false }
					>
						<SelectControl
							label={ __(
								'What pressing it does',
								'gated-media-access'
							) }
							value={ type }
							options={ [
								{
									label: __(
										'Nothing on its own',
										'gated-media-access'
									),
									value: 'button',
								},
								{
									label: __(
										'Submits the form',
										'gated-media-access'
									),
									value: 'submit',
								},
								{
									label: __(
										'Resets the form',
										'gated-media-access'
									),
									value: 'reset',
								},
							] }
							onChange={ ( next ) =>
								setAttributes( { type: next } )
							}
							__nextHasNoMarginBottom
						/>
						<TextControl
							label={ __( 'Field name', 'gated-media-access' ) }
							value={ name }
							onChange={ ( next ) =>
								setAttributes( { name: next } )
							}
							help={ __(
								'Only needed when the form must know which button was pressed.',
								'gated-media-access'
							) }
							__nextHasNoMarginBottom
						/>
						{ '' !== name && (
							<TextControl
								label={ __(
									'Field value',
									'gated-media-access'
								) }
								value={ value }
								onChange={ ( next ) =>
									setAttributes( { value: next } )
								}
								__nextHasNoMarginBottom
							/>
						) }
					</PanelBody>
				) }
			</InspectorControls>

			<div { ...blockProps }>
				<span className={ className }>
					{ '' !== icon && (
						<svg
							className="gatedmedia-icon gatedmedia-icon--small"
							aria-hidden="true"
						>
							<use href={ `#${ icon }` } />
						</svg>
					) }
					<RichText
						tagName="span"
						value={ label }
						onChange={ ( next ) =>
							setAttributes( { label: next } )
						}
						placeholder={ __(
							'Button text',
							'gated-media-access'
						) }
						allowedFormats={ [] }
					/>
				</span>
			</div>
		</>
	);
}
