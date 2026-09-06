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

// An unlimited 20% coupon, so §6.14's apply step can be walked repeatedly.
const COUPON = process.env.GATEDMEDIA_COUPON_CODE;

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

		// The coupon is no longer typed inside this form. Apply used to be a
		// submit on it, so pressing Apply went straight to Stripe at full
		// price; §6.14's flow is apply, see the discount, then buy. The typed
		// field now lives in its own GET form, and only a code that actually
		// priced the page rides the buy submit — as a hidden input, under the
		// name Checkout_Action reads. A hyphen there and the code never
		// arrives.
		await expect(
			form.locator( 'input[name="gatedmedia_coupon"]' )
		).toHaveCount( 0 );

		await expect(
			page.locator(
				'form.gatedmedia-coupon-form input[name="gatedmedia_coupon"]'
			)
		).toHaveCount( 1 );
	} );

	test( 'applying a coupon shows what it saves before anything is bought', async ( {
		page,
	} ) => {
		test.skip( ! BUY_URL || ! COUPON, 'The shop fixture did not run.' );

		await signIn( page );
		await page.goto( BUY_URL );

		const before = await page
			.locator( '.gatedmedia-price-block' )
			.innerText();

		await page.fill(
			'form.gatedmedia-coupon-form input[name="gatedmedia_coupon"]',
			COUPON
		);
		await page.getByRole( 'button', { name: 'Apply' } ).click();

		// Apply reloads this page with the code on it rather than buying
		// anything — the buyer is still here, and still on the product.
		await expect( page ).toHaveURL(
			new RegExp( `gatedmedia_coupon=${ COUPON }` )
		);

		// §6.14: the input and its button are replaced by a confirmation, not
		// decorated with one.
		await expect(
			page.locator( '.gatedmedia-coupon__applied' )
		).toContainText( COUPON );
		await expect(
			page.locator(
				'form.gatedmedia-coupon-form input[name="gatedmedia_coupon"]'
			)
		).toHaveCount( 0 );

		// The price moved, and the old one is still shown struck through.
		const after = await page
			.locator( '.gatedmedia-price-block' )
			.innerText();

		expect( after ).not.toBe( before );

		// And the code now travels with the purchase.
		await expect(
			page.locator(
				'form.gatedmedia-buy input[name="gatedmedia_coupon"]'
			)
		).toHaveValue( COUPON );

		// Nothing was bought by looking at a price.
		await expect(
			page.locator( '.gatedmedia-view--product' )
		).toBeVisible();

		// Remove puts it back.
		await page.getByRole( 'link', { name: 'Remove' } ).click();

		await expect(
			page.locator(
				'form.gatedmedia-coupon-form input[name="gatedmedia_coupon"]'
			)
		).toHaveCount( 1 );
	} );

	test( 'a coupon that is not one says so and leaves the price alone', async ( {
		page,
	} ) => {
		test.skip( ! BUY_URL, 'The shop fixture did not run.' );

		await signIn( page );
		await page.goto( `${ BUY_URL }?gatedmedia_coupon=not-a-coupon` );

		// §6.8's invalid treatment: the error replaces the helper line rather
		// than joining it, so it is the field's own message element.
		await expect(
			page.locator( '.gatedmedia-field__message' )
		).toContainText( 'That coupon cannot be used.' );

		await expect(
			page.locator( 'form.gatedmedia-coupon-form [aria-invalid="true"]' )
		).toHaveCount( 1 );

		// Refused, so nothing is carried to checkout.
		await expect(
			page.locator(
				'form.gatedmedia-buy input[name="gatedmedia_coupon"]'
			)
		).toHaveCount( 0 );
	} );

	/**
	 * §6.15 — the one pinned element in the design, and the one thing round 7
	 * shipped without: the browser pass only looked at the wide viewport, so a
	 * narrow-only component that was never composed went unnoticed. It is
	 * asserted at both viewports here for exactly that reason.
	 */
	test( 'a priced product pins its buy action on narrow, and only there', async ( {
		page,
	}, testInfo ) => {
		test.skip( ! BUY_URL, 'The shop fixture did not run.' );

		await signIn( page );
		await page.goto( BUY_URL );

		const bar = page.locator( '.gatedmedia-action-bar' );

		if ( testInfo.project.name === 'wide' ) {
			await expect( bar ).toBeHidden();
			return;
		}

		await expect( bar ).toBeVisible();

		// The price is in the button's own label — one control, not a price
		// sitting beside a button.
		await expect( bar ).toContainText( 'Get access' );

		// It submits the buy form it is not inside, by naming it. Without the
		// form attribute this is a button that does nothing without script.
		const id = await page
			.locator( 'form.gatedmedia-buy' )
			.getAttribute( 'id' );

		await expect( bar.locator( 'button' ) ).toHaveAttribute( 'form', id );
		await expect( bar.locator( 'button' ) ).toHaveAttribute(
			'type',
			'submit'
		);

		// And the page reserves room for it rather than hiding its own foot.
		await expect(
			page.locator( '.gatedmedia-view--product.has-action-bar' )
		).toHaveCount( 1 );
	} );

	test( 'the pinned bar never appears on an account view', async ( {
		page,
	} ) => {
		await signIn( page );
		await page.goto( '/account/orders/' );

		// §6.15 is explicit, and nothing but the composer can enforce it.
		await expect( page.locator( '.gatedmedia-action-bar' ) ).toHaveCount(
			0
		);
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

	test( 'an order still waiting on Stripe says so, and claims nothing', async ( {
		page,
	} ) => {
		const uuid = process.env.GATEDMEDIA_PENDING_UUID;

		test.skip( ! uuid, 'The shop fixture did not run.' );

		// The state §7.8 exists for: Stripe has returned the buyer, its webhook
		// has not landed. Every other order in the suite is complete, so this
		// panel had never been drawn on a real page.
		await page.goto( `/account/orders/${ uuid }/?new_order=${ uuid }` );

		await expect(
			page.locator( '.gatedmedia-payment-status--pending' )
		).toBeVisible();
		await expect(
			page.getByText( 'Confirming your payment' )
		).toBeVisible();

		// It must not tell them they are in, and must not offer the way on.
		await expect( page.getByText( "You're in" ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'link', { name: 'Go to my access' } )
		).toHaveCount( 0 );

		// And it is watching rather than sitting there. The panel carries what
		// the poll needs; without these attributes the buyer has to reload by
		// hand, which is what round 7 shipped.
		const panel = page.locator( '.gatedmedia-payment-status--pending' );

		await expect( panel ).toHaveAttribute( 'data-gatedmedia-poll', uuid );
		await expect( panel ).toHaveAttribute(
			'data-gatedmedia-url',
			new RegExp( `/gated-media-access/v1/payment/${ uuid }` )
		);
		await expect( panel ).not.toHaveAttribute(
			'data-gatedmedia-nonce',
			''
		);
	} );

	test( 'the poll asks the route, and a payment that stays pending is never called a failure', async ( {
		page,
	} ) => {
		const uuid = process.env.GATEDMEDIA_PENDING_UUID;

		test.skip( ! uuid, 'The shop fixture did not run.' );

		const asked = [];

		page.on( 'request', ( request ) => {
			if ( request.url().includes( `/payment/${ uuid }` ) ) {
				asked.push( request.url() );
			}
		} );

		await page.goto( `/account/orders/${ uuid }/?new_order=${ uuid }` );

		// It polls without being touched.
		await expect
			.poll( () => asked.length, { timeout: 15_000 } )
			.toBeGreaterThan( 0 );

		// The row never moves, so the page must still be confirming — never an
		// error, and never a claim that access arrived. They have paid either
		// way.
		await expect(
			page.locator( '.gatedmedia-payment-status--pending' )
		).toBeVisible();
		await expect( page.getByText( "You're in" ) ).toHaveCount( 0 );
		await expect(
			page.locator( '.gatedmedia-payment-status--failed' )
		).toHaveCount( 0 );
	} );

	test( 'one failed request does not end the poll', async ( { page } ) => {
		const uuid = process.env.GATEDMEDIA_PENDING_UUID;

		test.skip( ! uuid, 'The shop fixture did not run.' );

		let served = 0;

		// A proxy hiccup or a second offline. The buyer has paid either way,
		// so the panel must keep watching rather than freeze on the spinner.
		await page.route( `**/payment/${ uuid }**`, async ( route ) => {
			served += 1;

			if ( 1 === served ) {
				await route.fulfill( { status: 502, body: 'nope' } );
				return;
			}

			await route.continue();
		} );

		await page.goto( `/account/orders/${ uuid }/?new_order=${ uuid }` );

		await expect
			.poll( () => served, { timeout: 20_000 } )
			.toBeGreaterThan( 1 );
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
