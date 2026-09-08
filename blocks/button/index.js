/**
 * Button.
 *
 * Hidden from the inserter, but a real block type on the client, because the editor has to know what it is wherever one appears.
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
