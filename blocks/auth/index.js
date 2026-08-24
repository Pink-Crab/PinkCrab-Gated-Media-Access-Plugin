/**
 * Sign in / sign up / reset — ui-spec.md §7.7.
 *
 * Not placeable: the state comes from the URL, so this only means anything on
 * the plugin's own auth route. Registered so the block exists server-side.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: () => (
		<div { ...useBlockProps() }>
			{ __(
				'The sign-in view. Rendered on the plugin’s own auth route, where the URL decides which of its four states is shown.',
				'gated-media-access'
			) }
		</div>
	),
	save: () => null,
} );
