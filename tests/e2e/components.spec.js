/**
 * Components placed outside the account area.
 *
 * Every other e2e visits `/account/`, where the shell wraps everything — which
 * is exactly how three separate faults survived a full suite:
 *
 * - design tokens were scoped to `.gatedmedia`, so a component anywhere else
 *   had no tokens at all: transparent buttons, 16px where 44px was specified;
 * - the icon sprite was printed only on the account route, so every icon in
 *   every component was silently missing elsewhere;
 * - inline components had no block-level host, so a theme could not place them
 *   in its content column.
 *
 * A block is placeable on any page. These check one behaves there.
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

test.describe( 'a component on an ordinary page', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
		await page.goto( '/component-kitchen-sink/' );
	} );

	test( 'design tokens reach it, so it is not unstyled', async ( { page } ) => {
		// The token that decides every fill. Undefined here was the fault.
		const primary = await page.evaluate( () =>
			getComputedStyle( document.documentElement )
				.getPropertyValue( '--gatedmedia-primary' )
				.trim()
		);

		expect( primary ).toBe( '#5d5e67' );
	} );

	test( 'a button is the size and colour §6.3 specifies', async ( { page } ) => {
		const button = page.locator( '.gatedmedia-button--primary' ).first();

		await expect( button ).toBeVisible();

		const box = await button.boundingBox();
		expect( box.height ).toBe( 44 );

		const styles = await button.evaluate( ( el ) => {
			const c = getComputedStyle( el );
			return { bg: c.backgroundColor, weight: c.fontWeight, radius: c.borderTopLeftRadius };
		} );

		expect( styles.bg ).toBe( 'rgb(93, 94, 103)' );
		expect( styles.weight ).toBe( '700' );
		expect( styles.radius ).toBe( '2px' );
	} );

	test( 'the icon sprite is present, so icons render', async ( { page } ) => {
		const symbols = await page.locator( 'symbol' ).count();
		expect( symbols ).toBeGreaterThan( 0 );

		// Every <use> must resolve. A reference with no symbol draws nothing
		// at all rather than failing, which is how this went unnoticed.
		const unresolved = await page.evaluate( () => {
			const ids = [ ...document.querySelectorAll( 'symbol' ) ].map( ( s ) => s.id );
			return [ ...document.querySelectorAll( 'use' ) ]
				.map( ( u ) => ( u.getAttribute( 'href' ) || '' ).replace( '#', '' ) )
				.filter( ( ref ) => ref && ! ids.includes( ref ) );
		} );

		expect( unresolved ).toEqual( [] );
	} );

	test( 'inline components sit in the content column, not against the page edge', async ( {
		page,
	} ) => {
		// The section heading is a block-level element the theme constrains,
		// so it marks where the column starts.
		const column = await page.locator( '.gatedmedia-section-heading' ).first().boundingBox();

		for ( const selector of [
			'.gatedmedia-button',
			'.gatedmedia-expiry',
			'.gatedmedia-status-pill',
			'.gatedmedia-price',
		] ) {
			const box = await page.locator( selector ).first().boundingBox();

			expect(
				Math.abs( box.x - column.x ),
				`${ selector } is ${ Math.round( box.x - column.x ) }px from the column`
			).toBeLessThanOrEqual( 2 );
		}
	} );

	test( 'a field renders its invalid state', async ( { page } ) => {
		// §6.8's invalid treatment had CSS and no way to be rendered at all
		// until the components became blocks.
		const invalid = page.locator( '.gatedmedia-field.is-invalid' ).first();

		await expect( invalid ).toBeVisible();
		await expect( invalid.locator( '[aria-invalid="true"]' ) ).toHaveCount( 1 );

		const border = await invalid
			.locator( '.gatedmedia-field__input' )
			.evaluate( ( el ) => getComputedStyle( el ).borderTopColor );

		expect( border ).toBe( 'rgb(158, 63, 78)' );
	} );

	test( 'zero renders as the word Free', async ( { page } ) => {
		await expect( page.locator( '.gatedmedia-price-block__amount', { hasText: 'Free' } ) )
			.toHaveCount( 1 );
	} );
} );
