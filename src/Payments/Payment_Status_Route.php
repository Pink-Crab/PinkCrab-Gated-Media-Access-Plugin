<?php
/**
 * The route the return page polls.
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

/**
 * GET `/gated-media-access/v1/payment/{uuid}` (spec §4): the payment's
 * status and nothing else. The return page polls it while Stripe's
 * confirmation is in flight — it never writes and never asks Stripe.
 *
 * Owner only, and an unknown uuid and someone else's payment answer the
 * same 404 — the route confirms nothing about payments that are not yours.
 */
class Payment_Status_Route implements Hookable {

	/** The REST namespace every route this plugin registers lives under. */
	public const ROUTE_NAMESPACE = 'gated-media-access/v1';

	/**
	 * Reads only.
	 *
	 * @param Payment_Store $store The payments table's owner.
	 */
	public function __construct( private Payment_Store $store ) {
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
	 * Registers the poll endpoint, signed-in only — ownership is the
	 * callback's check, since it needs the row.
	 */
	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/payment/(?P<uuid>[a-f0-9-]{36})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'uuid' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The status, for the payment's owner.
	 *
	 * @param WP_REST_Request $request The poll.
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payment = $this->store->find_by_uuid( (string) $request->get_param( 'uuid' ) );

		if ( null === $payment || get_current_user_id() !== $payment->user_id ) {
			return new WP_Error(
				'gatedmedia_no_payment',
				__( 'No such payment.', 'gated-media-access' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'status' => $payment->status ) );
	}
}
