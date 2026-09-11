/**
 * A third-party login plugin meeting our auth pages, in a real browser.
 *
 * `fake-2fa` is the stand-in for a captcha or two-factor plugin: it hooks core's
 * `login_form`, `register_form`, `authenticate` and `registration_errors`, and
 * knows nothing about this plugin. `auth-bridge` is the site's own glue, using
 * this plugin's hooks to re-publish core's two rendering hooks inside our form.
 *
 * Both are inert until switched on, so the rest of the suite is untouched.
 */

const { test, expect } = require( '@playwright/test' );
const { spawnSync } = require( 'node:child_process' );

const TOKEN_FIELD = '#fake_2fa_token';
const PASSWORD = 'a-long-enough-password';

/**
 * Runs a wp-cli command on the wp-env site.
 *
 * @param {Array<string>} args The command, minus the leading `wp`.
 * @return {string} Everything it printed.
 */
function wp( args ) {
	const result = spawnSync(
		'npx',
		[ 'wp-env', 'run', 'cli', 'wp', ...args ],
		{ encoding: 'utf8' }
	);

	if ( result.status !== 0 ) {
		throw new Error(
			`wp ${ args.join( ' ' ) } failed:\n${ result.stdout || '' }${
				result.stderr || ''
			}`
		);
	}

	return result.stdout || '';
}

/**
 * Turns one of the fixtures, or the auth mode, on or off.
 *
 * @param {string} option The option name.
 * @param {string} value  Its value, '' to delete.
 */
function setOption( option, value ) {
	if ( value === '' ) {
		spawnSync(
			'npx',
			[ 'wp-env', 'run', 'cli', 'wp', 'option', 'delete', option ],
			{
				encoding: 'utf8',
			}
		);
		return;
	}

	wp( [ 'option', 'update', option, value ] );
}

/**
 * A fresh customer account.
 *
 * @return {string} Their email address.
 */
function makeCustomer() {
	const email = `e2e-2fa-${ Date.now() }@example.com`;

	wp( [
		'user',
		'create',
		email,
		email,
		`--role=subscriber`,
		`--user_pass=${ PASSWORD }`,
	] );

	return email;
}

test.describe( 'a login plugin that only knows core', () => {
	test.beforeEach( () => {
		setOption( 'fake_2fa_active', '1' );
	} );

	test.afterEach( () => {
		setOption( 'fake_2fa_active', '' );
		setOption( 'gatedmedia_bridge_active', '' );
		setOption( 'gatedmedia_settings', '' );
	} );

	test( 'without the bridge, it locks everyone out of our sign-in', async ( {
		page,
	} ) => {
		const email = makeCustomer();

		await page.goto( '/sign-in/' );
		await page.fill( 'input[name="email"]', email );
		await page.fill( 'input[name="password"]', PASSWORD );
		await page.click( '.gatedmedia-auth button[type="submit"]' );

		// The password is right. The refusal is the other plugin's, on a field our form never drew.
		await expect( page.locator( 'body' ) ).toContainText(
			"That email and password don't match."
		);
	} );

	test( 'with the bridge, its field is drawn in our form', async ( {
		page,
	} ) => {
		setOption( 'gatedmedia_bridge_active', '1' );

		await page.goto( '/sign-in/' );

		await expect( page.locator( TOKEN_FIELD ) ).toHaveCount( 1 );
	} );

	test( 'with the bridge, the same sign-in goes through', async ( {
		page,
	} ) => {
		setOption( 'gatedmedia_bridge_active', '1' );
		const email = makeCustomer();

		await page.goto( '/sign-in/' );
		await page.fill( 'input[name="email"]', email );
		await page.fill( 'input[name="password"]', PASSWORD );
		await page.click( '.gatedmedia-auth button[type="submit"]' );

		await expect( page.locator( 'body' ) ).not.toContainText(
			"That email and password don't match."
		);
		await expect( page.locator( 'body' ) ).not.toContainText( 'Sign in' );
	} );

	test( 'with the bridge, a tampered token is still refused', async ( {
		page,
	} ) => {
		setOption( 'gatedmedia_bridge_active', '1' );
		const email = makeCustomer();

		await page.goto( '/sign-in/' );
		await page.fill( 'input[name="email"]', email );
		await page.fill( 'input[name="password"]', PASSWORD );

		// The widget would fill this in. Emptying it is the visitor who never solved it.
		await page.evaluate( () => {
			document.getElementById( 'fake_2fa_token' ).value = '';
		} );

		await page.click( '.gatedmedia-auth button[type="submit"]' );

		await expect( page.locator( 'body' ) ).toContainText(
			"That email and password don't match."
		);
	} );

	test( 'with the bridge, its field is drawn on our sign-up too', async ( {
		page,
	} ) => {
		setOption( 'gatedmedia_bridge_active', '1' );

		await page.goto( '/sign-in/?state=signup' );

		await expect( page.locator( TOKEN_FIELD ) ).toHaveCount( 1 );
	} );

	test( 'with the bridge, a sign-up without the token creates nothing', async ( {
		page,
	} ) => {
		setOption( 'gatedmedia_bridge_active', '1' );
		const email = `e2e-2fa-refused-${ Date.now() }@example.com`;

		await page.goto( '/sign-in/?state=signup' );
		await page.fill( 'input[name="email"]', email );
		await page.fill( 'input[name="password"]', PASSWORD );
		await page.evaluate( () => {
			document.getElementById( 'fake_2fa_token' ).value = '';
		} );
		await page.click( '.gatedmedia-auth button[type="submit"]' );

		const found = spawnSync(
			'npx',
			[
				'wp-env',
				'run',
				'cli',
				'wp',
				'user',
				'get',
				email,
				'--field=ID',
			],
			{ encoding: 'utf8' }
		);

		expect( found.status ).not.toBe( 0 );
	} );

	test( 'in core mode our sign-in link goes to wp-login.php', async ( {
		page,
	} ) => {
		wp( [
			'option',
			'patch',
			'insert',
			'gatedmedia_settings',
			'auth_pages',
			'core',
		] );

		await page.goto( '/account/' );

		await expect( page ).toHaveURL( /wp-login\.php/ );
		await expect( page.locator( TOKEN_FIELD ) ).toHaveCount( 1 );
	} );
} );
