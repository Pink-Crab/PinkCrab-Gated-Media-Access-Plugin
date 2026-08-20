<?php
/**
 * The payments table, its migration and its store.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;

/**
 * The row is the replay guard: each status mover moves from exactly one
 * state, and affected-rows answers who was first. These tests prove the
 * guard, the reads, and that the migration runs from load, never activation.
 *
 * @group integration
 */
class Test_Payment_Store extends WP_UnitTestCase {

	private Payment_Store $store;

	public function set_up(): void {
		parent::set_up();

		// Deterministic whatever ran before: force the version check to look.
		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store = new Payment_Store();
	}

	/** @testdox The load-time migration creates the table and records its version. */
	public function test_migration_creates_the_table(): void {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Asking MySQL about our own table.
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertSame( Payments_Schema::DB_VERSION, get_option( Payments_Schema::OPTION_DB_VERSION ) );
	}

	/** @testdox A created payment is pending, carries a uuid, and freezes the snapshot. */
	public function test_create_pending(): void {
		$payment = $this->store->create_pending( 5, 9, 1250, 'gbp', array( 'file:42', 'group:abc' ), 3, 250 );

		$this->assertInstanceOf( Payment::class, $payment );
		$this->assertSame( Payment::STATUS_PENDING, $payment->status );
		$this->assertSame( 36, strlen( $payment->uuid ) );
		$this->assertSame( 5, $payment->user_id );
		$this->assertSame( 9, $payment->product_id );
		$this->assertSame( 1250, $payment->amount_total );
		$this->assertSame( 'GBP', $payment->currency );
		$this->assertSame( 3, $payment->coupon_id );
		$this->assertSame( 250, $payment->discount_amount );
		$this->assertSame( array( 'file:42', 'group:abc' ), $payment->contents_snapshot );
		$this->assertNull( $payment->completed_at );
		$this->assertNull( $payment->refunded_at );
	}

	/** @testdox The Stripe session lands on the row once the session exists. */
	public function test_attach_session(): void {
		$payment = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );

		$this->assertTrue( $this->store->attach_session( $payment->uuid, 'cs_test_123' ) );
		$this->assertSame( 'cs_test_123', $this->store->find_by_uuid( $payment->uuid )->stripe_session_id );
	}

	/** @testdox Completing a pending payment succeeds exactly once — the replay guard. */
	public function test_mark_complete_is_first_delivery_only(): void {
		$payment = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );

		$this->assertTrue( $this->store->mark_complete( $payment->uuid, 'pi_123' ) );
		$this->assertFalse( $this->store->mark_complete( $payment->uuid, 'pi_123' ), 'a retried delivery must change nothing' );

		$read = $this->store->find_by_uuid( $payment->uuid );

		$this->assertSame( Payment::STATUS_COMPLETE, $read->status );
		$this->assertSame( 'pi_123', $read->stripe_payment_intent_id );
		$this->assertNotNull( $read->completed_at );
	}

	/** @testdox A refund moves complete to refunded exactly once, and never touches pending. */
	public function test_mark_refunded_guards_the_same_way(): void {
		$payment = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );

		$this->assertFalse( $this->store->mark_refunded( $payment->uuid ), 'a pending payment has nothing to refund' );

		$this->store->mark_complete( $payment->uuid, 'pi_123' );

		$this->assertTrue( $this->store->mark_refunded( $payment->uuid ) );
		$this->assertFalse( $this->store->mark_refunded( $payment->uuid ), 'a retried refund must change nothing' );
		$this->assertSame( Payment::STATUS_REFUNDED, $this->store->find_by_uuid( $payment->uuid )->status );
	}

	/** @testdox A failed checkout cannot complete late, and a completion cannot fail late. */
	public function test_failed_and_complete_exclude_each_other(): void {
		$failed = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );
		$this->store->mark_failed( $failed->uuid );
		$this->assertFalse( $this->store->mark_complete( $failed->uuid, 'pi_late' ) );

		$completed = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );
		$this->store->mark_complete( $completed->uuid, 'pi_ok' );
		$this->assertFalse( $this->store->mark_failed( $completed->uuid ) );
	}

	/** @testdox A refund event finds its payment by Stripe's intent id. */
	public function test_find_by_intent(): void {
		$payment = $this->store->create_pending( 1, 2, 500, 'GBP', array( 'post:7' ) );
		$this->store->mark_complete( $payment->uuid, 'pi_findme' );

		$this->assertSame( $payment->uuid, $this->store->find_by_intent( 'pi_findme' )->uuid );
		$this->assertNull( $this->store->find_by_intent( 'pi_absent' ) );
		$this->assertNull( $this->store->find_by_intent( '' ), 'an empty intent matches nothing, not the rows whose intent is unset' );
	}

	/** @testdox Paging returns newest first and total counts every row. */
	public function test_paged_and_total(): void {
		foreach ( range( 1, 3 ) as $product_id ) {
			$this->store->create_pending( 1, $product_id, 100, 'GBP', array() );
		}

		$page = $this->store->paged( 1, 2 );

		$this->assertCount( 2, $page );
		$this->assertSame( 3, $page[0]->product_id );
		$this->assertSame( 2, $page[1]->product_id );
		$this->assertCount( 1, $this->store->paged( 2, 2 ) );
		$this->assertSame( 3, $this->store->total() );
	}

	/** @testdox Coupon usage counts completed payments only — abandoning a checkout spends nothing. */
	public function test_coupon_completions(): void {
		$spent = $this->store->create_pending( 1, 2, 500, 'GBP', array(), 7, 100 );
		$this->store->mark_complete( $spent->uuid, 'pi_1' );

		$other_user = $this->store->create_pending( 2, 2, 500, 'GBP', array(), 7, 100 );
		$this->store->mark_complete( $other_user->uuid, 'pi_2' );

		// Pending — never counted.
		$this->store->create_pending( 1, 2, 500, 'GBP', array(), 7, 100 );

		$this->assertSame( 2, $this->store->coupon_completions( 7 ) );
		$this->assertSame( 1, $this->store->coupon_completions( 7, 1 ) );
		$this->assertSame( 0, $this->store->coupon_completions( 99 ) );
	}
}
