<?php
/**
 * The buy button's target.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * The admin-post action a product page's buy form submits to. Thin on
 * purpose: read the form, hand it to `Checkout`, follow its answer —
 * to Stripe's hosted page, straight to the product for a free claim, or
 * back to the product carrying the error flag. Round 6's product page
 * renders the form; until then anything can POST it (the e2e specs do).
 *
 * Signed out, the wp-login round trip comes back here and continues.
 */
class Checkout_Action implements Hookable {

	/** The admin-post action name, and the nonce it demands. */
	public const ACTION = 'gatedmedia_checkout';

	/** The query flag a failed checkout returns under. */
	public const ERROR_FLAG = 'gatedmedia_checkout_error';

	/**
	 * The coupon's field name — posted with the buy form, and the query arg
	 * Apply reloads the product page with. One name for both, so the applied
	 * code and the typed code are never two different things.
	 */
	public const COUPON_FIELD = 'gatedmedia_coupon';

	/**
	 * The flow lives in Checkout; this class only fronts it.
	 *
	 * @param Checkout $checkout The purchase flow.
	 * @param Settings $settings Decides whether signing up is offered.
	 */
	public function __construct( private Checkout $checkout, private Settings $settings ) {
	}

	/**
	 * The handler, its signed-out mirror, and Stripe's host on the redirect
	 * allow-list.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		$loader->action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'require_login' ) );
		$loader->filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_host' ) );
	}

	/**
	 * Core's wp_safe_redirect() only follows hosts it knows; the hosted
	 * checkout lives on Stripe's.
	 *
	 * @param array<int, string> $hosts Core's allow-list.
	 * @return array<int, string>
	 */
	public function allow_stripe_host( array $hosts ): array {
		$hosts[] = 'checkout.stripe.com';

		return $hosts;
	}

	/**
	 * One buy submit: nonce, then Checkout decides, then the redirect.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body
	 * after the Location header.
	 */
	public function handle(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( false === wp_verify_nonce( $nonce, self::ACTION ) ) {
			wp_die( esc_html__( 'The checkout link has expired. Go back and try again.', 'gated-media-access' ), '', 403 );
		}

		$product_id = isset( $_POST['gatedmedia_product'] ) ? absint( wp_unslash( $_POST['gatedmedia_product'] ) ) : 0;
		$coupon     = isset( $_POST[ self::COUPON_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::COUPON_FIELD ] ) ) : '';

		$outcome = $this->checkout->purchase( $product_id, get_current_user_id(), $coupon );

		if ( $outcome instanceof WP_Error ) {
			wp_safe_redirect( add_query_arg( self::ERROR_FLAG, $outcome->get_error_code(), (string) get_permalink( $product_id ) ) );
			exit;
		}

		wp_safe_redirect( $outcome['redirect'] );
		exit;
	}

	/**
	 * Signed out: to the plugin's own way in, and back to the product after.
	 *
	 * The control that reaches here says "Create an account to continue", so it
	 * goes to sign-up when this site creates accounts that way and to sign-in
	 * when it does not — a page must never offer a route that does not exist.
	 *
	 * The coupon rides along on the destination. Before round 9 it was dropped
	 * on the way through, so a buyer who had applied one came back to the
	 * product at full price with no sign of what had happened.
	 */
	public function require_login(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nothing changes; the fields only shape the post-login destination.
		$product_id = isset( $_POST['gatedmedia_product'] ) ? absint( wp_unslash( $_POST['gatedmedia_product'] ) ) : 0;
		$coupon     = isset( $_POST[ self::COUPON_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::COUPON_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$product = (string) get_permalink( $product_id );

		if ( '' !== $coupon && '' !== $product ) {
			$product = add_query_arg( self::COUPON_FIELD, $coupon, $product );
		}

		$signup = Settings::ACCOUNT_CREATION_REGISTRATION === $this->settings->account_creation();

		wp_safe_redirect( $signup ? Auth_Url::signup( $product ) : Auth_Url::signin( $product ) );
		exit;
	}
}
