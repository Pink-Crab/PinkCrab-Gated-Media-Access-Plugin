/**
 * The profile form, end to end: its groups, and changing a password.
 *
 * Signs in as the account the profile fixture resets, never the admin, because these specs change its password. Any spec that changes it puts it back, since the narrow project runs the same specs straight after.
 */

const { test, expect } = require( '@playwright/test' );

const USER = 'e2e-profile';
const PASSWORD = 'e2e-profile-password';
const NEW_PASSWORD = 'a-brand-new-password';

/**
 * Signs in through the ordinary login form, from a clean session.
 *
 * @param {import('@playwright/test').Page} page     The page.
 * @param {string}                          password The password to try.
 */
async function signIn( page, password ) {
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );
	// Core focuses and selects a field 200ms in, which lands a fill in the wrong box.
	await page.waitForFunction( () => {
		const field = document.getElementById( 'user_login' );
		return field && field.ownerDocument.activeElement === field;
	} );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );
	await page.waitForLoadState();
}

/**
 * Fills the password group and saves.
 *
 * @param {import('@playwright/test').Page} page           The page.
 * @param {Object}                          fields         The three password fields.
 * @param {string}                          fields.current The current password.
 * @param {string}                          fields.next    The new password.
 * @param {string}                          fields.confirm The new password again.
 */
async function changePassword( page, { current, next, confirm } ) {
	await page.goto( '/account/profile/' );
	await page.fill( '#gatedmedia-current_password', current );
	await page.fill( '#gatedmedia-new_password', next );
	await page.fill( '#gatedmedia-confirm_password', confirm );
	await page.click( '.gatedmedia-view--profile button[type="submit"]' );
	await page.waitForLoadState();
}

