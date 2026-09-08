<?php
/**
 * The single-payment detail page.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Admin\Payment_Detail_Page;
use PinkCrab\Gated_Access\Admin\Payments_List_Table;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * One payment whole: the row's facts, the access its reference wrote, and the Reference column pointing here, registered hidden behind the view capability.
 *
 * @group integration
 */
class Test_Payment_Detail_Page extends WP_UnitTestCase {

	private Payment_Store $store;

	private Access_Writer $writer;

	private Payment_Detail_Page $page;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store  = new Payment_Store();
		$this->writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->writer->register_meta();

		$this->page = new Payment_Detail_Page( $this->store, new Access_Lookup(), new Access_Taxonomy() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** @testdox The page registers hidden, behind gatedmedia_view_payments. */
	public function test_registered_hidden_behind_the_view_capability(): void {
		$GLOBALS['submenu'] = array();

		$this->page->register_page();

		$entries = $GLOBALS['submenu'][''] ?? array();
		$ours    = array_values( array_filter( $entries, static fn ( array $entry ): bool => Payment_Detail_Page::PAGE_SLUG === $entry[2] ) );

		$this->assertCount( 1, $ours, 'the detail page was not registered' );
		$this->assertSame( Capabilities::VIEW_PAYMENTS, $ours[0][1] );
	}

	/** @testdox The page shows the row's facts and every record the payment's reference wrote. */
	public function test_renders_the_payment_and_its_grants(): void {
		$user_id = self::factory()->user->create(
			array(
				'display_name' => 'Terry Buyer',
				'user_email'   => 'terry@example.test',
			)
		);
		$post_id = self::factory()->post->create( array( 'post_title' => 'The Granted Post' ) );
		$product = self::factory()->post->create(
			array(
				'post_type'  => 'gatedmedia_product',
				'post_title' => 'The Bundle',
			)
		);

		$payment = $this->store->create_pending( $user_id, $product, 1250, 'GBP', array( "post:{$post_id}" ) );
		$this->store->mark_complete( $payment->uuid );
		$this->writer->grant( $user_id, 'post', (string) $post_id, null, Checkout::SOURCE_STRIPE, $payment->uuid );

		$_GET['payment'] = $payment->uuid;
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['payment'] );

		$this->assertStringContainsString( '£12.50', $html );
		$this->assertStringContainsString( $payment->uuid, $html );
		$this->assertStringContainsString( 'Terry Buyer', $html );
		$this->assertStringContainsString( 'The Bundle', $html );
		$this->assertStringContainsString( 'The Granted Post', $html );
		$this->assertStringContainsString( 'Active', $html );
	}

	/** @testdox An unknown reference gets the way back, not a fatal. */
	public function test_unknown_reference_renders_the_way_back(): void {
		$_GET['payment'] = 'no-such-uuid';
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['payment'] );

		$this->assertStringContainsString( 'No payment found', $html );
		$this->assertStringContainsString( 'gatedmedia-payments', $html );
	}

	/**
	 * `Coupon_Hold` reserves a limited coupon only briefly, so two checkouts overlapping by longer can both complete, and nothing can be refused once Stripe has the money.
	 *
	 * So the payment that went past the limit says so here, and the ones within it stay quiet.
	 *
	 * @testdox A payment that took a coupon past its limit says so; the one within it does not.
	 */
	public function test_a_payment_past_the_coupon_limit_is_flagged(): void {
		$coupon = self::factory()->post->create(
			array(
				'post_type'  => Post_Types::COUPON,
				'post_title' => 'Once Only',
			)
		);

		update_post_meta( $coupon, Coupon_Metabox::META_USAGE_LIMIT, '1' );

		$first  = $this->store->create_pending( 1, 1, 100, 'GBP', array(), $coupon, 20 );
		$second = $this->store->create_pending( 2, 1, 100, 'GBP', array(), $coupon, 20 );

		$this->store->mark_complete( $first->uuid );
		$this->store->mark_complete( $second->uuid );

		$this->assertStringNotContainsString( 'use 1 of a coupon', $this->render_detail( $first->uuid ) );
		$this->assertStringContainsString( 'use 2 of a coupon limited to 1', $this->render_detail( $second->uuid ) );
	}

	/** @testdox A coupon with no limit is never reported as over one. */
	public function test_an_unlimited_coupon_is_never_flagged(): void {
		$coupon = self::factory()->post->create(
			array(
				'post_type'  => Post_Types::COUPON,
				'post_title' => 'As Often As You Like',
			)
		);

		$first  = $this->store->create_pending( 1, 1, 100, 'GBP', array(), $coupon, 20 );
		$second = $this->store->create_pending( 2, 1, 100, 'GBP', array(), $coupon, 20 );

		$this->store->mark_complete( $first->uuid );
		$this->store->mark_complete( $second->uuid );

		$this->assertStringNotContainsString( 'limited to', $this->render_detail( $second->uuid ) );
	}

	/**
	 * The detail page's markup for one payment.
	 *
	 * @param string $uuid The payment to render.
	 */
	private function render_detail( string $uuid ): string {
		$_GET['payment'] = $uuid;
		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();
		unset( $_GET['payment'] );

		return $html;
	}

	/** @testdox The list's Reference column links to the detail page. */
	public function test_reference_column_links_here(): void {
		$payment = $this->store->create_pending( 1, 1, 100, 'GBP', array() );

		if ( ! class_exists( \WP_List_Table::class ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		$cell = ( new Payments_List_Table( $this->store ) )->column_reference( $payment );

		$this->assertStringContainsString( Payment_Detail_Page::PAGE_SLUG, $cell );
		$this->assertStringContainsString( $payment->uuid, $cell );
	}
}
