/**
 * §6.15 Pinned action bar — editor.
 *
 * The label is typed on the button, price included — §6.15 puts the price in
 * the button's own label rather than beside it, because the bar holds one
 * control and nothing else.
 *
 * Drawn unpinned here. Pinning it in the editor canvas would stick it to the
 * bottom of the editor, which is not what it does on a page.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, TextControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { label, href } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'Where it goes', 'gated-media-access' ) }
				>
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Product pages only. It pins to the bottom of the screen below 782px, shows nothing above it, and must not be used on an account view.',
							'gated-media-access'
						) }
					</Notice>

					<TextControl
						label={ __( 'Links to', 'gated-media-access' ) }
						value={ href }
						onChange={ ( value ) =>
							setAttributes( { href: value } )
						}
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div className="gatedmedia-button gatedmedia-button--primary gatedmedia-button--full">
					<RichText
						tagName="span"
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
						placeholder={ __(
							'Get access — £49.00',
							'gated-media-access'
						) }
						allowedFormats={ [] }
					/>
				</div>
			</div>
		</>
	);
}
