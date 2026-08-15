/**
 * Editor registration for the Orders block.
 *
 * Server rendered — orders come from the payments table, which is not
 * reachable from WP_Query by design.
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
