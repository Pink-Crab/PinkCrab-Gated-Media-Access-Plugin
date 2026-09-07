/**
 * The admin screens the plugin designs for itself.
 *
 * wp-admin is core's surface, so the plugin restyles only what it owns —
 * everything under `.gatedmedia-admin`. That restyling has to keep core's own
 * guarantees, and the one that goes missing most easily is the keyboard focus
 * indicator: `outline: none` is a single line and shows up as nothing at all
 * until somebody tries to tab through the form.
 *
 * _mixins.scss states the rule these assert: "§6.3 — focus. Never removed,
 * never replaced with a colour change alone."
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USER || 'admin';
const PASSWORD = process.env.WP_PASSWORD || 'password';

/**
 * Signs in through the ordinary login form.
 *
 * @param {import('@playwright/test').Page} page The page.
 */
async function signIn( page ) {
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

/**
 * Whether an element draws something a keyboard user can see when focused.
 *
 * A border colour change alone does not count: it is the one thing §6.3 names
 * as insufficient, and on these screens it moved #dcdcde to #605e61.
 *
 * @param {import('@playwright/test').Locator} field The control to focus.
 */
async function focusIndicator( field ) {
	await field.focus();

	return field.evaluate( ( el ) => {
		const style = el.ownerDocument.defaultView.getComputedStyle( el );

		return {
			outlineStyle: style.outlineStyle,
			outlineWidth: style.outlineWidth,
			boxShadow: style.boxShadow,
		};
	} );
}

test.describe( 'the settings screen', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
		await page.goto( '/wp-admin/admin.php?page=gatedmedia-settings' );
	} );

	test( 'it is the plugin-styled screen, not a default one', async ( {
		page,
	} ) => {
		await expect( page.locator( '.gatedmedia-admin' ) ).toBeVisible();
	} );

	test( 'a focused text input draws a visible focus indicator', async ( {
		page,
	} ) => {
		const field = page
			.locator( '.gatedmedia-admin input[type="text"]' )
			.first();

		await expect( field ).toBeVisible();

		const indicator = await focusIndicator( field );

		expect(
			indicator.outlineStyle !== 'none' || indicator.boxShadow !== 'none'
		).toBe( true );
	} );

	test( 'a focused select draws a visible focus indicator', async ( {
		page,
	} ) => {
		const field = page.locator( '.gatedmedia-admin select' ).first();

		await expect( field ).toBeVisible();

		const indicator = await focusIndicator( field );

		expect(
			indicator.outlineStyle !== 'none' || indicator.boxShadow !== 'none'
		).toBe( true );
	} );

	test( 'a focused textarea draws a visible focus indicator', async ( {
		page,
	} ) => {
		// The templates live on the Notifications tab, which is where the only
		// textarea on these screens is.
		await page.goto(
			'/wp-admin/admin.php?page=gatedmedia-settings&section=notifications'
		);

		const field = page.locator( '.gatedmedia-admin textarea' ).first();

		await expect( field ).toBeVisible();

		const indicator = await focusIndicator( field );

		expect(
			indicator.outlineStyle !== 'none' || indicator.boxShadow !== 'none'
		).toBe( true );
	} );
} );
