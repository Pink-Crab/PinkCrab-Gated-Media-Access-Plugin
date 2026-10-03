/**
 * Buying again, end to end, as the admin who already holds the repurchase fixture's product.
 *
 * Each spec sets the product's buy-again setting itself, and the setting goes back to the default after, because the narrow project runs the same specs straight after.
 */

const { spawnSync } = require( 'node:child_process' );
const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USER || 'admin';
const PASSWORD = process.env.WP_PASSWORD || 'password';

// A £9.00 product granting one post the admin holds for good, from the repurchase fixture.
const URL = process.env.GATEDMEDIA_REPURCHASE_URL;
const ID = process.env.GATEDMEDIA_REPURCHASE_ID;

/**
 * Sets the product's buy-again setting, '' for the default.
 *
 * @param {string} mode lapsed, always, never, or ''.
 */
function setMode( mode ) {
	const command =
		'' === mode
			? [ 'delete', ID, 'gatedmedia_repurchase' ]
			: [ 'update', ID, 'gatedmedia_repurchase', mode ];

	spawnSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', 'post', 'meta', ...command ],
		{
			encoding: 'utf8',
		}
	);
}

/**
 * Signs in through the ordinary login form.
 *
 * @param {Object}                          fixtures      Playwright's fixtures.
 * @param {import('@playwright/test').Page} fixtures.page The page.
 */
async function signIn( { page } ) {
	await page.goto( '/wp-login.php' );
	// Core focuses and selects a field 200ms in, which lands a fill in the wrong box.
	await page.waitForFunction( () => {
		const field = document.getElementById( 'user_login' );
		return field && field.ownerDocument.activeElement === field;
	} );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.describe( 'buying again', () => {
	test.skip( ! URL, 'The repurchase fixture did not run.' );

	test.beforeEach( signIn );

	test.afterEach( () => {
		setMode( '' );
	} );

	test( 'by default, a holder is told they have it, with no way to buy', async ( {
		page,
	} ) => {
		setMode( '' );
		await page.goto( URL );

		await expect(
			page.getByText( 'You already have this.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /Get access/ } )
		).toHaveCount( 0 );
	} );

	test( 'set to never, a holder sees a disabled Access granted button', async ( {
		page,
	} ) => {
		setMode( 'never' );
		await page.goto( URL );

		const granted = page.getByRole( 'button', { name: 'Access granted' } );

		await expect( granted ).toBeVisible();
		await expect( granted ).toBeDisabled();
		await expect(
			page.getByRole( 'link', { name: 'View your access' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: /Get access/ } )
		).toHaveCount( 0 );

		// It must not look pressable.
		expect(
			await granted.evaluate(
				( el ) =>
					el.ownerDocument.defaultView.getComputedStyle( el ).cursor
			)
		).toBe( 'not-allowed' );
	} );

	test( 'set to any time, a holder can buy it again', async ( { page } ) => {
		setMode( 'always' );
		await page.goto( URL );

		const buy = page.getByRole( 'button', {
			name: 'Get access',
			exact: true,
		} );

		await expect( buy ).toBeVisible();
		await expect( buy ).toBeEnabled();
	} );

	test( 'a buy form posted once the product turned once-only is refused', async ( {
		page,
	} ) => {
		// The form is drawn while buying again is allowed, then the setting changes under it, as a page left open would.
		setMode( 'always' );
		await page.goto( URL );
		setMode( 'never' );

		await page
			.getByRole( 'button', { name: 'Get access', exact: true } )
			.click();

		await expect(
			page.getByText(
				'You already have this, and it cannot be bought again.'
			)
		).toBeVisible();
	} );
} );
