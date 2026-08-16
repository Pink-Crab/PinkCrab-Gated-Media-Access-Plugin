/**
 * §6.9 Section heading — editor.
 *
 * Typed in place. The only thing that cannot be seen is the heading level,
 * because the size never changes with it — this is drawn small and uppercase
 * whether it is an h2 or an h6, so the level is about document structure alone.
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { text, level } = attributes;
	const tagName = `h${ Math.min( 6, Math.max( 2, level || 2 ) ) }`;

	const blockProps = useBlockProps( {
		className: 'gatedmedia-section-heading',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Structure', 'gated-media-access' ) }>
					<SelectControl
						label={ __( 'Heading level', 'gated-media-access' ) }
						value={ String( level ) }
						options={ [ 2, 3, 4, 5, 6 ].map( ( option ) => ( {
							label: `H${ option }`,
							value: String( option ),
						} ) ) }
						onChange={ ( value ) =>
							setAttributes( { level: parseInt( value, 10 ) } )
						}
						help={ __(
							'Document structure only — the size never changes. The page title is the h1, so these start at h2.',
							'gated-media-access'
						) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<RichText
				{ ...blockProps }
				tagName={ tagName }
				value={ text }
				onChange={ ( value ) => setAttributes( { text: value } ) }
				placeholder={ __(
					'Available downloads',
					'gated-media-access'
				) }
				allowedFormats={ [] }
			/>
		</>
	);
}
