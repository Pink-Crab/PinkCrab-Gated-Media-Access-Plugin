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
		// The only textarea on these screens is on the Notifications tab.
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
	// The post type's template pins the block in. The canvas is an iframe.
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

		// 'group', 'post' and 'file' are row key halves, never labels.
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

	/**
	 * The block's item search box, inside the editor canvas.
	 *
	 * @param {import('@playwright/test').Page} page The page.
	 */
	function itemSearch( page ) {
		return page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.getByRole( 'textbox', { name: 'Search for an item to add' } );
	}

	/**
	 * Answers the first term with one row, and the second however the test says.
	 *
	 * @param {import('@playwright/test').Page} page   The page.
	 * @param {Object}                          broken How the second answers.
	 */
	async function searchAnswers( page, broken ) {
		await page.route( '**/admin-ajax.php**', async ( route ) => {
			const url = route.request().url();

			if ( ! url.includes( 'gatedmedia_search_' ) ) {
				await route.continue();
				return;
			}

			if ( url.includes( 'term=aaab' ) ) {
				await route.fulfill( broken );
				return;
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [ { id: 901, label: 'FIRST HIT' } ] ),
			} );
		} );
	}

	test( 'a failed request clears the results rather than leaving stale ones', async ( {
		page,
	} ) => {
		// A failed request never reached setResults, so stale rows stayed.
		await searchAnswers( page, { status: 502, body: 'nope' } );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const search = itemSearch( page );

		await search.fill( 'aaa' );
		await expect(
			canvas.getByRole( 'button', { name: 'FIRST HIT' } )
		).toBeVisible();

		await search.fill( 'aaab' );
		await expect(
			canvas.getByRole( 'button', { name: 'FIRST HIT' } )
		).toHaveCount( 0 );
	} );

	test( 'a stale nonce answer clears the results too', async ( { page } ) => {
		// A dead nonce answers 200 with "-1", which is not JSON.
		await searchAnswers( page, { status: 200, body: '-1' } );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const search = itemSearch( page );

		await search.fill( 'aaa' );
		await expect(
			canvas.getByRole( 'button', { name: 'FIRST HIT' } )
		).toBeVisible();

		await search.fill( 'aaab' );
		await expect(
			canvas.getByRole( 'button', { name: 'FIRST HIT' } )
		).toHaveCount( 0 );
	} );

	test( 'a slow earlier search cannot overwrite a newer one', async ( {
		page,
	} ) => {
		// The early term answers late, with a row of its own.
		await page.route( '**/admin-ajax.php**', async ( route ) => {
			const url = route.request().url();

			if ( ! url.includes( 'gatedmedia_search_' ) ) {
				await route.continue();
				return;
			}

			const stale =
				url.includes( 'term=aa' ) && ! url.includes( 'term=aab' );

			if ( stale ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 3000 ) );
			}

			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( [
					{
						id: stale ? 991 : 992,
						label: stale ? 'STALE ANSWER' : 'FRESH ANSWER',
					},
				] ),
			} );
		} );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		const search = itemSearch( page );

		await search.fill( 'aa' );
		await search.fill( 'aab' );

		await expect(
			canvas.getByRole( 'button', { name: 'FRESH ANSWER' } )
		).toBeVisible();

		// Long enough for the delayed answer to arrive and be discarded.
		await page.waitForTimeout( 4000 );

		await expect(
			canvas.getByRole( 'button', { name: 'STALE ANSWER' } )
		).toHaveCount( 0 );
		await expect(
			canvas.getByRole( 'button', { name: 'FRESH ANSWER' } )
		).toBeVisible();
	} );
} );
