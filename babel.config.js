/**
 * Babel, for Jest only.
 *
 * The build does not read this: wp-scripts configures babel-loader itself.
 * `@wordpress/jest-preset-default` transforms through plain babel-jest, which
 * looks for a project config, so without this every `import` in a unit test is
 * a syntax error.
 */

module.exports = {
	presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
};
