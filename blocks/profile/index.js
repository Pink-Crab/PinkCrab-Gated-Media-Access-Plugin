/**
 * Editor registration for the Profile block.
 *
 * Server rendered because the form is populated from the signed-in user, which
 * the editor cannot know about.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import ServerSideRender from '@wordpress/server-side-render';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		return (
			<div { ...useBlockProps() }>
				<ServerSideRender block={ metadata.name } />
			</div>
		);
	},

	save() {
		return null;
	},
} );
