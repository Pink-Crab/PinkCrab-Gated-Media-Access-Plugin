/**
 * Product details — the product's whole form.
 *
 * Hidden from the inserter: the post type's template places it, locked, on
 * every product. It saves to meta over REST and writes nothing to content.
 */

import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
