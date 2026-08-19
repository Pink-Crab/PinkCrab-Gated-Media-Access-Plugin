/**
 * §6.16 Summary — editor.
 *
 * The line is typed in place. When a page supplies counts instead, the panel
 * says so and what you type overrides them — pluralisation happens on the
 * server either way, so "1 file" is never "1 files".
 */

import {
	useBlockProps,
	InspectorControls,
	RichText,
} from '@wordpress/block-editor';
import { PanelBody, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function Edit( { attributes, setAttributes } ) {
	const { text, counts } = attributes;
	const hasCounts = Array.isArray( counts ) && counts.length > 0;

	const blockProps = useBlockProps( { className: 'gatedmedia-summary' } );

	return (
		<>
			{ hasCounts && (
				<InspectorControls>
					<PanelBody title={ __( 'Counts', 'gated-media-access' ) }>
						<Notice status="info" isDismissible={ false }>
							{ '' === text
								? sprintf(
										/* translators: %d: how many kinds of thing are counted. */
										__(
											'Phrasing %d counts supplied by the page. Type here to override them.',
											'gated-media-access'
										),
										counts.length
								  )
								: __(
										'Counts were supplied by the page, but your wording is being used instead.',
										'gated-media-access'
								  ) }
						</Notice>
					</PanelBody>
				</InspectorControls>
			) }

			<RichText
				{ ...blockProps }
				tagName="p"
				value={ text }
				onChange={ ( value ) => setAttributes( { text: value } ) }
				placeholder={
					hasCounts
						? __(
								'Counts supplied by the page',
								'gated-media-access'
						  )
						: __( '12 files, 1 post', 'gated-media-access' )
				}
				allowedFormats={ [] }
			/>
		</>
	);
}
