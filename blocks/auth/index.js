/**
 * Sign in, sign up and reset.
 *
 * Not placeable, because the state comes from the URL and this only means anything on the plugin's own auth route, and registered so the block exists server-side.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

const Edit = () => (
	<div { ...useBlockProps() }>
		{ __(
			'The sign-in view. Rendered on the plugin’s own auth route, where the URL decides which of its four states is shown.',
			'gated-media-access'
		) }
	</div>
);

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
