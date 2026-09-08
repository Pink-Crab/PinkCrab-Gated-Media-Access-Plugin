<?php
/**
 * The one class that talks to Stripe.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Stripe\Webhook;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Everything Stripe-shaped crosses here and nowhere else: the hosted checkout session on the way out, the verified webhook event on the way in.
 *
 * Credentials come from `Settings`' one reader, mode included, and every SDK exception turns into a WP_Error at this boundary, so nothing upstream handles Stripe types.
 *
 * Tests fake this class whole, and nothing else in the plugin constructs an SDK object.
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
	 * What the SDK's HTTP client is pinned to.
	 *
	 * The call runs inline in the buyer's request, so a Stripe stall would otherwise hold a PHP worker for the SDK's own 80 second default.
	 *
	 * @return array<string, int>
	 */
	public function client_config(): array {
		$config = array(
			'timeout'             => 10,
			'connect_timeout'     => 5,
			'max_network_retries' => 2,
		);

		/**
		 * Filters the Stripe client's timeouts and retry count.
		 *
		 * @param array<string, int> $config Seconds, and how many retries.
		 */
		return array_map( 'absint', (array) apply_filters( 'gatedmedia_stripe_client_config', $config ) );
	}

	/**
	 * The SDK client, with its timeouts applied.
	 *
	 * @param string $secret The API key.
	 */
	private function client( string $secret ): StripeClient {
		$config = $this->client_config();

		// The retries are the client's own, and the timeouts belong to the curl client the SDK reaches through ApiRequestor.
		$curl = new CurlClient();
		$curl->setTimeout( $config['timeout'] );
		$curl->setConnectTimeout( $config['connect_timeout'] );

		ApiRequestor::setHttpClient( $curl );

		return new StripeClient(
			array(
				'api_key'             => $secret,
				'max_network_retries' => $config['max_network_retries'],
			)
		);
	}

	/**
	 * Creates the hosted checkout session for a pending payment.
	 *
	 * The uuid rides as client_reference_id, which is how the webhook's checkout.session.completed finds its row again.
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
			$session = $this->client( $secret )->checkout->sessions->create(
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
	 * The SDK checks the v1 HMAC signature and its timestamp tolerance, and anything wrong is one WP_Error the route answers 400 to.
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
