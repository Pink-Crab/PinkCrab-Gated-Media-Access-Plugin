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
use PinkCrab\Gated_Access\Access\Access_Writer;

/**
 * POST `/gated-media-access/v1/stripe/webhook` (spec §4): the signature is
 * the authentication, so `permission_callback` answers true and the
 * gateway's verification decides — anything unverifiable is a 400.
 *
 * **Access lands here and nowhere else** for a paid product (architecture
 * §7). Stripe retries deliveries, and the payment row is its own guard:
 * only a pending row grants, and the conditional status move answers who
 * was first, so a repeated `checkout.session.completed` changes nothing
 * and announces nothing. A refund runs the same guard against complete
 * and revokes every record the payment created.
 *
 * Grants run **before** the status move, and a failure answers 500. The
 * status move used to come first and grant failures were discarded, which
 * left a buyer charged against a row reading complete with no access —
 * and, because that move is the replay guard, Stripe's retry returned
 * early and could never repair it. Answering non-2xx is what makes
 * Stripe's own retry the repair, and the writer's per-item reference
 * guard is what makes re-granting safe.
 */
class Stripe_Webhook implements Hookable {

	/**
	 * What a refused delivery is told, and all it is told.
	 *
	 * `permission_callback` is `__return_true`, so anyone can post here.
	 * Echoing the verification failure told a stranger whether the plugin
	 * was configured and which check it tripped; one fixed token tells them
	 * only that the endpoint exists. The cause goes to the 500, which needs
	 * the webhook secret to reach, and to the payment's own row.
	 */
	public const REFUSED = 'refused';

	/**
	 * The row, the verification, the grants and the revokes.
	 *
	 * @param Payment_Store  $store    The payments table's owner.
	 * @param Stripe_Gateway $gateway  Verifies the delivery.
	 * @param Checkout       $checkout Grants from the snapshot on completion.
	 * @param Access_Writer  $writer   The one writer of access records.
	 */
	public function __construct(
		private Payment_Store $store,
		private Stripe_Gateway $gateway,
		private Checkout $checkout,
		private Access_Writer $writer
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
	 * One delivery: verify, announce, act on the types we know. 200 when
	 * there is nothing left to do, 500 when a grant failed and Stripe should
	 * deliver again, 400 when the delivery does not verify.
	 *
	 * @param WP_REST_Request $request The delivery.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$event = $this->gateway->parse_event( (string) $request->get_body(), (string) $request->get_header( 'stripe-signature' ) );

		if ( $event instanceof WP_Error ) {
			return new WP_REST_Response( array( 'error' => self::REFUSED ), 400 );
		}

		/**
		 * Fires for every verified Stripe event, whatever its type.
		 *
		 * @param \Stripe\Event $event The verified event.
		 */
		do_action( 'gatedmedia_stripe_event', $event );

		$object = $event->data->object ?? null;

		if ( null === $object ) {
			return new WP_REST_Response( array( 'received' => true ) );
		}

		// Only the completion can fail in a way Stripe should act on: an
		// expiry and a refund have nothing left to retry.
		$failed = null;

		switch ( $event->type ) {
			case 'checkout.session.completed':
				$failed = $this->complete( $object );
				break;
			case 'checkout.session.expired':
				$this->expire( $object );
				break;
			case 'charge.refunded':
				$this->refund( $object );
				break;
			default:
				break;
		}

		if ( $failed instanceof WP_Error ) {
			// Only reachable with a valid signature, so the cause is safe to
			// name: it is the only place it shows in Stripe's own event log.
			return new WP_REST_Response( array( 'error' => $failed->get_error_message() ), 500 );
		}

		return new WP_REST_Response( array( 'received' => true ) );
	}

	/**
	 * A confirmed checkout: grant first, then move the row, and only the
	 * mover announces.
	 *
	 * Pending is the gate on granting at all, so a redelivery for a row that
	 * has already completed, failed or been refunded writes nothing — and a
	 * refunded payment cannot be resurrected by a late confirmation. A grant
	 * that fails leaves the row pending and comes back as an error, so the
	 * route answers 500 and Stripe delivers again.
	 *
	 * @param \Stripe\StripeObject $session The event's checkout session.
	 * @return WP_Error|null Null when there is nothing for Stripe to retry.
	 */
	private function complete( \Stripe\StripeObject $session ): ?WP_Error {
		$uuid    = (string) ( $session['client_reference_id'] ?? '' );
		$payment = $this->store->find_by_uuid( $uuid );

		if ( null === $payment || Payment::STATUS_PENDING !== $payment->status ) {
			return null;
		}

		$failed = $this->checkout->grant_snapshot( $payment );

		if ( $failed instanceof WP_Error ) {
			$this->store->record_grant_error( $uuid, $failed->get_error_message() );

			return $failed;
		}

		$this->store->record_grant_error( $uuid, '' );

		$intent = $session['payment_intent'] ?? '';
		$intent = is_string( $intent ) ? $intent : (string) ( $intent->id ?? '' );

		if ( ! $this->store->mark_complete( $uuid, $intent ) ) {
			return null;
		}

		// The completion is the coupon's real count from here, so the
		// reservation standing in for it is given back.
		$this->checkout->release_hold( $payment );

		/**
		 * Fires once a payment has completed and its access has landed.
		 *
		 * @param int $payment_id The payment's row id.
		 */
		do_action( 'gatedmedia_payment_completed', $payment->payment_id );

		return null;
	}

	/**
	 * A checkout that ended without payment: pending → failed, and the
	 * guard means a late completion cannot resurrect it. Whatever coupon it
	 * was holding goes back at the same time, rather than waiting out its
	 * own expiry.
	 *
	 * @param \Stripe\StripeObject $session The event's checkout session.
	 */
	private function expire( \Stripe\StripeObject $session ): void {
		$uuid    = (string) ( $session['client_reference_id'] ?? '' );
		$payment = $this->store->find_by_uuid( $uuid );

		if ( null === $payment || ! $this->store->mark_failed( $uuid ) ) {
			return;
		}

		$this->checkout->release_hold( $payment );
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

		// Not a blanket revoke: a renewal stacks onto a live record, so the
		// record can owe its time to more than one payment. The writer takes
		// back this payment's days and revokes only when nothing is left.
		$this->writer->refund( Checkout::SOURCE_STRIPE, $payment->uuid );

		/**
		 * Fires once a payment has been refunded and its access revoked.
		 *
		 * @param int $payment_id The payment's row id.
		 */
		do_action( 'gatedmedia_payment_refunded', $payment->payment_id );
	}
}
