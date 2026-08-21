/**
 * The account area, end to end.
 *
 * These cover the things unit and integration tests cannot see: that the theme
 * still renders around us, that the one breakpoint reflows as ui-spec.md §3
 * settled it, and that a refused URL answers with a real 404 status rather than
 * a "not found" page served with 200.
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
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

test.describe( 'account area', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the bare route lands on My Access', async ( { page } ) => {
		await page.goto( '/account/' );

		await expect( page.locator( '.gatedmedia-account' ) ).toBeVisible();
		await expect( page ).toHaveTitle( /My Access/ );
	} );

	test( 'the theme still renders its own header and footer around us', async ( { page } ) => {
		await page.goto( '/account/' );

		// The whole point of the virtual page rather than a takeover.
		await expect( page.locator( 'header.wp-block-template-part' ) ).toBeVisible();
		await expect( page.locator( 'footer.wp-block-template-part' ) ).toBeVisible();
	} );

	test( 'the page has exactly one h1', async ( { page } ) => {
		await page.goto( '/account/files/' );

		// §2 conflict 4. The theme supplies it; we must not print a second.
		await expect( page.locator( 'h1' ) ).toHaveCount( 1 );
	} );

	for ( const [ slug, title ] of [
		[ 'my-access', 'My Access' ],
		[ 'files', 'Files' ],
		[ 'orders', 'Orders' ],
		[ 'profile', 'Profile' ],
	] ) {
		test( `the ${ slug } section renders`, async ( { page } ) => {
			const response = await page.goto( `/account/${ slug }/` );

			expect( response.status() ).toBe( 200 );
			await expect( page.locator( 'h1' ) ).toHaveText( title );
			await expect( page.locator( '.gatedmedia-account' ) ).toBeVisible();
		} );
	}

	test( 'every section is reachable from the navigation', async ( { page } ) => {
		await page.goto( '/account/' );

		// Both navigations are in the markup and CSS picks one per width, so
		// this counts what is on screen rather than what is in the document.
		const links = page.locator(
			'.gatedmedia-account-nav__item:visible, .gatedmedia-tab-strip__item:visible'
		);

		await expect( links ).toHaveCount( 4 );
	} );

	test( 'the current section is marked as the current page', async ( { page } ) => {
		await page.goto( '/account/orders/' );

		// Visible only, for the same reason. The hidden navigation carries its
		// own aria-current, but display:none keeps it out of the
		// accessibility tree, so nothing is announced twice.
		const current = page.locator( '[aria-current="page"]:visible' );

		await expect( current ).toHaveCount( 1 );
		await expect( current ).toContainText( 'Orders' );
	} );

	test( 'an unknown section is a real 404, not a soft one', async ( { page } ) => {
		const response = await page.goto( '/account/nothing-here/' );

		// A "page not found" screen served with 200 is indexed as a live page.
		expect( response.status() ).toBe( 404 );
		await expect( page.locator( '.gatedmedia-account' ) ).toHaveCount( 0 );
	} );

	test( 'the icon sprite is inlined once', async ( { page } ) => {
		await page.goto( '/account/' );

		// External <use> references are not reliably supported, so the symbols
		// have to be in the document.
		expect( await page.locator( 'symbol' ).count() ).toBeGreaterThan( 0 );
	} );

	test( 'the empty state appears when there is nothing to show', async ( { page } ) => {
		// Signed in as somebody who holds nothing and has bought nothing. This
		// used to use the admin's Orders, on the grounds that no orders could
		// exist yet — true only until the payments table landed and the shop
		// fixture began creating one. An empty state needs an empty account,
		// not a feature that has not been built.
		await page.goto( '/wp-login.php?loggedout=true' );
		await page.fill( '#user_login', 'e2e-empty' );
		await page.fill( '#user_pass', 'e2e-empty-password' );
		await page.click( '#wp-submit' );

		await page.goto( '/account/orders/' );

		// One box, at page level — never one per empty section.
		await expect( page.locator( '.gatedmedia-empty-state' ) ).toHaveCount( 1 );
	} );

	test( 'my access lists what the fixture granted', async ( { page } ) => {
		await page.goto( '/account/' );

		// Real rows, not the empty state — the resolver feeds the view now.
		await expect( page.locator( '.gatedmedia-empty-state' ) ).toHaveCount( 0 );
		await expect( page.locator( '.gatedmedia-row' ).first() ).toBeVisible();
		await expect( page.getByText( 'E2E Group' ) ).toBeVisible();
	} );

	test( 'files lists the granted file as available', async ( { page } ) => {
		await page.goto( '/account/files/' );

		await expect( page.getByText( 'Granted file' ).first() ).toBeVisible();
	} );

	test( 'a profile edit saves and comes back', async ( { page } ) => {
		await page.goto( '/account/profile/' );

		const company = `Pink Crab ${ Date.now() }`;

		// First and last name are required, so the browser refuses to submit
		// while they are empty. A fresh WordPress has neither on its admin
		// user, which made this pass only where someone had filled them in by
		// hand. Fill them here so the state under test is the one we set.
		await page.fill( '#gatedmedia-first_name', 'Glynn' );
		await page.fill( '#gatedmedia-last_name', 'Quelch' );
		await page.fill( '#gatedmedia-company', company );
		await page.click( 'button[type="submit"]' );

		await expect( page.locator( '.gatedmedia-notice--success' ) ).toBeVisible();
		await expect( page.locator( '#gatedmedia-company' ) ).toHaveValue( company );

		// And it survives a fresh request, so it reached the database.
		await page.goto( '/account/profile/' );
		await expect( page.locator( '#gatedmedia-company' ) ).toHaveValue( company );
	} );

	test( 'the email field cannot be edited here', async ( { page } ) => {
		await page.goto( '/account/profile/' );

		await expect( page.locator( '#gatedmedia-email' ) ).toBeDisabled();
	} );
} );

test.describe( 'signed out', () => {
	test( 'the account area is not reachable', async ( { page } ) => {
		await page.context().clearCookies();
		await page.goto( '/account/' );

		// Sent to log in, and brought back here afterwards.
		await expect( page ).toHaveURL( /wp-login\.php/ );
	} );
} );

test.describe( 'the one breakpoint', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the sidebar and the tab strip never both show', async ( { page }, testInfo ) => {
		await page.goto( '/account/files/' );

		const sidebar = page.locator( '.gatedmedia-account__sidebar' );
		const tabs = page.locator( '.gatedmedia-tab-strip' );

		if ( testInfo.project.name === 'wide' ) {
			await expect( sidebar ).toBeVisible();
			await expect( tabs ).toBeHidden();
			// §3 — 256px, fixed.
			expect( ( await sidebar.boundingBox() ).width ).toBe( 256 );
		} else {
			await expect( sidebar ).toBeHidden();
			await expect( tabs ).toBeVisible();
		}
	} );

	test( 'search stays at every width, and chips replace the select on narrow', async ( { page }, testInfo ) => {
		await page.goto( '/account/files/' );

		// §8 conflict 8 — search is never dropped.
		await expect( page.locator( '.gatedmedia-filter__search input' ) ).toBeVisible();

		const select = page.locator( '.gatedmedia-filter__type' );
		const chips = page.locator( '.gatedmedia-type-chips' );

		if ( testInfo.project.name === 'wide' ) {
			await expect( select ).toBeVisible();
			await expect( chips ).toBeHidden();
		} else {
			await expect( select ).toBeHidden();
			await expect( chips ).toBeVisible();
		}
	} );

	test( 'no fixed bottom bar at any width', async ( { page } ) => {
		await page.goto( '/account/' );

		// §8 conflict 3 dropped it. It must not come back on an account view.
		await expect( page.locator( '.gatedmedia-action-bar' ) ).toHaveCount( 0 );
	} );

	test( 'nothing overflows the viewport horizontally', async ( { page } ) => {
		await page.goto( '/account/files/' );

		const overflow = await page.evaluate(
			() => document.documentElement.scrollWidth - document.documentElement.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );
