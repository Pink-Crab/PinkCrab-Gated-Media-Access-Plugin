<?php
/**
 * The one class that talks to Stripe.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use Stripe\StripeClient;
use Stripe\Webhook;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Everything Stripe-shaped crosses here and nowhere else: the hosted
 * checkout session on the way out, the verified webhook event on the way
 * in. Credentials come from `Settings`' one reader, mode included, and
 * every SDK exception turns into a WP_Error at this boundary — nothing
 * upstream handles Stripe types.
 *
 * Tests fake this class whole; nothing else in the plugin constructs an
 * SDK object.
 */
class Stripe_Gateway {

	/**
	 * Credentials come from the one reader.
	 *
	 * @param Settings $settings The settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Creates the hosted checkout session for a pending payment.
	 *
	 * The uuid rides as client_reference_id, which is how the webhook's
	 * checkout.session.completed finds its row again.
	 *
	 * @param Payment $payment      The pending row, created before this call.
	 * @param string  $product_name What the checkout page shows.
	 * @param string  $success_url  Where Stripe sends them after paying.
	 * @param string  $cancel_url   Where Stripe sends them if they back out.
	 * @param string  $email        Pre-fills the checkout's email field.
	 * @return array{id: string, url: string}|WP_Error The session, or why not.
	 */
	public function create_checkout_session( Payment $payment, string $product_name, string $success_url, string $cancel_url, string $email ): array|WP_Error {
		$secret = $this->settings->stripe_secret();

		if ( '' === $secret ) {
			return new WP_Error( 'gatedmedia_stripe_unconfigured', __( 'Stripe is not configured.', 'gated-media-access' ) );
		}

		try {
			$session = ( new StripeClient( $secret ) )->checkout->sessions->create(
				array(
					'mode'                => 'payment',
					'client_reference_id' => $payment->uuid,
					'customer_email'      => $email,
					'success_url'         => $success_url,
					'cancel_url'          => $cancel_url,
					'line_items'          => array(
						array(
							'quantity'   => 1,
							'price_data' => array(
								'currency'     => strtolower( $payment->currency ),
								'unit_amount'  => $payment->amount_total,
								'product_data' => array( 'name' => $product_name ),
							),
						),
					),
					'metadata'            => array( 'gatedmedia_payment' => $payment->uuid ),
				)
			);
		} catch ( \Throwable $error ) {
			return new WP_Error( 'gatedmedia_stripe_error', $error->getMessage() );
		}

		return array(
			'id'  => (string) $session->id,
			'url' => (string) $session->url,
		);
	}

	/**
	 * Verifies a webhook delivery and hands back the event.
	 *
	 * The SDK checks the v1 HMAC signature and its timestamp tolerance;
	 * anything wrong — bad JSON, bad signature, stale timestamp, no secret
	 * configured — is one WP_Error, and the route answers 400.
	 *
	 * @param string $payload          The raw request body, exactly as sent.
	 * @param string $signature_header The Stripe-Signature header.
	 * @return \Stripe\Event|WP_Error The verified event, or why not.
	 */
	public function parse_event( string $payload, string $signature_header ): \Stripe\Event|WP_Error {
		$secret = $this->settings->stripe_webhook_secret();

		if ( '' === $secret ) {
			return new WP_Error( 'gatedmedia_stripe_unconfigured', __( 'The webhook secret is not configured.', 'gated-media-access' ) );
		}

		try {
			return Webhook::constructEvent( $payload, $signature_header, $secret );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'gatedmedia_stripe_signature', $error->getMessage() );
		}
	}
}
