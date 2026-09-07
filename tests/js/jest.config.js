/**
 * Unit tests for the editor's own logic.
 *
 * Only the pieces that can be reasoned about without a browser live here —
 * the item-type vocabulary and the picker's request handling. Anything that
 * needs a real screen is a Playwright spec instead.
 */

const path = require( 'path' );

const root = path.resolve( __dirname, '../..' );

module.exports = {
	preset: '@wordpress/jest-preset-default',
	rootDir: root,
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	// The e2e specs are Playwright's, and its `test` is not Jest's.
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/', '/tests/e2e/' ],
};
