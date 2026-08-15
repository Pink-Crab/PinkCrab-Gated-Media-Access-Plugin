/**
 * Editor registration for the Files block.
 *
 * Server rendered for the same reason as My Access — what is downloadable is
 * the resolver's answer, not the editor's.
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