test.describe( 'profile', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page, PASSWORD );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'the groups are drawn in order, the email first', async ( {
		page,
	} ) => {
		await page.goto( '/account/profile/' );

		const groups = page.locator( '.gatedmedia-profile-group' );

		await expect( groups ).toHaveCount( 3 );
		await expect( groups.nth( 0 ) ).toHaveClass(
			/gatedmedia-profile-group--main/
		);
		await expect( groups.nth( 1 ) ).toHaveClass(
			/gatedmedia-profile-group--password/
		);
		await expect( groups.nth( 2 ) ).toHaveClass(
			/gatedmedia-profile-group--contact/
		);

		const mainIds = await groups
			.nth( 0 )
			.locator( 'input' )
			.evaluateAll( ( inputs ) => inputs.map( ( input ) => input.id ) );

		expect( mainIds ).toEqual( [
			'gatedmedia-email',
			'gatedmedia-first_name',
			'gatedmedia-last_name',
		] );
	} );

	test( 'the groups add no styles of their own, and no headings', async ( {
		page,
	} ) => {
		await page.goto( '/account/profile/' );

		const styles = await page
			.locator( '.gatedmedia-profile-group' )
			.evaluateAll( ( groups ) =>
				groups.map( ( group ) => {
					const style = window.getComputedStyle( group );
					return {
						padding: style.padding,
						border: style.borderTopWidth,
						background: style.backgroundColor,
					};
				} )
			);

		for ( const style of styles ) {
			expect( style ).toEqual( {
				padding: '0px',
				border: '0px',
				background: 'rgba(0, 0, 0, 0)',
			} );
		}

		await expect(
			page.locator(
				'.gatedmedia-profile-group .gatedmedia-section-heading'
			)
		).toHaveCount( 0 );
	} );

	test( 'every field keeps the same gap, across groups as within them', async ( {
		page,
	} ) => {
		await page.goto( '/account/profile/' );

		const gaps = await page
			.locator( '.gatedmedia-view--profile .gatedmedia-field' )
			.evaluateAll( ( fields ) => {
				const boxes = fields.map( ( field ) =>
					field.getBoundingClientRect()
				);
				return boxes
					.slice( 1 )
					.map( ( box, index ) => box.top - boxes[ index ].bottom );
			} );

		// Twelve fields, eleven gaps, two of them between groups.
		expect( gaps ).toHaveLength( 11 );

		for ( const gap of gaps ) {
			expect( gap ).toBeCloseTo( gaps[ 0 ], 0 );
		}
	} );

	test( 'the password inputs are empty password inputs', async ( {
		page,
	} ) => {
		await page.goto( '/account/profile/' );

		for ( const id of [
			'#gatedmedia-current_password',
			'#gatedmedia-new_password',
			'#gatedmedia-confirm_password',
		] ) {
			await expect( page.locator( id ) ).toHaveAttribute(
				'type',
				'password'
			);
			await expect( page.locator( id ) ).toHaveValue( '' );
		}
	} );

	test( 'a wrong current password is refused and changes nothing', async ( {
		page,
	} ) => {
		await changePassword( page, {
			current: 'not-the-password',
			next: NEW_PASSWORD,
			confirm: NEW_PASSWORD,
		} );

		await expect(
			page.locator( '.gatedmedia-notice--error' )
		).toBeVisible();
		await expect(
			page.locator( '#gatedmedia-current_password' )
		).toHaveAttribute( 'aria-invalid', 'true' );
		await expect(
			page.locator( '#gatedmedia-current_password-message' )
		).toContainText( 'not your current password' );

		await signIn( page, PASSWORD );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'new passwords that do not match are refused under the confirm field', async ( {
		page,
	} ) => {
		await changePassword( page, {
			current: PASSWORD,
			next: NEW_PASSWORD,
			confirm: 'a-different-password',
		} );

		await expect(
			page.locator( '#gatedmedia-confirm_password' )
		).toHaveAttribute( 'aria-invalid', 'true' );
		await expect(
			page.locator( '#gatedmedia-confirm_password-message' )
		).toContainText( 'do not match' );
	} );

	test( 'a short new password is refused under the new password field', async ( {
		page,
	} ) => {
		await changePassword( page, {
			current: PASSWORD,
			next: 'short',
			confirm: 'short',
		} );

		await expect(
			page.locator( '#gatedmedia-new_password' )
		).toHaveAttribute( 'aria-invalid', 'true' );
		await expect(
			page.locator( '#gatedmedia-new_password-message' )
		).toContainText( '12 characters' );
	} );

	test( 'cancel clears a refusal', async ( { page } ) => {
		await changePassword( page, {
			current: 'not-the-password',
			next: NEW_PASSWORD,
			confirm: NEW_PASSWORD,
		} );

		await page.click( '.gatedmedia-view--profile a.gatedmedia-text-link' );

		await expect( page ).not.toHaveURL( /profile=error/ );
		await expect( page.locator( '.gatedmedia-notice--error' ) ).toHaveCount(
			0
		);
	} );

	test( 'a current password on its own saves the rest and changes nothing', async ( {
		page,
	} ) => {
		const company = `Pink Crab ${ Date.now() }`;

		await page.goto( '/account/profile/' );
		await page.fill( '#gatedmedia-current_password', PASSWORD );
		await page.fill( '#gatedmedia-company', company );
		await page.click( '.gatedmedia-view--profile button[type="submit"]' );

		await expect(
			page.locator( '.gatedmedia-notice--success' )
		).toBeVisible();
		await expect( page.locator( '#gatedmedia-company' ) ).toHaveValue(
			company
		);

		await signIn( page, PASSWORD );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );

	test( 'a correct change keeps you signed in, and only the new password signs in after', async ( {
		page,
	} ) => {
		await changePassword( page, {
			current: PASSWORD,
			next: NEW_PASSWORD,
			confirm: NEW_PASSWORD,
		} );

		// Still on the profile, so the change did not sign them out.
		await expect( page ).toHaveURL( /\/account\/profile\/.*profile=saved/ );
		await expect(
			page.locator( '.gatedmedia-notice--success' )
		).toBeVisible();

		await signIn( page, PASSWORD );
		await expect( page ).not.toHaveURL( /wp-admin/ );

		await signIn( page, NEW_PASSWORD );
		await expect( page ).toHaveURL( /wp-admin/ );

		// Put it back through the same form, for the specs that follow.
		await changePassword( page, {
			current: NEW_PASSWORD,
			next: PASSWORD,
			confirm: PASSWORD,
		} );
		await expect(
			page.locator( '.gatedmedia-notice--success' )
		).toBeVisible();

		await signIn( page, PASSWORD );
		await expect( page ).toHaveURL( /wp-admin/ );
	} );
} );
