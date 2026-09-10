/**
 * The access boundary, end to end.
 *
 * What integration tests cannot see: a refused post answers over HTTP with a genuine 404 and leaks nothing of itself, a held one renders, and the protected-file URL serves its real bytes to the holder while everyone else gets the substitute image.
 *
 * Both viewport projects run all of it, so the boundary holds on either side of 782px.
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

test.describe( 'signed in as the granted user', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'a held restricted post renders', async ( { page } ) => {
		const response = await page.goto( '/?name=e2e-granted-post' );

		expect( response.status() ).toBe( 200 );
		await expect( page.locator( 'body' ) ).toContainText( 'Granted post' );
	} );

	test( 'a restricted post not held is a hard 404 with no clues', async ( {
		page,
	} ) => {
		const response = await page.goto( '/?name=e2e-refused-post' );

		expect( response.status() ).toBe( 404 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Refused post'
		);
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Locked away.'
		);
	} );

	test( 'search does not surface the refused post', async ( { page } ) => {
		await page.goto( '/?s=Refused' );

		await expect( page.locator( 'body' ) ).not.toContainText(
			'Refused post'
		);
	} );

	test( 'the protected file serves its bytes to the holder, the substitute to everyone else', async ( {
		page,
		request,
	} ) => {
		await page.goto( '/account/files/' );

		const href = await page
			.getByRole( 'link', { name: /Download/ } )
			.first()
			.getAttribute( 'href' );

		expect( href ).toBeTruthy();

		// The page's request context carries the signed-in cookies.
		const held = await page.request.get( href );
		expect( held.status() ).toBe( 200 );
		expect( await held.text() ).toContain( 'E2E protected file contents' );

		// The bare `request` fixture carries no cookies, so this is a stranger's view.
		const refused = await request.get( href );
		expect( refused.status() ).toBe( 200 );
		expect( refused.headers()[ 'content-type' ] ).toContain( 'image/gif' );
	} );
} );

test.describe( 'signed out', () => {
	test( 'every restricted post is a 404, held by someone or not', async ( {
		page,
	} ) => {
		const refused = await page.goto( '/?name=e2e-refused-post' );
		expect( refused.status() ).toBe( 404 );

		const granted = await page.goto( '/?name=e2e-granted-post' );
		expect( granted.status() ).toBe( 404 );
		await expect( page.locator( 'body' ) ).not.toContainText(
			'Members only.'
		);
	} );

	test( 'the single post feed leaks nothing of a restricted post', async ( {
		request,
	} ) => {
		// withoutcomments keeps it a posts feed. The comments feed prints no body, so without this the test would pass while proving nothing.
		const response = await request.get(
			'/?name=e2e-refused-post&feed=rss2&withoutcomments=1'
		);
		const body = await response.text();

		expect( response.status() ).toBe( 404 );
		expect( body ).not.toContain( 'Locked away.' );
		expect( body ).not.toContain( 'Refused post' );
	} );
} );
