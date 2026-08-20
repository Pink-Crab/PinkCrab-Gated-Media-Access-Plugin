<?php
/**
 * The Stripe webhook.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Stripe\WebhookSignature;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Payments\Stripe_Webhook;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Deliveries signed with the SDK's own header generator, verified by the
 * gateway for real. The row is the replay guard: a repeated completion
 * grants nothing twice, a repeated refund revokes nothing twice, and
 * access lands only when the confirmation moves the row.
 *
 * @group integration
 */
class Test_Stripe_Webhook extends WP_UnitTestCase {

	private const SECRET = 'whsec_test_secret';

	private Payment_Store $store;

	private Access_Lookup $lookup;

	private int $buyer_id;

	private int $post_item;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		update_option( Settings::OPTION, array( 'stripe_test_webhook_secret' => self::SECRET ) );

		// The framework's tear_down unregisters every meta key.
		$writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$writer->register_meta();
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();

		$this->store     = new Payment_Store();
		$this->lookup    = new Access_Lookup();
		$this->buyer_id  = self::factory()->user->create();
		$this->post_item = self::factory()->post->create();

		$gateway  = new Stripe_Gateway( new Settings() );
		$checkout = new Checkout( $this->store, $writer, $gateway );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		add_action(
			'rest_api_init',
			array( new Stripe_Webhook( $this->store, $gateway, $checkout, $writer, $this->lookup ), 'register_route' )
		);
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/** @testdox A delivery whose signature does not verify is a 400 and does nothing. */
	public function test_bad_signature_is_refused(): void {
		$payment  = $this->pending_payment();
		$body     = (string) wp_json_encode( $this->completed_event( $payment->uuid ) );
		$response = $this->deliver( $body, 't=1,v1=nonsense' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Payment::STATUS_PENDING, $this->store->find_by_uuid( $payment->uuid )->status );
	}

	/** @testdox The confirmation completes the row, grants the snapshot, and announces — exactly once. */
	public function test_completion_grants_once(): void {
		$payment = $this->pending_payment();

		$completed = array();
		add_action(
			'gatedmedia_payment_completed',
			static function ( int $payment_id ) use ( &$completed ): void {
				$completed[] = $payment_id;
			}
		);

		$body = (string) wp_json_encode( $this->completed_event( $payment->uuid ) );

		$this->assertSame( 200, $this->deliver( $body )->get_status() );

		$read = $this->store->find_by_uuid( $payment->uuid );

		$this->assertSame( Payment::STATUS_COMPLETE, $read->status );
		$this->assertSame( 'pi_9', $read->stripe_payment_intent_id );
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
		$this->assertSame( array( $payment->payment_id ), $completed );

		// Stripe retries: the same delivery again changes and grants nothing.
		$this->assertSame( 200, $this->deliver( $body )->get_status() );
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
		$this->assertSame( array( $payment->payment_id ), $completed );
	}

	/** @testdox An expired checkout fails the row, and a late confirmation cannot resurrect it. */
	public function test_expiry_fails_the_row(): void {
		$payment = $this->pending_payment();

		$this->deliver( (string) wp_json_encode( $this->event( 'checkout.session.expired', array( 'client_reference_id' => $payment->uuid ) ) ) );

		$this->assertSame( Payment::STATUS_FAILED, $this->store->find_by_uuid( $payment->uuid )->status );

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid ) ) );

		$this->assertSame( Payment::STATUS_FAILED, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertCount( 0, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/** @testdox A refund revokes every record the payment created and announces — exactly once. */
	public function test_refund_revokes(): void {
		$payment = $this->pending_payment();
		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid ) ) );

		$refunded = array();
		add_action(
			'gatedmedia_payment_refunded',
			static function ( int $payment_id ) use ( &$refunded ): void {
				$refunded[] = $payment_id;
			}
		);

		$refund_body = (string) wp_json_encode( $this->event( 'charge.refunded', array( 'payment_intent' => 'pi_9' ), 'charge' ) );

		$this->assertSame( 200, $this->deliver( $refund_body )->get_status() );
		$this->assertSame( Payment::STATUS_REFUNDED, $this->store->find_by_uuid( $payment->uuid )->status );

		$records = $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid );

		$this->assertCount( 1, $records );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $records[0] ) );
		$this->assertSame( array( $payment->payment_id ), $refunded );

		// A retried refund changes nothing.
		$this->deliver( $refund_body );
		$this->assertSame( array( $payment->payment_id ), $refunded );
	}

	/** @testdox A confirmation for a payment that is not ours is acknowledged and ignored. */
	public function test_unknown_payment_is_ignored(): void {
		$body = (string) wp_json_encode( $this->completed_event( '00000000-0000-4000-8000-000000000000' ) );

		$this->assertSame( 200, $this->deliver( $body )->get_status() );
	}

	/** @testdox Every verified event fires gatedmedia_stripe_event, whatever its type. */
	public function test_every_event_announced(): void {
		$seen = array();
		add_action(
			'gatedmedia_stripe_event',
			static function ( $event ) use ( &$seen ): void {
				$seen[] = $event->type;
			}
		);

		$this->deliver( (string) wp_json_encode( $this->event( 'invoice.paid', array() ) ) );

		$this->assertSame( array( 'invoice.paid' ), $seen );
	}

	/**
	 * A pending payment for one product whose snapshot holds one post.
	 */
	private function pending_payment(): Payment {
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );

		return $this->store->create_pending( $this->buyer_id, $product_id, 1000, 'GBP', array( "post:{$this->post_item}" ) );
	}

	/**
	 * A checkout.session.completed event body for a payment.
	 *
	 * @param string $uuid The payment it confirms.
	 * @return array<string, mixed>
	 */
	private function completed_event( string $uuid ): array {
		return $this->event(
			'checkout.session.completed',
			array(
				'client_reference_id' => $uuid,
				'payment_intent'      => 'pi_9',
			)
		);
	}

	/**
	 * A minimal Stripe event envelope.
	 *
	 * @param string               $type        The event type.
	 * @param array<string, mixed> $data_object The data.object payload.
	 * @param string               $object_kind What data.object claims to be.
	 * @return array<string, mixed>
	 */
	private function event( string $type, array $data_object, string $object_kind = 'checkout.session' ): array {
		return array(
			'id'     => 'evt_1',
			'object' => 'event',
			'type'   => $type,
			'data'   => array(
				'object' => array_merge( array( 'object' => $object_kind ), $data_object ),
			),
		);
	}

	/**
	 * One signed delivery to the route.
	 *
	 * @param string      $body   The raw JSON body.
	 * @param string|null $header A signature header, or null to sign correctly.
	 */
	private function deliver( string $body, ?string $header = null ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gated-media-access/v1/stripe/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'stripe-signature', $header ?? WebhookSignature::generateSignatureHeader( $body, self::SECRET ) );
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}
}
