/**
 * Build config.
 *
 * Three source trees, three destinations:
 *
 *   blocks/<name>/index.js  → build/blocks/<name>/index.js
 *   assets/js/*.js          → build/js/*.js
 *   assets/scss/*.scss      → build/css/*.css
 *
 * Everything else — JSX, SCSS, the `.asset.php` dependency files, RTL — comes
 * from the @wordpress/scripts default and is not restated here.
 */

const path = require( 'path' );
const fs = require( 'fs' );

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const CopyPlugin = require( 'copy-webpack-plugin' );
const RemoveEmptyScriptsPlugin = require( 'webpack-remove-empty-scripts' );

const root = __dirname;
const blocksDir = path.join( root, 'blocks' );

/**
 * Every JS entry inside blocks/, discovered rather than listed.
 *
 * A block is a directory holding a block.json. `index.js` is its editor
 * script, `view.js` its optional front-end script. Adding a block means adding
 * the directory — this file does not change.
 *
 * @return {Object} Entry name to absolute path.
 */
function blockEntries() {
	if ( ! fs.existsSync( blocksDir ) ) {
		return {};
	}

	return fs
		.readdirSync( blocksDir, { withFileTypes: true } )
		.filter( ( entry ) => entry.isDirectory() )
		.filter( ( entry ) =>
			fs.existsSync( path.join( blocksDir, entry.name, 'block.json' ) )
		)
		.reduce( ( entries, entry ) => {
			[ 'index', 'view' ].forEach( ( file ) => {
				const source = path.join( blocksDir, entry.name, `${ file }.js` );

				if ( fs.existsSync( source ) ) {
					entries[ `blocks/${ entry.name }/${ file }` ] = source;
				}
			} );

			return entries;
		}, {} );
}

/**
 * The standalone bundles, for the two places that are not a block: the
 * plugin's own account route, and wp-admin.
 *
 * Shared code lives in assets/js/shared and assets/scss/shared and is pulled
 * into both — there is no third bundle to coordinate at runtime.
 */
const assetEntries = {
	'js/front': path.join( root, 'assets/js/front.js' ),
	'js/admin': path.join( root, 'assets/js/admin.js' ),
	'js/editor': path.join( root, 'assets/js/editor/index.js' ),
	'css/front': path.join( root, 'assets/scss/front.scss' ),
	'css/admin': path.join( root, 'assets/scss/admin.scss' ),
};

module.exports = {
	...defaultConfig,

	entry: () => ( {
		...blockEntries(),
		...assetEntries,
	} ),

	plugins: [
		// The default CopyPlugin reads from `src`, which here is the PSR-4 PHP
		// tree — it would copy every class into build/. Point it at blocks/.
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'CopyPlugin'
		),

		new CopyPlugin( {
			patterns: [
				{
					from: '**/block.json',
					context: 'blocks',
					to: 'blocks/[path][name][ext]',
					noErrorOnMissing: true,
				},
				{
					from: '**/*.php',
					context: 'blocks',
					to: 'blocks/[path][name][ext]',
					noErrorOnMissing: true,
				},
			],
		} ),

		// A SCSS entry still emits a JS file holding nothing. Drop them.
		new RemoveEmptyScriptsPlugin( {
			stage: RemoveEmptyScriptsPlugin.STAGE_AFTER_PROCESS_PLUGINS,
		} ),
	],
};
