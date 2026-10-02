/**
 * Sales, end to end: set in bulk from the products list and in the product editor, and struck through on the product page.
 *
 * Every spec ends the sale it starts, because the narrow project runs the same specs straight after and the shop specs buy this product at its full price.
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USER || 'admin';
const PASSWORD = process.env.WP_PASSWORD || 'password';

// The unsold £15.00 product, from the shop fixture.
const BUY_URL = process.env.GATEDMEDIA_BUY_URL;
const BUY_ID = process.env.GATEDMEDIA_BUY_ID;

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

/**
 * Bulk edits the product's sale from the products list.
 *
 * @param {import('@playwright/test').Page} page  The page.
 * @param {string}                          mode  percent, amount or end.
 * @param {string}                          value How much off, '' for none.
 */
async function bulkSale( page, mode, value ) {
	await page.goto( '/wp-admin/edit.php?post_type=gatedmedia_product' );
	await page.check( `#cb-select-${ BUY_ID }` );
	// The bottom bulk actions, because core hides the top ones below 782px.
	await page.selectOption( '#bulk-action-selector-bottom', 'edit' );
	await page.click( '#doaction2' );

	const panel = page.locator( '#bulk-edit' );

	await expect( panel ).toBeVisible();
	await panel
		.locator( 'select[name="gatedmedia_bulk_sale"]' )
		.selectOption( mode );

	if ( '' !== value ) {
		await panel
			.locator( 'input[name="gatedmedia_bulk_sale_value"]' )
			.fill( value );
	}

	await page.click( '#bulk_edit' );
	await page.waitForLoadState();
}

/**
 * The prices the product page shows.
 *
 * @param {import('@playwright/test').Page} page The page.
 * @return {Promise<{original: ?string, amount: ?string}>} The struck and the payable price.
 */
async function prices( page ) {
	await page.goto( BUY_URL );

	return page.evaluate( () => ( {
		original:
			document.querySelector( '.gatedmedia-price-block__original' )
				?.textContent ?? null,
		amount:
			document.querySelector( '.gatedmedia-price-block__amount' )
				?.textContent ?? null,
	} ) );
}

test.describe( 'sales', () => {
	test.beforeEach( signIn );

	test.afterEach( async ( { page } ) => {
		await bulkSale( page, 'end', '' );
	} );

	test( 'a percentage off in bulk strikes the full price through', async ( {
		page,
	} ) => {
		await bulkSale( page, 'percent', '20' );

		await expect(
			page.locator( `#post-${ BUY_ID } .column-gatedmedia_price del` )
		).toHaveText( '£15.00' );

		expect( await prices( page ) ).toEqual( {
			original: '£15.00',
			amount: '£12.00',
		} );
	} );

	test( 'an amount off in bulk takes that amount off', async ( { page } ) => {
		await bulkSale( page, 'amount', '2.50' );

		expect( await prices( page ) ).toEqual( {
			original: '£15.00',
			amount: '£12.50',
		} );
	} );

	test( 'ending the sale puts the full price back', async ( { page } ) => {
		await bulkSale( page, 'percent', '20' );
		await bulkSale( page, 'end', '' );

		expect( await prices( page ) ).toEqual( {
			original: null,
			amount: '£15.00',
		} );
	} );

	test( 'the editor keeps a typed amount as typed and shows the sale price', async ( {
		page,
	} ) => {
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

		await page.goto( `/wp-admin/post.php?post=${ BUY_ID }&action=edit` );

		const block = page
			.frameLocator( 'iframe[name="editor-canvas"]' )
			.locator( '.gatedmedia-product-details' );

		await expect( block ).toBeVisible( { timeout: 30_000 } );

		await block.getByLabel( 'Sale type' ).selectOption( 'amount' );

		const off = block.getByLabel( 'Sale amount off' );

		// One key at a time, as a person types, which is what re-formatting used to break.
		await off.pressSequentially( '2.50' );

		await expect( off ).toHaveValue( '2.50' );
		await expect( block ).toContainText( '£12.50' );

		// Back to no sale and saved, so leaving the editor asks nothing.
		await block.getByLabel( 'Sale type' ).selectOption( '' );

		const dirty = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isEditedPostDirty()
		);

		if ( dirty ) {
			await page
				.getByRole( 'region', { name: 'Editor top bar' } )
				.getByRole( 'button', { name: 'Save', exact: true } )
				.click();
			await page.waitForFunction(
				() =>
					! window.wp.data.select( 'core/editor' ).isEditedPostDirty()
			);
		}
	} );
} );
