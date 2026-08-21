<?php
/**
 * The Orders view's data.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Orders_View_Data;
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
 * §7.3 is one person's own record and §7.4 is one row of it, so every test
 * here is really the same question asked twice: does this answer for the
 * person asking, and only for them.
 *
 * The expiry cases are deliberate. `META_EXPIRES_AT` holds a UTC MySQL
 * datetime, not a timestamp — casting that string to an integer yields the
 * year, which reads as a date in 1970 and dates every row "expires in 1 day".
 * It looks entirely plausible on screen, so it is asserted rather than eyed.
 *
 * @group integration
 */
class Test_Orders_View_Data extends WP_UnitTestCase {

	private const DEFAULTS = array(
		'orders' => array(),
		'detail' => null,
	);

	private Payment_Store $store;

	private Access_Writer $writer;

	private Orders_View_Data $data;

	private int $user_id;

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store  = new Payment_Store();
		$this->writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->data   = new Orders_View_Data( $this->store, new Access_Lookup(), new Item_Label( new Access_Taxonomy() ) );

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

	/** @testdox The thank-you shows only when the query arg names this very order. */
	public function test_is_new_only_for_the_named_order(): void {
		$payment = $this->completed_payment();
		$another = $this->completed_payment();

		$this->assertFalse( $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['is_new'] );

		$_GET[ Orders_View_Data::NEW_ORDER ] = $payment->uuid;
		$this->assertTrue( $this->data->orders( self::DEFAULTS, $payment->uuid )['detail']['is_new'] );

		// Naming a different order of your own does not congratulate you here.
		$this->assertFalse( $this->data->orders( self::DEFAULTS, $another->uuid )['detail']['is_new'] );

		unset( $_GET[ Orders_View_Data::NEW_ORDER ] );
	}
}
