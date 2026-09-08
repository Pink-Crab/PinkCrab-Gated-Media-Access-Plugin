/**
 * End-to-end config.
 *
 * Runs against a real WordPress with the plugin active, defaulting to the wp-env development site, and WP_BASE_URL points it at devilbox or a staging site instead.
 *
 * Two projects rather than two suites, because the most important thing to prove about this interface is that it reflows correctly across the one breakpoint at 782px, and both run the same specs.
 */

const { defineConfig } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8931';

module.exports = defineConfig( {
	testDir: './tests/e2e',

	// Builds the component fixture page, without which the component specs pass only where that page happens to have been made by hand.
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),

	timeout: 30_000,
	expect: { timeout: 5_000 },

	// A failing assertion should be a failing assertion, not a flaky retry that hides it.
	retries: 0,
	fullyParallel: false,
	workers: 1,

	reporter: process.env.CI ? 'list' : [ [ 'list' ] ],

	use: {
		baseURL,
		ignoreHTTPSErrors: true,
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},

	projects: [
		{
			name: 'wide',
			use: { viewport: { width: 1280, height: 900 } },
		},
		{
			// Below the 782px breakpoint: the sidebar replaced by the tab strip, the type filter by chips.
			name: 'narrow',
			use: { viewport: { width: 480, height: 900 } },
		},
	],
} );
