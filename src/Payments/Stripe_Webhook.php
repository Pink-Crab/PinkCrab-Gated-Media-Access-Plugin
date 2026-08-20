<?php
/**
 * Stripe's confirmations, and where access actually lands.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Writer;

/**
 * POST `/gated-media-access/v1/stripe/webhook` (spec §4): the signature is
 * the authentication, so `permission_callback` answers true and the
 * gateway's verification decides — anything unverifiable is a 400.
 *
 * **Access lands here and nowhere else** for a paid product (architecture
 * §7). Stripe retries deliveries, and the payment row is its own guard:
 * the conditional status move answers who was first, so a repeated
 * `checkout.session.completed` changes nothing and grants nothing. A
 * refund runs the same guard against complete and revokes every record
 * the payment created.
 */
class Stripe_Webhook implements Hookable {

	/**
	 * The row, the verification, the grants and the revokes.
	 *
	 * @param Payment_Store  $store    The payments table's owner.
	 * @param Stripe_Gateway $gateway  Verifies the delivery.
	 * @param Checkout       $checkout Grants from the snapshot on completion.
	 * @param Access_Writer  $writer   The one writer of access records.
	 * @param Access_Lookup  $lookup   Finds what a payment granted, for the refund.
	 */
	public function __construct(
		private Payment_Store $store,
		private Stripe_Gateway $gateway,
		private Checkout $checkout,
		private Access_Writer $writer,
		private Access_Lookup $lookup
	) {
	}

	/**
	 * The route.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Registers the webhook endpoint. Open on purpose: the signature check
	 * inside the callback is the authentication.
	 */
	public function register_route(): void {
		register_rest_route(
			Payment_Status_Route::ROUTE_NAMESPACE,
			'/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * One delivery: verify, announce, act on the types we know, always 200
	 * for a verified event — Stripe retries anything else.
	 *
	 * @param WP_REST_Request $request The delivery.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$event = $this->gateway->parse_event( (string) $request->get_body(), (string) $request->get_header( 'stripe-signature' ) );

		if ( $event instanceof WP_Error ) {
			return new WP_REST_Response( array( 'error' => $event->get_error_message() ), 400 );
		}

		/**
		 * Fires for every verified Stripe event, whatever its type.
		 *
		 * @param \Stripe\Event $event The verified event.
		 */
		do_action( 'gatedmedia_stripe_event', $event );

		$object = $event->data->object ?? null;

		if ( null !== $object ) {
			match ( $event->type ) {
				'checkout.session.completed' => $this->complete( $object ),
				'checkout.session.expired'   => $this->expire( $object ),
				'charge.refunded'            => $this->refund( $object ),
				default                      => null,
			};
		}

		return new WP_REST_Response( array( 'received' => true ) );
	}

	/**
	 * A confirmed checkout: move the row exactly once, and only the mover
	 * grants — a repeated delivery changes nothing.
	 *
	 * @param \Stripe\StripeObject $session The event's checkout session.
	 */
	private function complete( \Stripe\StripeObject $session ): void {
		$uuid    = (string) ( $session['client_reference_id'] ?? '' );
		$payment = $this->store->find_by_uuid( $uuid );

		if ( null === $payment ) {
			return;
		}

		$intent = $session['payment_intent'] ?? '';
		$intent = is_string( $intent ) ? $intent : (string) ( $intent->id ?? '' );

		if ( ! $this->store->mark_complete( $uuid, $intent ) ) {
			return;
		}

		$this->checkout->grant_snapshot( $payment );

		/**
		 * Fires once a payment has completed and its access has landed.
		 *
		 * @param int $payment_id The payment's row id.
		 */
		do_action( 'gatedmedia_payment_completed', $payment->payment_id );
	}

	/**
	 * A checkout that ended without payment: pending → failed, and the
	 * guard means a late completion cannot resurrect it.
	 *
	 * @param \Stripe\StripeObject $session The event's checkout session.
	 */
	private function expire( \Stripe\StripeObject $session ): void {
		$this->store->mark_failed( (string) ( $session['client_reference_id'] ?? '' ) );
	}

	/**
	 * A refund: complete → refunded exactly once, then every record the
	 * payment created is revoked (architecture §7).
	 *
	 * @param \Stripe\StripeObject $charge The event's charge.
	 */
	private function refund( \Stripe\StripeObject $charge ): void {
		$intent  = $charge['payment_intent'] ?? '';
		$intent  = is_string( $intent ) ? $intent : (string) ( $intent->id ?? '' );
		$payment = $this->store->find_by_intent( $intent );

		if ( null === $payment || ! $this->store->mark_refunded( $payment->uuid ) ) {
			return;
		}

		foreach ( $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) as $access_id ) {
			$this->writer->revoke( $access_id );
		}

		/**
		 * Fires once a payment has been refunded and its access revoked.
		 *
		 * @param int $payment_id The payment's row id.
		 */
		do_action( 'gatedmedia_payment_refunded', $payment->payment_id );
	}
}
