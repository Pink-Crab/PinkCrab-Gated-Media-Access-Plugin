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
use PinkCrab\Gated_Access\Payments\Coupon_Hold;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Payments\Stripe_Webhook;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Deliveries signed with the SDK's own header generator and verified by the gateway for real.
 *
 * The row is the replay guard: a repeated completion grants nothing twice, a repeated refund revokes nothing twice, and access lands only when the confirmation moves the row.
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
		$checkout = new Checkout( $this->store, $writer, $gateway, new Resolver( new Access_Taxonomy() ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		add_action(
			'rest_api_init',
			array( new Stripe_Webhook( $this->store, $gateway, $checkout, $writer ), 'register_route' )
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

	/** @testdox The confirmation completes the row, grants the snapshot, and announces, exactly once. */
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

	/** @testdox A refund revokes every record the payment created and announces, exactly once. */
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

	/**
	 * @testdox Refunding a renewal takes back only the days it bought, and leaves the rest standing.
	 *
	 * The whole purchase path, not just the writer: two payments for the same timed product, the second stacking onto the first's live record, then a refund of the second.
	 *
	 * `stack_onto_live()` used to write only the expiry, so no record carried the second payment's uuid and the refund revoked nothing.
	 */
	public function test_refunding_a_renewal_takes_back_only_its_own_days(): void {
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		update_post_meta( $product_id, Product_Meta::META_DURATION, 20 );

		$first  = $this->store->create_pending( $this->buyer_id, $product_id, 1000, 'GBP', array( "post:{$this->post_item}" ) );
		$second = $this->store->create_pending( $this->buyer_id, $product_id, 1000, 'GBP', array( "post:{$this->post_item}" ) );

		$this->deliver( (string) wp_json_encode( $this->completed_event( $first->uuid ) ) );
		$this->deliver( (string) wp_json_encode( $this->session_event( 'checkout.session.completed', $second->uuid, 'paid', 'pi_10' ) ) );

		$records = $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $second->uuid );

		$this->assertCount( 1, $records, 'the renewal stacked, and the record must answer to its reference' );

		$record = $records[0];

		// 20 + 20 days stand before the refund.
		$this->deliver(
			(string) wp_json_encode(
				$this->event( 'charge.refunded', array( 'payment_intent' => 'pi_10' ), 'charge' )
			)
		);

		$this->assertSame(
			Post_Types::STATUS_ACTIVE,
			get_post_status( $record ),
			'the first payment still stands, so the record must not be revoked'
		);

		$expires = (string) get_post_meta( $record, Access_Writer::META_EXPIRES_AT, true );

		$this->assertEqualsWithDelta(
			time() + 20 * DAY_IN_SECONDS,
			strtotime( $expires . ' +0000' ),
			5,
			'40 days less the refunded 20 leaves 20'
		);
	}

	/**
	 * @testdox A grant that fails leaves the row pending and asks Stripe to deliver again.
	 *
	 * The row used to move to complete before anything was granted, so a snapshot item deleted between purchase and confirmation produced a paid, complete payment with no access that Stripe's retry could never repair.
	 *
	 * Granting first is safe, because the writer's per-item reference guard means a redelivery writes nothing twice.
	 */
	public function test_a_failed_grant_keeps_the_row_pending_and_asks_for_a_retry(): void {
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		$doomed     = self::factory()->post->create();

		$payment = $this->store->create_pending(
			$this->buyer_id,
			$product_id,
			1000,
			'GBP',
			array( "post:{$this->post_item}", "post:{$doomed}" )
		);

		wp_delete_post( $doomed, true );

		$body = (string) wp_json_encode( $this->completed_event( $payment->uuid ) );

		$this->assertSame( 500, $this->deliver( $body )->get_status(), 'a 200 tells Stripe never to try again' );

		$read = $this->store->find_by_uuid( $payment->uuid );

		$this->assertSame( Payment::STATUS_PENDING, $read->status, 'the row must stay retryable' );
		$this->assertNotSame( '', $read->grant_error, 'the cause must be recorded where an administrator can see it' );

		// The item that could be granted still was.
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );

		// Stripe retries: the same failure, and the good item is not written twice.
		$this->assertSame( 500, $this->deliver( $body )->get_status() );
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/**
	 * @testdox A refusal tells an unauthenticated caller nothing about the site.
	 *
	 * The route is open on purpose, since the signature is the authentication, so anyone can post to it. It used to answer with the SDK's exception text, which tells a stranger the plugin is installed and whether Stripe is set up.
	 *
	 * The detail belongs in the 500, which only a caller holding the webhook secret can reach.
	 */
	public function test_a_refusal_gives_nothing_away(): void {
		$payment = $this->pending_payment();
		$body    = (string) wp_json_encode( $this->completed_event( $payment->uuid ) );

		$refused = $this->deliver( $body, 't=1,v1=nonsense' );

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame(
			array( 'error' => Stripe_Webhook::REFUSED ),
			$refused->get_data(),
			'the body must be one fixed token, whatever went wrong'
		);

		// An unconfigured shop answers the same, so the two look identical from outside.
		delete_option( Settings::OPTION );

		$this->assertSame( array( 'error' => Stripe_Webhook::REFUSED ), $this->deliver( $body, 't=1,v1=nonsense' )->get_data() );
	}

	/**
	 * @testdox A late confirmation cannot re-grant a payment that was refunded.
	 *
	 * Granting before the status move makes the row's own status the guard, not `mark_complete()`, and only a pending row grants.
	 */
	public function test_a_late_completion_cannot_regrant_a_refunded_payment(): void {
		$payment = $this->pending_payment();

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid ) ) );
		$this->deliver( (string) wp_json_encode( $this->event( 'charge.refunded', array( 'payment_intent' => 'pi_9' ), 'charge' ) ) );

		$records = $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid );

		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $records[0] ) );

		$this->assertSame( 200, $this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid ) ) )->get_status() );

		$this->assertSame( Payment::STATUS_REFUNDED, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertSame(
			Post_Types::STATUS_REVOKED,
			get_post_status( $records[0] ),
			'a redelivery must not resurrect access that was refunded'
		);
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
	 * @testdox A completed checkout whose money has not been collected grants nothing and stays pending.
	 *
	 * Stripe completes the session for a delayed method before the money is collected and says so in `payment_status`, so granting on the event alone hands over what was bought before it is paid for.
	 */
	public function test_an_unpaid_completion_grants_nothing(): void {
		$payment = $this->pending_payment();

		$response = $this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, 'unpaid' ) ) );

		$this->assertSame( 200, $response->get_status(), 'there is nothing for Stripe to retry, the money is simply not here yet' );
		$this->assertSame( Payment::STATUS_PENDING, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertCount( 0, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/** @testdox A session that says nothing about the money grants nothing. */
	public function test_a_completion_without_a_payment_status_grants_nothing(): void {
		$payment = $this->pending_payment();

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, '' ) ) );

		$this->assertSame( Payment::STATUS_PENDING, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertCount( 0, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/** @testdox A checkout that needed no payment at all still grants, because a coupon can take the price to nothing. */
	public function test_a_checkout_needing_no_payment_grants(): void {
		$payment = $this->pending_payment();

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, 'no_payment_required' ) ) );

		$this->assertSame( Payment::STATUS_COMPLETE, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/** @testdox The money landing later grants the access and completes the row, exactly once. */
	public function test_the_money_landing_later_grants(): void {
		$payment = $this->pending_payment();

		$completed = array();
		add_action(
			'gatedmedia_payment_completed',
			static function ( int $payment_id ) use ( &$completed ): void {
				$completed[] = $payment_id;
			}
		);

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, 'unpaid' ) ) );

		$this->assertSame( Payment::STATUS_PENDING, $this->store->find_by_uuid( $payment->uuid )->status, 'nothing may land before the money does' );

		$body = (string) wp_json_encode( $this->session_event( 'checkout.session.async_payment_succeeded', $payment->uuid ) );

		$this->assertSame( 200, $this->deliver( $body )->get_status() );

		$read = $this->store->find_by_uuid( $payment->uuid );

		$this->assertSame( Payment::STATUS_COMPLETE, $read->status );
		$this->assertSame( 'pi_9', $read->stripe_payment_intent_id );
		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
		$this->assertSame( array( $payment->payment_id ), $completed );

		// Stripe retries: the same delivery again grants nothing twice.
		$this->deliver( $body );

		$this->assertCount( 1, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
		$this->assertSame( array( $payment->payment_id ), $completed );
	}

	/**
	 * @testdox A delayed payment that fails leaves the row failed, gives the coupon back, and cannot then complete.
	 */
	public function test_a_delayed_payment_that_fails_releases_everything(): void {
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		$coupon_id  = self::factory()->post->create( array( 'post_type' => Post_Types::COUPON ) );
		$payment    = $this->store->create_pending( $this->buyer_id, $product_id, 1000, 'GBP', array( "post:{$this->post_item}" ), $coupon_id, 200 );
		$holds      = new Coupon_Hold();

		$holds->take( $coupon_id, $payment->uuid, 1 );

		$this->assertSame( 1, $holds->live( $coupon_id ), 'the checkout in flight reserves the coupon' );

		$this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, 'unpaid' ) ) );
		$this->deliver( (string) wp_json_encode( $this->session_event( 'checkout.session.async_payment_failed', $payment->uuid, 'unpaid' ) ) );

		$this->assertSame( Payment::STATUS_FAILED, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertSame( 0, $holds->live( $coupon_id ), 'a payment that failed must not go on holding the coupon' );
		$this->assertCount( 0, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );

		// A success arriving after the failure cannot resurrect it.
		$this->deliver( (string) wp_json_encode( $this->session_event( 'checkout.session.async_payment_succeeded', $payment->uuid ) ) );

		$this->assertSame( Payment::STATUS_FAILED, $this->store->find_by_uuid( $payment->uuid )->status );
		$this->assertCount( 0, $this->lookup->records_for_reference( Checkout::SOURCE_STRIPE, $payment->uuid ) );
	}

	/** @testdox A grant that fails as the money lands asks Stripe to deliver again. */
	public function test_a_failed_grant_on_the_money_landing_asks_for_a_retry(): void {
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		$doomed     = self::factory()->post->create();

		$payment = $this->store->create_pending(
			$this->buyer_id,
			$product_id,
			1000,
			'GBP',
			array( "post:{$this->post_item}", "post:{$doomed}" )
		);

		wp_delete_post( $doomed, true );

		$unpaid = $this->deliver( (string) wp_json_encode( $this->completed_event( $payment->uuid, 'unpaid' ) ) );

		$this->assertSame( 200, $unpaid->get_status(), 'nothing was attempted, so there is nothing to retry' );
		$this->assertSame( '', $this->store->find_by_uuid( $payment->uuid )->grant_error );

		$body = (string) wp_json_encode( $this->session_event( 'checkout.session.async_payment_succeeded', $payment->uuid ) );

		$this->assertSame( 500, $this->deliver( $body )->get_status(), 'a 200 tells Stripe never to try again' );

		$read = $this->store->find_by_uuid( $payment->uuid );

		$this->assertSame( Payment::STATUS_PENDING, $read->status, 'the row must stay retryable' );
		$this->assertNotSame( '', $read->grant_error, 'the cause must be recorded where an administrator can see it' );
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
	 * @param string $uuid           The payment it confirms.
	 * @param string $payment_status Where Stripe says the money has got to.
	 * @return array<string, mixed>
	 */
	private function completed_event( string $uuid, string $payment_status = 'paid' ): array {
		return $this->session_event( 'checkout.session.completed', $uuid, $payment_status );
	}

	/**
	 * Any checkout session event, carrying what Stripe carries: the payment it belongs to, its intent, and where the money has got to.
	 *
	 * @param string $type           The event type.
	 * @param string $uuid           The payment it belongs to.
	 * @param string $payment_status Where the money has got to, '' to leave the field out.
	 * @param string $intent         The payment intent id.
	 * @return array<string, mixed>
	 */
	private function session_event( string $type, string $uuid, string $payment_status = 'paid', string $intent = 'pi_9' ): array {
		$session = array(
			'client_reference_id' => $uuid,
			'payment_intent'      => $intent,
		);

		if ( '' !== $payment_status ) {
			$session['payment_status'] = $payment_status;
		}

		return $this->event( $type, $session );
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
