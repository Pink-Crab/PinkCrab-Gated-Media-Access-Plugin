/**
 * Editor registration for the My Access block.
 *
 * The block draws itself on the server — what a person can see is decided by
 * the resolver, and the editor has no business reimplementing that. So the
 * editor asks the server for the same markup the front end gets.
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

	// Dynamic: nothing is stored in post content but the block comment itself.
	save() {
		return null;
	},
} );
