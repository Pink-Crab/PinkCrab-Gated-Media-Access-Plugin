/**
 * The site's own way in, end to end.
 *
 * Four states on one URL. What unit tests cannot see is what a browser does with them: that the route renders without the account shell, that the links between the states land, and that a failed sign-in draws the notice and both fields in their invalid treatment.
 *
 * Both viewports run these, because narrow-only faults have got through a wide-only pass before.
 *
 * Nothing here waits for an email. wp-env ships no mailcatcher, so reset is walked as far as the confirmation state, which is all it promises anyway.
 */

const { test, expect } = require( '@playwright/test' );

// The subscriber the shop fixture creates. By address, because the field is `type="email"`.
const USER = 'e2e-empty@example.test';
const PASSWORD = 'e2e-empty-password';

const BUY_URL = process.env.GATEDMEDIA_BUY_URL;

test.describe( 'the auth view', () => {
	test( 'sign in draws the card, both fields and its two other routes', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/' );

		const card = page.locator( '.gatedmedia-auth-card' );
		await expect( card ).toBeVisible();

		// No shell: neither the sidebar nor the tab strip is drawn here.
		await expect( page.locator( '.gatedmedia-account' ) ).toHaveCount( 0 );

		await expect( page.locator( '#gatedmedia-email' ) ).toBeVisible();
		await expect( page.locator( '#gatedmedia-password' ) ).toBeVisible();

		await expect(
			card.getByRole( 'link', { name: /forgotten your password/i } )
		).toBeVisible();
	} );

	test( 'the states reach each other, and each one names itself', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/' );

		await page
			.getByRole( 'link', { name: /forgotten your password/i } )
			.click();
		await expect( page ).toHaveURL( /state=reset/ );

		// Reset asks for the address and nothing else.
		await expect( page.locator( '#gatedmedia-password' ) ).toHaveCount( 0 );

		await page.getByRole( 'link', { name: /back to sign in/i } ).click();
		await expect( page.locator( '#gatedmedia-password' ) ).toBeVisible();
	} );

	test( 'a failed sign-in shows the notice and marks both fields, not one', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/' );

		await page.fill( '#gatedmedia-email', 'nobody@example.com' );
		await page.fill( '#gatedmedia-password', 'definitely-wrong' );
		await page.getByRole( 'button', { name: /^sign in$/i } ).click();

		await expect(
			page.locator( '.gatedmedia-notice--error' )
		).toBeVisible();

		// Both fields, because marking one would say which half was wrong.
		await expect(
			page.locator( '.gatedmedia-field.is-invalid' )
		).toHaveCount( 2 );

		// The address comes back so they need not retype it, and the password does not because it would have to travel in the URL.
		await expect( page.locator( '#gatedmedia-email' ) ).toHaveValue(
			'nobody@example.com'
		);
		await expect( page.locator( '#gatedmedia-password' ) ).toHaveValue(
			''
		);
	} );

	test( 'asking for a reset link confirms nothing about the address', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/?state=reset' );

		await page.fill( '#gatedmedia-email', 'nobody-at-all@example.com' );
		await page.getByRole( 'button', { name: /send reset link/i } ).click();

		await expect( page ).toHaveURL( /sent=1/ );

		const card = page.locator( '.gatedmedia-auth-card' );

		// The fields and the button are replaced entirely.
		await expect( card.locator( 'input[type="email"]' ) ).toHaveCount( 0 );
		await expect( card.locator( 'button[type="submit"]' ) ).toHaveCount(
			0
		);

		await expect( card.locator( '.gatedmedia-notice' ) ).toContainText(
			/if that email has an account/i
		);
	} );

	test( 'signing in for real lands where the URL asked', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/' );

		await page.fill( '#gatedmedia-email', USER );
		await page.fill( '#gatedmedia-password', PASSWORD );
		await page.getByRole( 'button', { name: /^sign in$/i } ).click();

		await expect( page ).toHaveURL( /\/account\// );
	} );

	test( 'someone already signed in is sent on rather than shown the form', async ( {
		page,
	} ) => {
		await page.goto( '/sign-in/' );
		await page.fill( '#gatedmedia-email', USER );
		await page.fill( '#gatedmedia-password', PASSWORD );
		await page.getByRole( 'button', { name: /^sign in$/i } ).click();
		await expect( page ).toHaveURL( /\/account\// );

		await page.goto( '/sign-in/' );

		await expect( page ).not.toHaveURL( /\/sign-in\// );
	} );
} );

test.describe( 'the way in from a product', () => {
	test.skip( ! BUY_URL, 'The shop fixture did not run.' );

	test( 'the two signed-out controls go to two different places', async ( {
		page,
	} ) => {
		await page.goto( BUY_URL );

		const signIn = page.getByRole( 'link', {
			name: /already have an account/i,
		} );

		await expect( signIn ).toBeVisible();

		// This used to be the same wp-login URL the submit beside it went to.
		await expect( signIn ).toHaveAttribute( 'href', /\/sign-in\// );
		await expect( signIn ).not.toHaveAttribute( 'href', /wp-login/ );
	} );

	test( 'the buy submit carries the product through to sign up and back', async ( {
		page,
	} ) => {
		await page.goto( BUY_URL );

		await page
			.getByRole( 'button', { name: /create an account to continue/i } )
			.click();

		await expect( page ).toHaveURL( /state=signup/ );
		await expect( page ).toHaveURL( /redirect_to=/ );

		// Sign up asks for both fields and says what the password has to be.
		await expect( page.locator( '#gatedmedia-email' ) ).toBeVisible();
		await expect(
			page.locator( '.gatedmedia-field__message' )
		).toContainText( /at least \d+ characters/i );
	} );
} );
