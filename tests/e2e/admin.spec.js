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

test.describe( 'the product editor', () => {
	// The block is pinned into every product by the post type's template, so
	// a new product draws it from the first paint. The canvas is an iframe.
	test.beforeEach( async ( { page } ) => {
		await signIn( page );

		// The welcome guide covers the canvas on a fresh install.
		await page.addInitScript( () => {
			window.localStorage.setItem(
				'WP_PREFERENCES_USER_GLOBAL',
				JSON.stringify( {
					'core/edit-post': { welcomeGuide: false },
					core: { welcomeGuide: false },
				} )
			);
		} );

		await page.goto(
			'/wp-admin/post-new.php?post_type=gatedmedia_product'
		);
	} );

	test( 'the item type tabs are named, not printed as storage keys', async ( {
		page,
	} ) => {
		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const block = canvas.locator( '.gatedmedia-product-details' );

		await expect( block ).toBeVisible( { timeout: 30_000 } );

		// 'group', 'post' and 'file' are the halves of a stored row key
		// ("group:12"), never labels, and they never went through __().
		for ( const label of [ 'Group', 'Post', 'File' ] ) {
			await expect(
				block.getByRole( 'button', { name: label, exact: true } )
			).toBeVisible();
		}

		for ( const key of [ 'group', 'post', 'file' ] ) {
			await expect(
				block.getByRole( 'button', { name: key, exact: true } )
			).toHaveCount( 0 );
		}
	} );
} );
