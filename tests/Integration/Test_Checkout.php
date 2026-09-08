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
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Sweep;
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
 * The pending row exists before Stripe is asked anything, a priced product grants nothing on the way out, a free product grants directly with no row, a coupon spends at completion and can take a payment to zero, and the allow-list gates who may buy.
 *
 * Stripe is faked at the boundary: the gateway subclass below answers a canned session and records what it was asked.
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
		( new Coupon_Metabox( new Settings() ) )->register_meta();

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
		remove_all_filters( 'gatedmedia_coupon_hold_seconds' );
		remove_all_actions( 'gatedmedia_checkout_failed' );
		remove_all_filters( 'gatedmedia_account_route' );

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

	/**
	 * The claim's fixed reference matched the expired record, so the retry guard answered with it and nothing new was written, leaving a Join button that did nothing.
	 *
	 * @testdox A free timed product can be claimed again once the first claim has run out.
	 */
	public function test_a_lapsed_free_claim_can_be_made_again(): void {
		$product = $this->product( 0, array( "post:{$this->post_item}" ), array( Product_Meta::META_DURATION => array( '30' ) ) );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$first = $this->access_ids();

		$this->assertCount( 1, $first );

		update_post_meta( $first[0], Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		( new Sweep( $this->writer ) )->run();

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$records = $this->access_ids();

		$this->assertCount( 2, $records, 'the lapsed record is not the answer to a fresh claim' );

		$statuses = array_map( fn( $id ) => get_post_status( $id ), $records );

		$this->assertContains( Post_Types::STATUS_ACTIVE, $statuses, 'the new claim is live' );
	}

	/** @testdox Claiming a free product again while it is still held writes nothing and extends nothing. */
	public function test_a_live_free_claim_is_not_claimed_twice(): void {
		$product = $this->product( 0, array( "post:{$this->post_item}" ), array( Product_Meta::META_DURATION => array( '30' ) ) );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$records = $this->access_ids();
		$expiry  = (string) get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$this->assertSame( $records, $this->access_ids(), 'a second press writes no second record' );
		$this->assertSame( $expiry, (string) get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true ), 'and does not stack days on' );
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
	 * The payment status panel has no page of its own, so the return URL is the order itself.
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

	/**
	 * The worst dead link: the buyer has paid and Stripe sends them to a page the setting has stopped answering. Their access lands either way, with nothing to tell them so.
	 *
	 * @testdox With the account route off, Stripe is not told to return the buyer to a page that no longer answers.
	 */
	public function test_return_url_follows_the_account_route_setting(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );

		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertIsString( $gateway->return_url );
		$this->assertStringNotContainsString( '/account/', $gateway->return_url );
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

	/**
	 * The coupon shows a price before the buyer commits and `Checkout` then charges them. If the two disagree the product page is lying.
	 *
	 * @testdox The price previewed is the price charged.
	 */
	public function test_preview_agrees_with_what_is_charged(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'save20', 'percent', 20 );

		$checkout = $this->checkout( $this->fake_gateway() );
		$preview  = $checkout->preview( $product, $this->buyer_id, 'save20' );

		$this->assertTrue( $preview['applied'] );
		$this->assertSame( 200, $preview['discount'] );
		$this->assertSame( 800, $preview['total'] );

		$checkout->purchase( $product, $this->buyer_id, 'save20' );

		$this->assertSame( $preview['total'], $this->store->paged( 1, 1 )[0]->amount_total );
	}

	/** @testdox Previewing a coupon spends nothing, so looking at a price twice cannot use it up. */
	public function test_preview_spends_nothing(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );

		$checkout = $this->checkout( $this->fake_gateway() );

		$checkout->preview( $product, $this->buyer_id, 'once' );
		$checkout->preview( $product, $this->buyer_id, 'once' );

		$this->assertCount( 0, $this->store->paged( 1, 10 ), 'preview must create no payment row' );
		$this->assertTrue( $checkout->preview( $product, $this->buyer_id, 'once' )['applied'] );
	}

	/**
	 * The apply step is not trusted: a coupon that runs out between pricing and pressing is refused at purchase, whatever the page said.
	 *
	 * @testdox A coupon spent after the page was priced is still refused at purchase.
	 */
	public function test_a_coupon_spent_after_preview_is_refused(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'last', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );

		$checkout = $this->checkout( $this->fake_gateway() );

		$this->assertTrue( $checkout->preview( $product, $this->buyer_id, 'last' )['applied'] );

		// Somebody else takes the last one between the two.
		$checkout->purchase( $product, $this->buyer_id, 'last' );
		$this->store->mark_complete( $this->store->paged( 1, 1 )[0]->uuid, 'pi_1' );

		$late = $checkout->purchase( $product, $this->buyer_id, 'last' );

		$this->assertInstanceOf( WP_Error::class, $late );
		$this->assertSame( 'gatedmedia_bad_coupon', $late->get_error_code() );
	}

	/**
	 * Usage is counted from completions and a pending row is not one. The reservation a checkout in flight holds expires on its own and never becomes a use.
	 *
	 * @testdox An abandoned checkout completes nothing, so it spends nothing.
	 */
	public function test_pending_payment_spends_no_coupon(): void {
		$product   = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$coupon_id = $this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id, 'once' );

		$this->assertSame( 0, $this->store->coupon_completions( $coupon_id ), 'a pending payment is not a completion' );
	}

	/**
	 * Two buyers part-way through checkout when the other started, so neither counted. Both then paid, and a coupon limited to one use was spent twice.
	 *
	 * @testdox A coupon limited to one use cannot be completed twice.
	 */
	public function test_a_limit_one_coupon_cannot_be_completed_twice(): void {
		$product   = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$coupon_id = $this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$gateway   = $this->fake_gateway();
		$other     = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'once' );
		$this->checkout( $gateway )->purchase( $product, $other, 'once' );

		foreach ( $this->store->paged( 1, 10 ) as $payment ) {
			$this->store->mark_complete( $payment->uuid, 'pi_' . $payment->payment_id );
		}

		$this->assertSame( 1, $this->store->coupon_completions( $coupon_id ), 'a usage limit of one must not be spent twice' );
	}

	/**
	 * The hold closes the gap: a checkout in flight reserves the coupon briefly, so a second buyer arriving at the same moment is refused rather than sent to Stripe.
	 *
	 * @testdox A checkout in flight holds a limited coupon against everyone else.
	 */
	public function test_a_pending_checkout_holds_a_limited_coupon(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$gateway = $this->fake_gateway();
		$other   = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'once' );
		$second = $this->checkout( $gateway )->purchase( $product, $other, 'once' );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'gatedmedia_bad_coupon', $second->get_error_code() );
	}

	/** @testdox A per-user limit is held by that buyer's own checkout in flight. */
	public function test_a_pending_checkout_holds_a_per_user_limit(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'mine', 'percent', 20, array( Coupon_Metabox::META_PER_USER_LIMIT => '1' ) );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'mine' );
		$second = $this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'mine' );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'gatedmedia_bad_coupon', $second->get_error_code() );
	}

	/** @testdox A hold of zero seconds turns the reservation off entirely. */
	public function test_the_hold_can_be_switched_off(): void {
		add_filter( 'gatedmedia_coupon_hold_seconds', '__return_zero' );

		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$gateway = $this->fake_gateway();
		$other   = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id, 'once' );
		$second = $this->checkout( $gateway )->purchase( $product, $other, 'once' );

		$this->assertIsArray( $second, 'with the hold off, an in-flight checkout reserves nothing' );
	}

	/** @testdox A checkout that fails gives its hold back straight away. */
	public function test_a_failed_payment_releases_the_hold(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'once', 'percent', 20, array( Coupon_Metabox::META_USAGE_LIMIT => '1' ) );
		$other   = self::factory()->user->create( array( 'user_email' => 'other@example.com' ) );

		$refused = $this->checkout( $this->failing_gateway() )->purchase( $product, $this->buyer_id, 'once' );

		$this->assertInstanceOf( WP_Error::class, $refused );

		$second = $this->checkout( $this->fake_gateway() )->purchase( $product, $other, 'once' );

		$this->assertIsArray( $second, 'a checkout that never reached Stripe must not keep the coupon' );
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

	/** @testdox A checkout that could not start announces itself, carrying the gateway's reason. */
	public function test_gateway_failure_announces(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$heard   = array();

		add_action(
			'gatedmedia_checkout_failed',
			static function ( $payment_id, $error ) use ( &$heard ): void {
				$heard[] = array( $payment_id, $error );
			},
			10,
			2
		);

		$this->checkout( $this->failing_gateway() )->purchase( $product, $this->buyer_id );

		$this->assertCount( 1, $heard, 'the failure must announce exactly once' );
		$this->assertSame( $this->store->paged( 1, 1 )[0]->payment_id, $heard[0][0] );
		$this->assertSame( 'refused', $heard[0][1]->get_error_message() );
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
	 * @testdox A free product marked lifetime grants every item with no expiry.
	 *
	 * Lifetime is `-1` and nothing else. Everything falsey was once indistinguishable from an unset key, and the offer read it as lifetime while `Checkout` handed 0 days to a writer that refuses anything below 1.
	 */
	public function test_a_lifetime_free_product_grants_with_no_expiry(): void {
		$product = $this->product(
			0,
			array( "post:{$this->post_item}", "file:{$this->file_item}" ),
			array( Product_Meta::META_DURATION => array( '-1' ) )
		);

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$records = $this->access_ids();

		$this->assertCount( 2, $records, 'lifetime grants, it does not refuse' );

		foreach ( $records as $record ) {
			$this->assertSame(
				'',
				(string) get_post_meta( $record, Access_Writer::META_EXPIRES_AT, true ),
				'lifetime access carries no expiry'
			);
		}
	}

	/**
	 * @testdox A paid product marked lifetime grants from its snapshot with no expiry.
	 *
	 * The same on the payment path: the coupon takes the total to zero, the row completes, and `grant_snapshot()` lands the items.
	 */
	public function test_a_lifetime_paid_product_grants_with_no_expiry(): void {
		$product = $this->product(
			1000,
			array( "post:{$this->post_item}" ),
			array( Product_Meta::META_DURATION => array( '-1' ) )
		);
		$this->coupon( 'freebie', 'percent', 100 );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id, 'freebie' );

		$records = $this->access_ids();

		$this->assertCount( 1, $records, 'the payment completed, so its snapshot must have granted' );
		$this->assertSame(
			'',
			(string) get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true ),
			'lifetime access carries no expiry'
		);
	}

	/**
	 * @testdox A product that never stored a duration reads the registered default, and grants lifetime.
	 *
	 * The unset key is the case `-1` exists to kill. `Product_Meta` registers `-1` as the default, so "nothing stored" arrives as lifetime rather than an empty string.
	 */
	public function test_an_unset_duration_reads_the_lifetime_default(): void {
		$product = $this->product( 0, array( "post:{$this->post_item}" ) );

		$this->assertSame( '-1', (string) get_post_meta( $product, Product_Meta::META_DURATION, true ) );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$records = $this->access_ids();

		$this->assertCount( 1, $records );
		$this->assertSame( '', (string) get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true ) );
	}

	/**
	 * @testdox A zero-total checkout that fails to complete its row grants nothing and announces nothing.
	 *
	 * `mark_complete()` answering true is what licenses a grant. The zero-total path threw that answer away, so a failed update left a pending row with access against it.
	 */
	public function test_a_zero_total_that_cannot_complete_grants_nothing(): void {
		$product = $this->product( 1000, array( "post:{$this->post_item}" ) );
		$this->coupon( 'freebie', 'percent', 100 );

		$fired = array();
		add_action(
			'gatedmedia_payment_completed',
			static function ( int $payment_id ) use ( &$fired ): void {
				$fired[] = $payment_id;
			}
		);

		$flow = new Checkout( $this->refusing_store(), $this->writer, $this->fake_gateway(), new Resolver( new Access_Taxonomy() ) );

		$flow->purchase( $product, $this->buyer_id, 'freebie' );

		$this->assertSame( array(), $this->access_ids(), 'the row never completed, so nothing may be granted' );
		$this->assertSame( array(), $fired, 'nothing completed, so nothing may be announced' );
	}

	/**
	 * A store whose completion never takes, standing in for a failed UPDATE.
	 */
	private function refusing_store(): Payment_Store {
		return new class() extends Payment_Store {
			/**
			 * Refuses to move the row.
			 *
			 * @param string $uuid      Ignored.
			 * @param string $intent_id Ignored.
			 */
			public function mark_complete( string $uuid, string $intent_id = '' ): bool {
				return false;
			}
		};
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
		return new Checkout( $this->store, $this->writer, $gateway, new Resolver( new Access_Taxonomy() ) );
	}

	/**
	 * A gateway answering a canned session and recording what it was asked about.
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
