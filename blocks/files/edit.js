/**
 * Files.
 *
 * A section view. It draws the signed-in person's own record, so the editor shows exactly what the front end will, and there is nothing here to configure beyond the detail segment the account route supplies from the URL.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, Notice } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

export default function Edit( { attributes } ) {
	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'This section', 'gated-media-access' ) }>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Shows what the person viewing the page holds. Placed on your own page it renders the same as it does on the account route.',
							'gated-media-access'
						) }
					</Notice>
				</PanelBody>
			</InspectorControls>

			<div { ...useBlockProps() }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
				/>
			</div>
		</>
	);
}
