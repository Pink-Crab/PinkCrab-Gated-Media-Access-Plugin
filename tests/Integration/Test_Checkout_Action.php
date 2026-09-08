<?php
/**
 * The buy button's target, and where it is allowed to send people.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Exception;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * `wp_safe_redirect()` follows only hosts on core's allow-list and quietly swaps anything else for wp-admin, and the hosted checkout is not always on `checkout.stripe.com` because a Stripe account can serve Checkout from its own domain.
 *
 * The failure these cover is a buyer landing in wp-admin instead of at payment, with a pending row left behind.
 *
 * The session URL is not user input: it comes back from the Stripe API over the site's own secret key.
 *
 * @group integration
 */
class Test_Checkout_Action extends WP_UnitTestCase {

	/** Where the last redirect was aimed. */
	private string $captured = '';

	public function set_up(): void {
		parent::set_up();

		$this->captured = '';

		// handle() exits after redirecting, which would take the runner with it, so throw from the filter first.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->captured = (string) $location;

				throw new Exception( 'redirected' );
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		unset( $_POST['_wpnonce'], $_POST['gatedmedia_product'] );

		parent::tear_down();
	}

	/** @testdox The buyer is sent to Stripe's own hosted checkout host. */
	public function test_the_default_stripe_host_is_followed(): void {
		$url = $this->submit( 'https://checkout.stripe.com/c/pay/cs_test_123' );

		$this->assertSame( 'https://checkout.stripe.com/c/pay/cs_test_123', $url );
	}

	/**
	 * Stripe Checkout can be served from the merchant's own domain, and the allow-list named one host and swapped everything else for wp-admin without a word.
	 *
	 * @testdox A hosted checkout on a Stripe custom domain is followed too, not swapped for wp-admin.
	 */
	public function test_a_custom_checkout_domain_is_followed(): void {
		$url = $this->submit( 'https://pay.example-shop.test/c/pay/cs_test_456' );

		$this->assertSame( 'https://pay.example-shop.test/c/pay/cs_test_456', $url );
		$this->assertStringNotContainsString( 'wp-admin', $url );
	}

	/** @testdox A destination on this site needs no allowance and is followed as it was. */
	public function test_an_on_site_destination_still_works(): void {
		$url = $this->submit( home_url( '/access/the-bundle/' ) );

		$this->assertSame( home_url( '/access/the-bundle/' ), $url );
	}

	/**
	 * Posts the buy form against a checkout answering the given URL, and returns where the handler sent them.
	 *
	 * @param string $redirect What Checkout::purchase() hands back.
	 */
	private function submit( string $redirect ): string {
		$checkout = $this->createMock( Checkout::class );
		$checkout->method( 'purchase' )->willReturn( array( 'redirect' => $redirect ) );

		$_POST['gatedmedia_product'] = (string) self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		$_POST['_wpnonce']           = wp_create_nonce( Checkout_Action::ACTION );

		$action = new Checkout_Action( $checkout, new Settings() );

		add_filter( 'allowed_redirect_hosts', array( $action, 'allow_stripe_host' ) );

		try {
			$action->handle();
		} catch ( Exception $stopped ) {
			return $this->captured;
		} finally {
			remove_filter( 'allowed_redirect_hosts', array( $action, 'allow_stripe_host' ) );
		}

		$this->fail( 'Expected a redirect and got none.' );
	}
}
