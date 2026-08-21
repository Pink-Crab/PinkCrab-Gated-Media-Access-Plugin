<?php
/**
 * The checkout flow.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Account\Order_History;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Architecture §7 as assertions: the pending row exists before Stripe is
 * asked anything; no access is granted on the way out for a priced
 * product; a free product grants directly with no row; a coupon spends at
 * completion and can take a payment to zero on the spot; the allow-list
 * and its filter gate who may buy at all.
 *
 * Stripe itself is faked at the boundary — the gateway subclass below
 * answers a canned session and records what it was asked.
 *
 * @group integration
 */
class Test_Checkout extends WP_UnitTestCase {

	private Payment_Store $store;

	private Access_Writer $writer;

	private int $buyer_id;

	private int $post_item;

	private int $file_item;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		// The framework's tear_down unregisters every meta key.
		( new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() ) )->register_meta();
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();
		( new Coupon_Metabox() )->register_meta();

		$this->store     = new Payment_Store();
		$this->writer    = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->buyer_id  = self::factory()->user->create( array( 'user_email' => 'buyer@example.com' ) );
		$this->post_item = self::factory()->post->create();
		$this->file_item = self::factory()->attachment->create();
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_product_eligibility' );
		remove_all_filters( 'gatedmedia_coupon_valid' );
		remove_all_filters( 'gatedmedia_coupon_discount' );

		parent::tear_down();
	}

	/** @testdox A priced product creates the pending row before Stripe is asked, and grants nothing yet. */
	public function test_paid_purchase_creates_row_then_session(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}", "file:{$this->file_item}" ) );
		$gateway = $this->fake_gateway();

		$outcome = $this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertSame( array( 'redirect' => 'https://stripe.example/session' ), $outcome );

		$payment = $this->store->paged( 1, 1 )[0];

		$this->assertSame( Payment::STATUS_PENDING, $payment->status );
		$this->assertSame( 1000, $payment->amount_total );
		$this->assertSame( array( "post:{$this->post_item}", "file:{$this->file_item}" ), $payment->contents_snapshot );
		$this->assertSame( 'cs_fake_1', $payment->stripe_session_id );
		$this->assertSame( $payment->uuid, $gateway->asked->uuid, 'the row must exist before the session is created' );
		$this->assertSame( array(), $this->access_ids(), 'access must not land before the confirmation' );
	}

	/** @testdox A free product grants every item directly, with no payment row and no Stripe. */
	public function test_free_purchase_grants_directly(): void {
		$product = $this->product( 0, array( "post:{$this->post_item}", "file:{$this->file_item}" ) );
		$gateway = $this->fake_gateway();

		$outcome = $this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertIsArray( $outcome );
		$this->assertSame( 0, $this->store->total(), 'free is not a zero-value order' );
		$this->assertNull( $gateway->asked, 'Stripe must not be involved at all' );
		$this->assertCount( 2, $this->access_ids() );
	}

	/** @testdox A coupon that takes the price to zero completes the payment on the spot and grants. */
	public function test_full_coupon_completes_immediately(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'freebie', 'percent', 100 );
		$gateway = $this->fake_gateway();

		$fired = array();
		add_action(
			'gatedmedia_payment_completed',
			static function ( int $payment_id ) use ( &$fired ): void {
				$fired[] = $payment_id;
			}
		);

		$outcome = $this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'freebie' );

		$this->assertIsArray( $outcome );
		$this->assertNull( $gateway->asked, 'a zero total never reaches Stripe' );

		$payment = $this->store->paged( 1, 1 )[0];

		$this->assertSame( Payment::STATUS_COMPLETE, $payment->status );
		$this->assertSame( 0, $payment->amount_total );
		$this->assertSame( 1000, $payment->discount_amount );
		$this->assertCount( 1, $this->access_ids() );
		$this->assertSame( array( $payment->payment_id ), $fired );
	}

	/**
	 * The decision that nearly shipped as nothing: §7.8 has no page of its own,
	 * so the return URL has to be the order. Nothing asserted it, and the
	 * whole round was built while Stripe still pointed at a query arg on the
	 * front page that renders nothing at all.
	 *
	 * @testdox Stripe sends the buyer back to their own order, flagged as just placed.
	 */
	public function test_return_url_is_the_order(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$payment = $this->store->paged( 1, 1 )[0];

		$this->assertIsString( $gateway->return_url );
		$this->assertStringContainsString( "/orders/{$payment->uuid}/", $gateway->return_url );
		$this->assertStringContainsString(
			Order_History::NEW_ORDER . '=' . $payment->uuid,
			$gateway->return_url
		);
	}

	/** @testdox A site can send the buyer somewhere of its own. */
	public function test_return_url_is_filterable(): void {
		add_filter( 'gatedmedia_checkout_return_url', static fn (): string => 'https://example.test/thanks' );

		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertSame( 'https://example.test/thanks', $gateway->return_url );
	}

	/** @testdox A percent coupon discounts the total that goes to Stripe; usage limits count completions. */
	public function test_coupon_discounts_and_limits(): void {
		$product   = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$coupon_id = $this->coupon( 'save20', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$gateway   = $this->fake_gateway();

		$outcome = $this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'save20' );

		$this->assertIsArray( $outcome );
		$this->assertSame( 800, $this->store->paged( 1, 1 )[0]->amount_total );

		// Spend it: completing the first payment uses the single allowed slot.
		$this->store->mark_complete( $this->store->paged( 1, 1 )[0]->uuid, 'pi_1' );

		$again = $this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'save20' );

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'gatedmedia_bad_coupon', $again->get_error_code() );
	}

	/** @testdox An abandoned checkout spends nothing — the pending row does not count against limits. */
	public function test_pending_payment_spends_no_coupon(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'once' );
		$second = $this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'once' );

		$this->assertIsArray( $second, 'a pending payment must not consume the coupon' );
	}

	/** @testdox The allow-list blocks buyers off it, and the eligibility filter has the last word. */
	public function test_eligibility(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ), array( Product_Meta::META_EMAILS => array( 'someone@else.com' ) ) );
		$gateway = $this->fake_gateway();

		$blocked = $this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'gatedmedia_not_eligible', $blocked->get_error_code() );

		add_filter( 'gatedmedia_product_eligibility', '__return_true' );

		$this->assertIsArray( $this->checkout( $gateway )->purchase( $product, $this->buyer_id ) );
	}

	/** @testdox A failed session creation marks the row failed and surfaces the error. */
	public function test_gateway_failure_fails_the_row(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$gateway = $this->failing_gateway();

		$outcome = $this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( Payment::STATUS_FAILED, $this->store->paged( 1, 1 )[0]->status );
	}

	/** @testdox Granting a product's items twice with one reference writes each item exactly once. */
	public function test_grant_items_idempotent_per_item(): void {
		$product = get_post( $this->product( 1000, array( "post:{$this->post_item}", "file:{$this->file_item}" ) ) );
		$flow    = $this->checkout( $this->fake_gateway() );

		$flow->grant_items( $product, $this->buyer_id, 'stripe', 'uuid-1' );
		$flow->grant_items( $product, $this->buyer_id, 'stripe', 'uuid-1' );

		$this->assertCount( 2, $this->access_ids(), 'each item once, not once per delivery and not one item swallowing the other' );
	}

	/**
	 * A product with a price, items and any extra meta.
	 *
	 * @param int                                $price Minor units.
	 * @param array<int, string>                 $items type:id rows.
	 * @param array<string, array<int, string>> $extra Repeated meta to add.
	 */
	private function product( int $price, array $items, array $extra = array() ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_title'  => 'The Bundle',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		update_post_meta( $product_id, Product_Meta::META_CURRENCY, 'GBP' );

		foreach ( $items as $item ) {
			add_post_meta( $product_id, Product_Meta::META_ITEMS, $item );
		}

		foreach ( $extra as $key => $rows ) {
			foreach ( $rows as $row ) {
				add_post_meta( $product_id, $key, $row );
			}
		}

		return $product_id;
	}

	/**
	 * A published coupon with a code, a type and a value.
	 *
	 * @param string                $code  The code (becomes post_name).
	 * @param string                $type  percent or fixed.
	 * @param int                   $value Whole percent, or minor units.
	 * @param array<string, string> $extra Single meta to add.
	 */
	private function coupon( string $code, string $type, int $value, array $extra = array() ): int {
		$coupon_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::COUPON,
				'post_title'  => $code,
				'post_name'   => $code,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $coupon_id, Coupon_Metabox::META_TYPE, $type );
		update_post_meta( $coupon_id, Coupon_Metabox::META_VALUE, $value );

		foreach ( $extra as $key => $meta_value ) {
			update_post_meta( $coupon_id, $key, $meta_value );
		}

		return $coupon_id;
	}

	/**
	 * The flow under test, over the given gateway fake.
	 *
	 * @param Stripe_Gateway $gateway The fake.
	 */
	private function checkout( Stripe_Gateway $gateway ): Checkout {
		return new Checkout( $this->store, $this->writer, $gateway );
	}

	/**
	 * A gateway answering a canned session and recording the payment it
	 * was asked about.
	 */
	private function fake_gateway(): Stripe_Gateway {
		return new class( new Settings() ) extends Stripe_Gateway {
			/**
			 * The payment the session was created for, null until asked.
			 *
			 * @var \PinkCrab\Gated_Access\Payments\Payment|null
			 */
			public ?Payment $asked = null;

			/**
			 * Where Stripe was told to send the buyer back to.
			 *
			 * @var string|null
			 */
			public ?string $return_url = null;

			/**
			 * Answers a canned session.
			 *
			 * @param Payment $payment      The pending row.
			 * @param string  $product_name Ignored.
			 * @param string  $success_url  Where the buyer returns on success.
			 * @param string  $cancel_url   Ignored.
			 * @param string  $email        Ignored.
			 * @return array{id: string, url: string}
			 */
			public function create_checkout_session( Payment $payment, string $product_name, string $success_url, string $cancel_url, string $email ): array|WP_Error {
				$this->asked      = $payment;
				$this->return_url = $success_url;

				return array(
					'id'  => 'cs_fake_1',
					'url' => 'https://stripe.example/session',
				);
			}
		};
	}

	/**
	 * A gateway that always refuses.
	 */
	private function failing_gateway(): Stripe_Gateway {
		return new class( new Settings() ) extends Stripe_Gateway {
			/**
			 * Refuses.
			 *
			 * @param Payment $payment      Ignored.
			 * @param string  $product_name Ignored.
			 * @param string  $success_url  Ignored.
			 * @param string  $cancel_url   Ignored.
			 * @param string  $email        Ignored.
			 * @return WP_Error
			 */
			public function create_checkout_session( Payment $payment, string $product_name, string $success_url, string $cancel_url, string $email ): array|WP_Error {
				return new WP_Error( 'gatedmedia_stripe_error', 'refused' );
			}
		};
	}

	/**
	 * Every access record's id.
	 *
	 * @return array<int, int>
	 */
	private function access_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::ACCESS,
					'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
	}
}
