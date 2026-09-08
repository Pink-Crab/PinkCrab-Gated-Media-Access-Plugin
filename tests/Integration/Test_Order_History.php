<?php
/**
 * The Orders view's data.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Order_History;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * The orders list is one person's own record and the detail is one row of it, so every test asks the same question: does this answer for the person asking, and only for them.
 *
 * The expiry cases are deliberate. `META_EXPIRES_AT` holds a UTC MySQL datetime, and casting that string to an integer yields the year, dating every row "expires in 1 day". It looks plausible on screen, so it is asserted rather than eyed.
 *
 * @group integration
 */
class Test_Order_History extends WP_UnitTestCase {

	private const DEFAULTS = array(
		'orders' => array(),
		'detail' => null,
	);

	private Payment_Store $store;

	private Access_Writer $writer;

	private Order_History $data;

	private int $user_id;

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store  = new Payment_Store();
		$this->writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->data   = new Order_History( $this->store, new Access_Lookup(), new Item_Label( new Access_Taxonomy() ) );

		$this->user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->product_id = self::factory()->post->create(
			array(
				'post_type'  => Post_Types::PRODUCT,
				'post_title' => 'Q3 market report bundle',
			)
		);

		wp_set_current_user( $this->user_id );
	}

	/**
	 * A completed payment for the current user.
	 *
	 * @param array<int, string> $snapshot What it contained, as `type:id` strings.
	 * @param int                $user_id  Whose payment, defaulting to the current user.
	 */
	private function completed_payment( array $snapshot = array(), int $user_id = 0 ): Payment {
		$payment = $this->store->create_pending(
			0 === $user_id ? $this->user_id : $user_id,
			$this->product_id,
			2500,
			'GBP',
			$snapshot
		);

		$this->assertInstanceOf( Payment::class, $payment );
		$this->store->mark_complete( $payment->uuid );

		$found = $this->store->find_by_uuid( $payment->uuid );
		$this->assertInstanceOf( Payment::class, $found );

		return $found;
	}

	/**
	 * A completed payment carrying a discount, for the struck-through price.
	 *
	 * @param int $total    What was actually charged, minor units.
	 * @param int $discount What the coupon took off, minor units.
	 */
	private function discounted_payment( int $total, int $discount ): Payment {
		$payment = $this->store->create_pending(
			$this->user_id,
			$this->product_id,
			$total,
			'GBP',
			array(),
			0,
			$discount
		);

		$this->assertInstanceOf( Payment::class, $payment );
		$this->store->mark_complete( $payment->uuid );

		$found = $this->store->find_by_uuid( $payment->uuid );
		$this->assertInstanceOf( Payment::class, $found );

		return $found;
	}

	/** @testdox Signed out, the filter returns its defaults untouched. */
	public function test_signed_out_gets_the_defaults(): void {
		wp_set_current_user( 0 );
		$this->completed_payment();

		$this->assertSame( self::DEFAULTS, $this->data->orders( self::DEFAULTS ) );
	}

	/** @testdox The list carries this person's own orders, newest first, and nobody else's. */
	public function test_list_is_the_current_users_newest_first(): void {
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$first  = $this->completed_payment();
		$second = $this->completed_payment();
		$theirs = $this->completed_payment( array(), $other );

		$orders = $this->data->orders( self::DEFAULTS )['orders'];

		$this->assertCount( 2, $orders );
		$this->assertSame( $second->uuid, $orders[0]['uuid'] );
		$this->assertSame( $first->uuid, $orders[1]['uuid'] );
		$this->assertNotContains( $theirs->uuid, wp_list_pluck( $orders, 'uuid' ) );
	}

	/** @testdox A row names the product and links to the order. */
	public function test_row_shape(): void {
		$payment = $this->completed_payment();

		$row = $this->data->orders( self::DEFAULTS )['orders'][0];

		$this->assertSame( 'Q3 market report bundle', $row['title'] );
		$this->assertSame( 2500, $row['amount'] );
		$this->assertSame( 'GBP', $row['currency'] );
		$this->assertSame( Payment::STATUS_COMPLETE, $row['status'] );
		$this->assertStringContainsString( '/orders/' . $payment->uuid . '/', $row['href'] );
	}

	/** @testdox Someone else's order reads exactly like one that never existed. */
	public function test_another_users_order_is_not_readable(): void {
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$theirs = $this->completed_payment( array(), $other );

		$this->assertNull( $this->data->orders( self::DEFAULTS, $theirs->uuid )['detail'] );
		$this->assertNull( $this->data->orders( self::DEFAULTS, 'not-a-real-uuid' )['detail'] );
	}

	/** @testdox An order's contents are named from the snapshot's type:id entries. */
	public function test_detail_names_its_snapshot(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Deep dive on gilts' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$detail = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail'];

		$this->assertSame( 'Deep dive on gilts', $detail['contents'][0]['text'] );
		$this->assertSame( 'i-article', $detail['contents'][0]['icon'] );
	}

	/**
	 * The regression this class was written wrong for once.
	 *
	 * @testdox A dated grant reports the date it actually expires, not one measured from 1970.
	 */
	public function test_dated_access_reports_its_real_expiry(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Analyst briefings' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, Checkout::SOURCE_STRIPE, $payment->uuid );

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertCount( 1, $access );
		$this->assertSame( 'Analyst briefings', $access[0]['title'] );

		// Thirty days out is neither "soon" (7) nor a date in the past.
		$this->assertSame( 'dated', $access[0]['expiry_state'] );
		$this->assertStringContainsString(
			wp_date( (string) get_option( 'date_format' ), time() + ( 30 * DAY_IN_SECONDS ) ),
			$access[0]['expiry_label']
		);
	}

	/** @testdox A lifetime grant says so rather than inventing a date. */
	public function test_lifetime_access_says_lifetime(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The whole archive' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, Checkout::SOURCE_STRIPE, $payment->uuid );

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertSame( 'lifetime', $access[0]['expiry_state'] );
	}

	/**
	 * @testdox A record the sweep expired reads as expired, not as one day left.
	 *
	 * `Expiry::describe()` floors a past date at one day, because every other view asks only about live access. Order detail lists records whatever their status, so a lapsed one came back as "Expires in 1 day".
	 */
	public function test_an_expired_record_reads_as_expired(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Last quarter' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, Checkout::SOURCE_STRIPE, $payment->uuid );

		$this->assertIsInt( $access_id );

		// Backdate it and move it the way the sweep does.
		update_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		wp_update_post(
			array(
				'ID'          => $access_id,
				'post_status' => Post_Types::STATUS_EXPIRED,
			)
		);

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertCount( 1, $access );
		$this->assertSame( 'expired', $access[0]['expiry_state'] );
		$this->assertStringNotContainsString( 'Expires in', $access[0]['expiry_label'] );
	}

	/**
	 * @testdox A revoked lifetime record says it was withdrawn rather than Lifetime.
	 *
	 * A refund revokes without touching the date, so a lifetime record kept reading "Lifetime" and a dated one kept its future date, both on access the customer no longer has.
	 */
	public function test_a_revoked_record_reads_as_withdrawn(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The whole archive' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, Checkout::SOURCE_STRIPE, $payment->uuid );

		$this->assertIsInt( $access_id );
		$this->assertTrue( $this->writer->revoke( $access_id ) );

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertCount( 1, $access );
		$this->assertSame( 'expired', $access[0]['expiry_state'] );
		$this->assertNotSame( 'Lifetime', $access[0]['expiry_label'] );
	}

	/** @testdox A revoked dated record does not keep advertising its old date. */
	public function test_a_revoked_dated_record_drops_its_date(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Analyst briefings' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, Checkout::SOURCE_STRIPE, $payment->uuid );

		$this->assertIsInt( $access_id );
		$this->assertTrue( $this->writer->revoke( $access_id ) );

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertSame( 'expired', $access[0]['expiry_state'] );
		$this->assertStringNotContainsString(
			wp_date( (string) get_option( 'date_format' ), time() + ( 30 * DAY_IN_SECONDS ) ),
			$access[0]['expiry_label']
		);
	}

	/** @testdox Live access is unaffected: a dated record still reports its date. */
	public function test_a_live_record_still_reports_its_date(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Still running' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id ) );

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, Checkout::SOURCE_STRIPE, $payment->uuid );

		$access = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['access'];

		$this->assertSame( 'dated', $access[0]['expiry_state'] );
	}

	/** @testdox The thank-you shows only when the query arg names this very order. */
	public function test_is_new_only_for_the_named_order(): void {
		$payment = $this->completed_payment();
		$another = $this->completed_payment();

		$this->assertFalse( $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['is_new'] );

		$_GET[ Order_History::NEW_ORDER ] = $payment->uuid;
		$this->assertTrue( $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['is_new'] );

		// Naming a different order of your own does not congratulate you here.
		$this->assertFalse( $this->data->orders( self::DEFAULTS, $another->uuid )['detail']['is_new'] );

		unset( $_GET[ Order_History::NEW_ORDER ] );
	}

	/**
	 * An order outlives what it bought, and a nameless line in a purchase history reads as a fault rather than a removed item.
	 *
	 * @testdox An order for a deleted product still has a name.
	 */
	public function test_deleted_product_still_names_its_order(): void {
		$payment = $this->completed_payment();

		wp_delete_post( $this->product_id, true );

		$this->assertSame( 'Access', $this->data->orders( self::DEFAULTS )['orders'][0]['title'] );
		$this->assertSame( $payment->uuid, $this->data->orders( self::DEFAULTS )['orders'][0]['uuid'] );
	}

	/**
	 * The price block draws `original` struck through beside `amount`, and nothing had produced a row where the two differ, so the only visible evidence of a coupon was unasserted.
	 *
	 * @testdox A discounted order carries what was paid and what it was before.
	 */
	public function test_a_discounted_order_keeps_the_original_price(): void {
		$this->discounted_payment( 2000, 500 );

		$row = $this->data->orders( self::DEFAULTS )['orders'][0];

		$this->assertSame( 2000, $row['amount'], 'what they were charged' );
		$this->assertSame( 2500, $row['original'], 'amount plus the discount' );
	}

	/** @testdox An order with no discount shows the same figure twice, so nothing is struck through. */
	public function test_an_undiscounted_order_has_no_original(): void {
		$this->completed_payment();

		$row = $this->data->orders( self::DEFAULTS )['orders'][0];

		$this->assertSame( $row['amount'], $row['original'] );
	}

	/** @testdox A refunded order opens, and says it was refunded rather than reading as complete. */
	public function test_a_refunded_order_opens(): void {
		$payment = $this->completed_payment();
		$this->store->mark_refunded( $payment->uuid );

		$detail = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail'];

		$this->assertNotNull( $detail );
		$this->assertSame( Payment::STATUS_REFUNDED, $detail['status'] );
	}

	/** @testdox An order still waiting on Stripe opens and says pending. */
	public function test_a_pending_order_opens(): void {
		$payment = $this->store->create_pending( $this->user_id, $this->product_id, 2500, 'GBP', array() );
		$this->assertInstanceOf( Payment::class, $payment );

		$detail = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail'];

		$this->assertNotNull( $detail );
		$this->assertSame( Payment::STATUS_PENDING, $detail['status'] );
	}

	/** @testdox An order's amount is formatted in its own currency, not the shop's. */
	public function test_the_amount_label_follows_the_row(): void {
		$payment = $this->completed_payment();

		$detail = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail'];

		$this->assertStringContainsString( '25.00', $detail['amount_label'] );
	}

	/** @testdox A snapshot entry naming nothing is dropped rather than drawn as a blank line. */
	public function test_an_unreadable_snapshot_entry_is_dropped(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The real one' ) );
		$payment = $this->completed_payment( array( 'post:' . $post_id, 'nonsense', ':', '' ) );

		$contents = $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['contents'];

		$this->assertCount( 1, $contents );
		$this->assertSame( 'The real one', $contents[0]['text'] );
	}
}
