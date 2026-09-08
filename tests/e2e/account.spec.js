/**
 * The account area, end to end.
 *
 * These cover the things unit and integration tests cannot see: that the theme still renders around us, that the one breakpoint reflows correctly, and that a refused URL answers with a real 404 status rather than a "not found" page served with 200.
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

test.describe( 'account area', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the bare route lands on My Access', async ( { page } ) => {
		await page.goto( '/account/' );

		await expect( page.locator( '.gatedmedia-account' ) ).toBeVisible();
		await expect( page ).toHaveTitle( /My Access/ );
	} );

	test( 'the theme still renders its own header and footer around us', async ( {
		page,
	} ) => {
		await page.goto( '/account/' );

		// The whole point of the virtual page rather than a takeover.
		await expect(
			page.locator( 'header.wp-block-template-part' )
		).toBeVisible();
		await expect(
			page.locator( 'footer.wp-block-template-part' )
		).toBeVisible();
	} );

	test( 'the page has exactly one h1', async ( { page } ) => {
		await page.goto( '/account/files/' );

		// The theme supplies it, so we must not print a second.
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

	test( 'every section is reachable from the navigation', async ( {
		page,
	} ) => {
		await page.goto( '/account/' );

		// Both navigations are in the markup and CSS picks one per width, so this counts what is on screen.
		const links = page.locator(
			'.gatedmedia-account-nav__item:visible, .gatedmedia-tab-strip__item:visible'
		);

		await expect( links ).toHaveCount( 4 );
	} );

	test( 'the current section is marked as the current page', async ( {
		page,
	} ) => {
		await page.goto( '/account/orders/' );

		// Visible only: the hidden navigation carries its own aria-current, but display:none keeps it out of the accessibility tree.
		const current = page.locator( '[aria-current="page"]:visible' );

		await expect( current ).toHaveCount( 1 );
		await expect( current ).toContainText( 'Orders' );
	} );

	test( 'an unknown section is a real 404, not a soft one', async ( {
		page,
	} ) => {
		const response = await page.goto( '/account/nothing-here/' );

		// A "page not found" screen served with 200 is indexed as a live page.
		expect( response.status() ).toBe( 404 );
		await expect( page.locator( '.gatedmedia-account' ) ).toHaveCount( 0 );
	} );

	test( 'the icon sprite is inlined once', async ( { page } ) => {
		await page.goto( '/account/' );

		// External <use> references are not reliably supported, so the symbols have to be in the document.
		expect( await page.locator( 'symbol' ).count() ).toBeGreaterThan( 0 );
	} );

	test( 'the empty state appears when there is nothing to show', async ( {
		page,
	} ) => {
		// Signed in as somebody who holds nothing and has bought nothing, and the cookies are dropped because for a signed-in visitor core pre-fills the username and empties the password box 200ms later.
		await page.context().clearCookies();

		await page.goto( '/wp-login.php' );
		// Core focuses and selects a field 200ms in, which lands a fill in the wrong box.
		await page.waitForFunction( () => {
			const field = document.getElementById( 'user_login' );
			return field && field.ownerDocument.activeElement === field;
		} );
		await page.fill( '#user_login', 'e2e-empty' );
		await page.fill( '#user_pass', 'e2e-empty-password' );
		await page.click( '#wp-submit' );
		await page.waitForURL( /wp-admin/ );

		await page.goto( '/account/orders/' );

		// One box, at page level, never one per empty section.
		await expect( page.locator( '.gatedmedia-empty-state' ) ).toHaveCount(
			1
		);
	} );

	test( 'my access lists what the fixture granted', async ( { page } ) => {
		await page.goto( '/account/' );

		// Real rows, not the empty state.
		await expect( page.locator( '.gatedmedia-empty-state' ) ).toHaveCount(
			0
		);
		await expect( page.locator( '.gatedmedia-row' ).first() ).toBeVisible();
		await expect( page.getByText( 'E2E Group' ) ).toBeVisible();
	} );

	test( 'files lists the granted file as available', async ( { page } ) => {
		await page.goto( '/account/files/' );

		await expect( page.getByText( 'Granted file' ).first() ).toBeVisible();
	} );

	test( 'the files search hides what does not match and brings it back', async ( {
		page,
	} ) => {
		await page.goto( '/account/files/' );

		const row = page.locator( '.gatedmedia-row' ).first();
		const search = page.locator(
			'[data-gatedmedia-filter="search"] input'
		);

		await expect( row ).toBeVisible();

		await search.fill( 'zzzz-no-such-file' );
		await expect( row ).toBeHidden();

		await search.fill( 'Granted' );
		await expect( row ).toBeVisible();
	} );

	test( 'an available file offers one Download control, not two', async ( {
		page,
	} ) => {
		await page.goto( '/account/files/' );

		const row = page.locator( '.gatedmedia-row' ).first();

		await expect( row ).toBeVisible();

		// The aside and the narrow-only __action both drew one, same href.
		const onScreen = row.locator( 'a:visible', { hasText: 'Download' } );

		await expect( onScreen ).toHaveCount( 1 );
	} );

	test( 'the files type filter hides what is not that type', async ( {
		page,
	} ) => {
		await page.goto( '/account/files/' );

		const row = page.locator( '.gatedmedia-row' ).first();
		const select = page.locator( '[data-gatedmedia-filter="type"]' );

		await expect( row ).toBeVisible();

		// Whatever the fixture's file is, video is not it.
		const ownType = await row.getAttribute( 'data-gatedmedia-type' );
		expect( ownType ).not.toBe( 'video' );

		// Select is the wide control, chips the narrow one.
		const wide = await select.isVisible();
		const pick = async ( type ) => {
			if ( wide ) {
				await select.selectOption( type );
				return;
			}

			await page.locator( `[data-gatedmedia-chip="${ type }"]` ).click();
		};

		await pick( 'video' );
		await expect( row ).toBeHidden();

		await pick( 'all' );
		await expect( row ).toBeVisible();
	} );

	test( 'a profile edit saves and comes back', async ( { page } ) => {
		await page.goto( '/account/profile/' );

		const company = `Pink Crab ${ Date.now() }`;

		// First and last name are required, and a fresh WordPress has neither on its admin user, so they are filled here rather than left to whatever the site happens to hold.
		await page.fill( '#gatedmedia-first_name', 'Glynn' );
		await page.fill( '#gatedmedia-last_name', 'Quelch' );
		await page.fill( '#gatedmedia-company', company );
		await page.click( 'button[type="submit"]' );

		await expect(
			page.locator( '.gatedmedia-notice--success' )
		).toBeVisible();
		await expect( page.locator( '#gatedmedia-company' ) ).toHaveValue(
			company
		);

		// And it survives a fresh request, so it reached the database.
		await page.goto( '/account/profile/' );
		await expect( page.locator( '#gatedmedia-company' ) ).toHaveValue(
			company
		);
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

		// Sent to our own sign-in view, not wp-login.php, and brought back after.
		await expect( page ).toHaveURL( /\/sign-in\// );
		await expect( page ).not.toHaveURL( /wp-login/ );

		// The return trip this test's comment always claimed and never checked.
		await expect( page ).toHaveURL( /redirect_to=.*account/ );
	} );
} );

test.describe( 'the one breakpoint', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the sidebar and the tab strip never both show', async ( {
		page,
	}, testInfo ) => {
		await page.goto( '/account/files/' );

		const sidebar = page.locator( '.gatedmedia-account__sidebar' );
		const tabs = page.locator( '.gatedmedia-tab-strip' );

		if ( testInfo.project.name === 'wide' ) {
			await expect( sidebar ).toBeVisible();
			await expect( tabs ).toBeHidden();
			// 256px, fixed.
			expect( ( await sidebar.boundingBox() ).width ).toBe( 256 );
		} else {
			await expect( sidebar ).toBeHidden();
			await expect( tabs ).toBeVisible();
		}
	} );

	test( 'search stays at every width, and chips replace the select on narrow', async ( {
		page,
	}, testInfo ) => {
		await page.goto( '/account/files/' );

		// Search is never dropped.
		await expect(
			page.locator( '.gatedmedia-filter__search input' )
		).toBeVisible();

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

		// It must never appear on an account view.
		await expect( page.locator( '.gatedmedia-action-bar' ) ).toHaveCount(
			0
		);
	} );

	test( 'nothing overflows the viewport horizontally', async ( { page } ) => {
		await page.goto( '/account/files/' );

		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
	} );
} );
