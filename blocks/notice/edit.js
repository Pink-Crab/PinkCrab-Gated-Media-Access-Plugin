/**
 * §6.4 Notice — editor.
 *
 * The message is typed in the notice, not in a sidebar box, and it takes
 * formatting because §6.4 says a link inside a notice is a text link — so the
 * body has to be able to hold one.
 *
 * The dismiss toggle carries a warning rather than sitting there innocently:
 * §7.5's forced-completion prompt is *defined* by being the notice you cannot
 * dismiss, so turning it on there breaks that view.
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
	Notice as NoticeUI,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { IconControl } from '../../assets/js/editor/controls';

const KINDS = [
	{
		value: 'info',
		title: __( 'Information', 'gated-media-access' ),
		icon: 'info-outline',
		symbol: 'i-info',
	},
	{
		value: 'error',
		title: __( 'Error', 'gated-media-access' ),
		icon: 'warning',
		symbol: 'i-error',
	},
	{
		value: 'success',
		title: __( 'Success', 'gated-media-access' ),
		icon: 'yes-alt',
		symbol: 'i-success',
	},
];

export default function Edit( { attributes, setAttributes } ) {
	const { kind, text, dismissible, icon } = attributes;
	const current =
		KINDS.find( ( option ) => option.value === kind ) || KINDS[ 0 ];

	const blockProps = useBlockProps( {
		className: [ 'gatedmedia-notice', `gatedmedia-notice--${ kind }` ]
			.filter( ( name ) => 'gatedmedia-notice--info' !== name )
			.join( ' ' ),
	} );

	return (
		<>
			<BlockControls>
				<ToolbarGroup>
					<ToolbarDropdownMenu
						icon={ current.icon }
						label={ __( 'Notice kind', 'gated-media-access' ) }
						text={ current.title }
						controls={ KINDS.map( ( option ) => ( {
							title: option.title,
							icon: option.icon,
							isActive: option.value === kind,
							onClick: () =>
								setAttributes( { kind: option.value } ),
						} ) ) }
					/>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Behaviour', 'gated-media-access' ) }>
					<ToggleControl
						label={ __( 'Can be dismissed', 'gated-media-access' ) }
						checked={ !! dismissible }
						onChange={ ( value ) =>
							setAttributes( { dismissible: value } )
						}
						help={ __(
							'Adds a close button.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
					{ dismissible && (
						<NoticeUI status="warning" isDismissible={ false }>
							{ __(
								'A prompt that blocks progress — asking someone to complete their profile — must not be dismissible.',
								'gated-media-access'
							) }
						</NoticeUI>
					) }
				</PanelBody>

				<PanelBody
					title={ __( 'Icon', 'gated-media-access' ) }
					initialOpen={ false }
				>
					<IconControl
						label={ __(
							'Override the icon',
							'gated-media-access'
						) }
						value={ icon }
						onChange={ ( value ) =>
							setAttributes( { icon: value } )
						}
						help={ __(
							'Each kind already picks its own. Only set this to override it.',
							'gated-media-access'
						) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<svg
					className="gatedmedia-icon gatedmedia-notice__icon"
					aria-hidden="true"
				>
					<use href={ `#${ icon || current.symbol }` } />
				</svg>

				<RichText
					tagName="div"
					className="gatedmedia-notice__body"
					value={ text }
					onChange={ ( value ) => setAttributes( { text: value } ) }
					placeholder={ __(
						'Say what happened.',
						'gated-media-access'
					) }
					allowedFormats={ [ 'core/link', 'core/bold' ] }
				/>

				{ dismissible && (
					<button
						type="button"
						className="gatedmedia-notice__dismiss"
						disabled
					>
						&times;
					</button>
				) }
			</div>
		</>
	);
}
