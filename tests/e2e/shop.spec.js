/**
 * The shop face, end to end — ui-spec.md §7.6, §7.3, §7.4 and the group page.
 *
 * These walk what unit tests cannot see: that the product's own single template
 * actually renders the block, that the buy form carries what `Checkout_Action`
 * demands, and that an order opens from the list.
 *
 * **Nothing here submits a priced purchase.** That would call Stripe, and a
 * test suite that reaches a payment provider is a test suite that fails when
 * somebody else's service is down. The paid product is inspected; only the free
 * product, which never touches Stripe, is submitted.
 */

const { test, expect } = require( '@playwright/test' );

const USER = process.env.WP_USER || 'admin';
const PASSWORD = process.env.WP_PASSWORD || 'password';

// Set by global-setup.js from the shop fixture — a product's URL carries a
// uuid minted when the fixture ran, so it cannot be written down here.
const PAID_URL = process.env.GATEDMEDIA_PAID_URL;
const FREE_URL = process.env.GATEDMEDIA_FREE_URL;
const PAID_ID = process.env.GATEDMEDIA_PAID_ID;

// A product granting something the admin does not hold, so its page shows the
// buy form rather than "you already have this".
const BUY_URL = process.env.GATEDMEDIA_BUY_URL;
const BUY_ID = process.env.GATEDMEDIA_BUY_ID;

// Rows are reached by their link text: the row block draws a div with the link
// inside its title, not an anchor wrapping the row (§6.2), so clicking the row
// itself navigates nowhere.

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

test.describe( 'the product page', () => {
	test.skip( ! PAID_URL, 'The shop fixture did not run.' );

	test( 'a stranger sees what it contains, its price, and a way in', async ( {
		page,
	} ) => {
		await page.goto( PAID_URL );

		await expect(
			page.locator( '.gatedmedia-view--product' )
		).toBeVisible();
		await expect( page.getByText( 'What you get' ) ).toBeVisible();
		await expect( page.locator( '.gatedmedia-price-block' ) ).toContainText(
			'£49.00'
		);

		// §7.6 signed-out: the buy control is real and posts, rather than a
		// link that loses the product on the way to logging in.
		await expect(
			page.getByRole( 'button', {
				name: 'Create an account to continue',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: /Already have an account/ } )
		).toBeVisible();
	} );

	test( 'the theme supplies the only h1', async ( { page } ) => {
		await page.goto( PAID_URL );

		// §2 conflict 4 — the product is rendered as its own post, so the theme
		// has already printed the title.
		await expect( page.locator( 'h1' ) ).toHaveCount( 1 );
	} );

	test( 'the buy form carries exactly what checkout demands', async ( {
		page,
	} ) => {
		test.skip( ! BUY_URL, 'The shop fixture did not run.' );

		await signIn( page );
		await page.goto( BUY_URL );

		const form = page.locator( 'form.gatedmedia-buy' );

		await expect( form ).toHaveAttribute( 'action', /admin-post\.php/ );
		await expect( form.locator( 'input[name="action"]' ) ).toHaveValue(
			'gatedmedia_checkout'
		);
		await expect(
			form.locator( 'input[name="gatedmedia_product"]' )
		).toHaveValue( String( BUY_ID ) );
		await expect(
			form.locator( 'input[name="_wpnonce"]' )
		).not.toHaveValue( '' );

		// The coupon rides along on the same submit, under the name
		// Checkout_Action reads. A hyphen here and the code never arrives.
		await expect(
			form.locator( 'input[name="gatedmedia_coupon"]' )
		).toHaveCount( 1 );
	} );

	test( 'a refused checkout comes back saying why', async ( { page } ) => {
		await signIn( page );
		await page.goto(
			`${ PAID_URL }?gatedmedia_checkout_error=gatedmedia_bad_coupon`
		);

		await expect(
			page.locator( '.gatedmedia-notice--error' )
		).toContainText( 'That coupon cannot be used.' );
	} );

	test( 'the product cannot be reached any way but its uuid', async ( {
		request,
	} ) => {
		// A redirect to the real URL would be an oracle for guessing it.
		const response = await request.get(
			`/?post_type=gatedmedia_product&p=${ PAID_ID }`
		);

		expect( response.status() ).toBe( 404 );
	} );

	test( 'a free product is joined rather than bought, and then held', async ( {
		page,
	} ) => {
		test.skip( ! FREE_URL, 'The shop fixture did not run.' );

		await signIn( page );
		await page.goto( FREE_URL );

		const join = page.getByRole( 'button', { name: 'Join' } );

		if ( ( await join.count() ) === 0 ) {
			// Already claimed by an earlier run: the page says so instead.
			await expect(
				page.getByText( 'You already have this.' )
			).toBeVisible();
			return;
		}

		await join.click();
		await page.goto( FREE_URL );

		await expect(
			page.getByText( 'You already have this.' )
		).toBeVisible();
	} );
} );

