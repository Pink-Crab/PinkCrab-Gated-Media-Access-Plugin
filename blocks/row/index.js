/**
 * Row — ui-spec.md §6.2.
 *
 * Hidden from the inserter, but a real block type on the client — the editor
 * has to know what it is wherever one appears.
 *
 * `save` returns the inner blocks rather than null. The row itself is drawn by
 * PHP, but its children are authored content and have to be written into the
 * post — with null, the aside would be empty again on every reload.
 */

import { registerBlockType } from '@wordpress/blocks';
import { InnerBlocks } from '@wordpress/block-editor';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => <InnerBlocks.Content />,
} );
