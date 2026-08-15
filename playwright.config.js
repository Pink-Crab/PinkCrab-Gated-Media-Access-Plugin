/**
 * End-to-end config.
 *
 * Runs against a real WordPress with the plugin active. Defaults to the wp-env
 * development site; point WP_BASE_URL elsewhere to run it against devilbox or
 * a staging site instead.
 *
 * Two projects rather than two suites, because the single most important thing
 * to prove about this interface is that it reflows correctly across the one
 * breakpoint — 782px, ui-spec.md §3. Both run the same specs.
 */

const { defineConfig } = require( '@playwright/test' );

const baseURL = process.env.WP_BASE_URL || 'http://localhost:8931';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 30_000,
	expect: { timeout: 5_000 },

	// A failing assertion should be a failing assertion, not a flaky retry that
	// hides it.
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
			// Below the 782px breakpoint: sidebar replaced by the tab strip,
			// the type filter replaced by chips.
			name: 'narrow',
			use: { viewport: { width: 480, height: 900 } },
		},
	],
} );
