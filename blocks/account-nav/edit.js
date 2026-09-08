/**
 * The account nav, editor side.
 *
 * Drawn client-side from whatever items it has, so you can see which variant you have placed. The items themselves are the one thing genuinely not editable: they come from `gatedmedia_account_sections`, so a section added by another plugin appears without anyone touching this block, and typing them by hand would produce navigation that disagrees with its routes.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	SelectControl,
	Notice,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { variant, label, items } = attributes;
	const list = Array.isArray( items ) ? items : [];
	const tabs = 'tabs' === variant;
	const base = tabs ? 'gatedmedia-tab-strip' : 'gatedmedia-account-nav';

	const blockProps = useBlockProps( { className: base } );

	// Nothing is supplied in the editor, so the real sections stand in as a preview of the shape rather than an empty box.
	const preview =
		list.length > 0
			? list
			: [
					{
						label: __( 'My Access', 'gated-media-access' ),
						icon: 'i-access',
						active: true,
					},
					{
						label: __( 'Files', 'gated-media-access' ),
						icon: 'i-files',
					},
					{
						label: __( 'Orders', 'gated-media-access' ),
						icon: 'i-orders',
					},
					{
						label: __( 'Profile', 'gated-media-access' ),
						icon: 'i-profile',
					},
			  ];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Navigation', 'gated-media-access' ) }>
					<SelectControl
						label={ __(
							'Which width this is for',
							'gated-media-access'
						) }
						value={ variant }
						options={ [
							{
								label: __(
									'Sidebar, 782px and above',
									'gated-media-access'
								),
								value: 'sidebar',
							},
							{
								label: __(
									'Tab strip, below 782px',
									'gated-media-access'
								),
								value: 'tabs',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { variant: value } )
						}
						help={ __(
							'The sidebar is replaced by the tab strip, not collapsed into a menu. Place both; CSS shows the right one.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Read aloud as', 'gated-media-access' ) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						placeholder={ __( 'Account', 'gated-media-access' ) }
						help={ __(
							'Give the two variants different names so they are distinguishable to a screen reader.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>

				<PanelBody title={ __( 'Items', 'gated-media-access' ) }>
					<Notice status="info" isDismissible={ false }>
						{ 0 === list.length
							? __(
									'Shown here as a preview. On the page these come from the account section list, so a plugin adding a section appears automatically.',
									'gated-media-access'
							  )
							: sprintf(
									/* translators: %d: how many nav items were supplied. */
									__(
										'%d items supplied by the page.',
										'gated-media-access'
									),
									list.length
							  ) }
					</Notice>
				</PanelBody>
			</InspectorControls>

			<nav { ...blockProps }>
				{ preview.map( ( item, index ) => (
					<span
						key={ index }
						className={ `${ base }__item${
							item.active ? ' is-active' : ''
						}` }
					>
						{ item.icon && (
							<svg
								className={ `gatedmedia-icon${
									tabs ? ' gatedmedia-icon--small' : ''
								}` }
								aria-hidden="true"
							>
								<use href={ `#${ item.icon }` } />
							</svg>
						) }
						<span>{ item.label }</span>
					</span>
				) ) }
			</nav>
		</>
	);
}
