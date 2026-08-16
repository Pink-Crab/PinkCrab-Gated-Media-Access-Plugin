/**
 * §6.5 Expiry — editor.
 *
 * The wording is typed on the chip. The state is in the toolbar because it is
 * what you flip between while looking at it, and it changes the icon and the
 * colour together — "expiring soon" is the only one that goes red, "expired"
 * the only one that dims.
 *
 * Drawn client-side: an icon and a span.
 */

import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToggleControl,
	ToolbarGroup,
	ToolbarDropdownMenu,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const STATES = [
	{
		value: 'lifetime',
		title: __( 'Lifetime', 'gated-media-access' ),
		icon: 'infinity',
		symbol: 'i-infinity',
		modifier: '',
	},
	{
		value: 'dated',
		title: __( 'Runs out on a date', 'gated-media-access' ),
		icon: 'calendar-alt',
		symbol: 'i-calendar',
		modifier: '',
	},
	{
		value: 'soon',
		title: __( 'Expiring soon', 'gated-media-access' ),
		icon: 'warning',
		symbol: 'i-warning',
		modifier: 'gatedmedia-expiry--warning',
	},
	{
		value: 'expired',
		title: __( 'Already expired', 'gated-media-access' ),
		icon: 'hidden',
		symbol: 'i-blocked',
		modifier: 'gatedmedia-expiry--expired',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { state, label, chip } = attributes;
	const current =
		STATES.find( ( option ) => option.value === state ) || STATES[ 0 ];

	// Block-level host with the inline expiry inside, matching render.php.
	const blockProps = useBlockProps( { className: 'gatedmedia-inline-host' } );

	const expiryClass = [
		'gatedmedia-expiry',
		current.modifier,
		chip ? 'gatedmedia-expiry--chip' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Expiry state', 'gated-media-access' ) }
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
				<PanelBody title={ __( 'Appearance', 'gated-media-access' ) }>
					<ToggleControl
						label={ __( 'Draw as a chip', 'gated-media-access' ) }
						checked={ !! chip }
						onChange={ ( value ) =>
							setAttributes( { chip: value } )
						}
						help={ __(
							'Filled background. For detail panels — a list is already busy with rules and buttons, so leave it off there.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<span className={ expiryClass }>
					<svg
						className="gatedmedia-icon gatedmedia-icon--small"
						aria-hidden="true"
					>
						<use href={ `#${ current.symbol }` } />
					</svg>
					<RichText
						tagName="span"
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						placeholder={ __(
							'Expires 12 March 2027',
							'gated-media-access'
						) }
						allowedFormats={ [] }
					/>
				</span>
			</div>
		</>
	);
}
