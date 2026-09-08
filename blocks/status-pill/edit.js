/**
 * The status pill, editor side.
 *
 * Five values, each meaning something specific, so the toolbar names them and the panel says what the current one means rather than listing five words.
 *
 * The wording is typed on the pill: left as the default it follows the value, overtyped it does not, which is the whole of the label override and needs no sidebar field.
 */

import {
	useBlockProps,
	InspectorControls,
	BlockControls,
	RichText,
} from '@wordpress/block-editor';
import {
	PanelBody,
	ToolbarGroup,
	ToolbarDropdownMenu,
	Notice,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const VALUES = [
	{
		value: 'complete',
		title: __( 'Complete', 'gated-media-access' ),
		icon: 'yes',
		symbol: 'i-check',
		help: __( 'The order went through.', 'gated-media-access' ),
	},
	{
		value: 'refunded',
		title: __( 'Refunded', 'gated-media-access' ),
		icon: 'undo',
		symbol: 'i-refund',
		help: __(
			'Money returned. Revokes the access it created.',
			'gated-media-access'
		),
	},
	{
		value: 'active',
		title: __( 'Active', 'gated-media-access' ),
		icon: 'marker',
		symbol: 'i-active',
		help: __(
			'Access from this order is currently usable.',
			'gated-media-access'
		),
	},
	{
		value: 'expired',
		title: __( 'Expired', 'gated-media-access' ),
		icon: 'clock',
		symbol: 'i-blocked',
		help: __( 'Its duration ran out.', 'gated-media-access' ),
	},
	{
		value: 'revoked',
		title: __( 'Revoked', 'gated-media-access' ),
		icon: 'lock',
		symbol: 'i-revoked',
		help: __( 'Withdrawn by an administrator.', 'gated-media-access' ),
	},
	{
		value: 'pending',
		title: __( 'Pending', 'gated-media-access' ),
		icon: 'clock',
		symbol: 'i-clock',
		help: __(
			'Payment started, and Stripe has not confirmed it yet.',
			'gated-media-access'
		),
	},
	{
		value: 'failed',
		title: __( 'Failed', 'gated-media-access' ),
		icon: 'warning',
		symbol: 'i-error',
		help: __( 'The payment did not go through.', 'gated-media-access' ),
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { value, label } = attributes;
	const current =
		VALUES.find( ( option ) => option.value === value ) || VALUES[ 2 ];
	const dims = 'refunded' === value || 'revoked' === value;

	// Block-level host with the inline pill inside, matching render.php.
	const blockProps = useBlockProps( {
		className: 'gatedmedia-inline-host',
	} );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Status', 'gated-media-access' ) }
						text={ current.title }
						controls={ VALUES.map( ( option ) => ( {
							title: option.title,
							icon: option.icon,
							isActive: option.value === value,
							onClick: () =>
								setAttributes( { value: option.value } ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody
					title={ __( 'What this means', 'gated-media-access' ) }
				>
					<Notice status="info" isDismissible={ false }>
						{ current.help }
					</Notice>
					{ dims && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'A refunded or revoked row is also dimmed as a whole. The row does that, not the pill.',
								'gated-media-access'
							) }
						</Notice>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<span className="gatedmedia-status-pill">
					<svg
						className="gatedmedia-icon gatedmedia-icon--small"
						aria-hidden="true"
					>
						<use href={ `#${ current.symbol }` } />
					</svg>
					<RichText
						tagName="span"
						value={ label }
						onChange={ ( next ) =>
							setAttributes( { label: next } )
						}
						placeholder={ current.title }
						allowedFormats={ [] }
					/>
				</span>
			</div>
		</>
	);
}