test.describe( 'orders', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'the list shows what was bought and opens it', async ( { page } ) => {
		await page.goto( '/account/orders/' );

		const order = page.getByRole( 'link', {
			name: 'Q3 market report bundle',
		} );

		await expect( order ).toBeVisible();
		await expect(
			page.locator( '.gatedmedia-status-pill' ).first()
		).toBeVisible();

		await order.click();
		await expect( page ).toHaveURL( /\/account\/orders\/[0-9a-f-]{36}\// );
	} );

	test( 'one order shows its price, its status and what it included', async ( {
		page,
	} ) => {
		await page.goto( '/account/orders/' );
		await page
			.getByRole( 'link', { name: 'Q3 market report bundle' } )
			.click();

		await expect(
			page.locator( '.gatedmedia-order-header' )
		).toContainText( 'Q3 market report bundle' );
		await expect(
			page.locator( '.gatedmedia-order-header' )
		).toContainText( '£49.00' );
		await expect(
			page.getByText( 'What this included at the time' )
		).toBeVisible();
		await expect( page.getByText( 'Access this created' ) ).toBeVisible();
	} );

	test( 'the thank-you shows only when returning from a payment', async ( {
		page,
	} ) => {
		await page.goto( '/account/orders/' );
		await page
			.getByRole( 'link', { name: 'Q3 market report bundle' } )
			.click();
		await page.waitForURL( /\/account\/orders\/[0-9a-f-]{36}\// );

		const url = page.url();

		// An ordinary visit is a receipt, not a celebration.
		await expect(
			page.locator( '.gatedmedia-payment-status' )
		).toHaveCount( 0 );

		const uuid = url.match( /orders\/([0-9a-f-]{36})/ )[ 1 ];
		await page.goto( `${ url }?new_order=${ uuid }` );

		await expect(
			page.locator( '.gatedmedia-payment-status' )
		).toBeVisible();
		await expect( page.getByText( "You're in" ) ).toBeVisible();
	} );

	test( "somebody else's order reads as one that never existed", async ( {
		page,
	} ) => {
		await page.goto(
			'/account/orders/11111111-2222-3333-4444-555555555555/'
		);

		await expect( page.getByText( 'Order not found' ) ).toBeVisible();
	} );
} );

test.describe( 'a held group', () => {
	test.beforeEach( async ( { page } ) => {
		await signIn( page );
	} );

	test( 'opens from My Access and lists what it holds', async ( {
		page,
	} ) => {
		await page.goto( '/account/my-access/' );

		const group = page.getByRole( 'link', { name: 'E2E Briefings' } );

		await expect( group ).toBeVisible();
		await group.click();

		await expect( page ).toHaveURL(
			/\/account\/my-access\/[0-9a-f-]{36}\//
		);
		await expect(
			page.getByText( 'The quarterly briefing' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Back to my access' } )
		).toBeVisible();
	} );

	test( 'a group nobody gave you reads as one that never existed', async ( {
		page,
	} ) => {
		await page.goto(
			'/account/my-access/11111111-2222-3333-4444-555555555555/'
		);

		await expect( page.getByText( 'Group not found' ) ).toBeVisible();
	} );
} );
