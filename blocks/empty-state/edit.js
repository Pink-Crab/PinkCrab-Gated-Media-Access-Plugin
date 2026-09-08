/**
 * The empty state, editor side.
 *
 * Both lines are typed in the box. The second is not optional, and the placeholder says what it is for: it always states what would put something here, so the box is never a dead end.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { IconControl } from '../../assets/js/editor/controls';

export default function Edit( { attributes, setAttributes } ) {
	const { icon, title, message } = attributes;
	const blockProps = useBlockProps( { className: 'gatedmedia-empty-state' } );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Icon', 'gated-media-access' ) }>
					<IconControl
						value={ icon }
						onChange={ ( value ) =>
							setAttributes( { icon: value } )
						}
						optional={ false }
						help={ __(
							'Shown large and muted above the headline.',
							'gated-media-access'
						) }
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Where this goes', 'gated-media-access' ) }
					initialOpen={ false }
				>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'One per view, only when the whole view is empty. An empty section is simply not rendered, because three of these down a page reads as three failures rather than one empty account.',
							'gated-media-access'
						) }
					</Notice>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<svg
					className="gatedmedia-empty-state__icon"
					aria-hidden="true"
				>
					<use href={ `#${ icon || 'i-empty' }` } />
				</svg>

				<RichText
					tagName="p"
					className="gatedmedia-empty-state__title"
					value={ title }
					onChange={ ( value ) => setAttributes( { title: value } ) }
					placeholder={ __( 'No files yet', 'gated-media-access' ) }
					allowedFormats={ [] }
				/>

				<RichText
					tagName="p"
					className="gatedmedia-text gatedmedia-text--meta"
					value={ message }
					onChange={ ( value ) =>
						setAttributes( { message: value } )
					}
					placeholder={ __(
						'Say what would put something here. This must never be a dead end.',
						'gated-media-access'
					) }
					allowedFormats={ [ 'core/link' ] }
				/>
			</div>
		</>
	);
}
