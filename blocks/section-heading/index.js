/**
 * Section heading — ui-spec.md §6.9.
 *
 * Hidden from the inserter, but a real block type on the client — the editor
 * has to know what it is wherever one appears.
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
