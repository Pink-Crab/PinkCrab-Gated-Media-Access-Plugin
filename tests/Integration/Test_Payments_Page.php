<?php
/**
 * The payments screen.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Payments_Page;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Support\Money;

/**
 * The one list written from nothing: registered behind gatedmedia_view_payments, read-only, its cells rendered from the typed row.
 *
 * register_page() is called directly because the admin_menu hook is attached through admin_action(), which never fires under PHPUnit where is_admin() is false.
 *
 * @group integration
 */
class Test_Payments_Page extends WP_UnitTestCase {

	private Payment_Store $store;

	private Payments_Page $page;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store = new Payment_Store();
		$this->page  = new Payments_Page( $this->store );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$GLOBALS['menu']             = array();
		$GLOBALS['submenu']          = array();
		$GLOBALS['admin_page_hooks'] = array();

		// add_submenu_page() registers nothing for a user without the capability, and administrators hold it from the init grant.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/** @testdox The page registers under the plugin menu behind gatedmedia_view_payments. */
	public function test_registered_behind_the_view_capability(): void {
		$this->assertTrue( current_user_can( Capabilities::VIEW_PAYMENTS ), 'the administrator should hold the view capability' );

		$this->page->register_page();

		$entries = $GLOBALS['submenu']['gated-media-access'] ?? array();
		$ours    = array_values( array_filter( $entries, static fn ( array $entry ): bool => Payments_Page::PAGE_SLUG === $entry[2] ) );

		$this->assertCount( 1, $ours, 'the payments page was not registered' );
		$this->assertSame( Capabilities::VIEW_PAYMENTS, $ours[0][1] );
	}

	/** @testdox The table pages newest first and reports the true total. */
	public function test_prepare_items_pages_the_table(): void {
		foreach ( range( 1, 25 ) as $product_id ) {
			$this->store->create_pending( 1, $product_id, 100, 'GBP', array() );
		}

		$table = $this->page->build_table();
		$table->prepare_items();

		$this->assertCount( 20, $table->items );
		$this->assertSame( 25, $table->items[0]->product_id );
		$this->assertSame( 25, $table->get_pagination_arg( 'total_items' ) );
		$this->assertSame( 2, $table->get_pagination_arg( 'total_pages' ) );
	}

	/** @testdox Cells render the person, the product, the money and the status from the row. */
	public function test_columns_render_from_the_row(): void {
		$user_id    = self::factory()->user->create( array( 'display_name' => 'Terry Buyer' ) );
		$product_id = self::factory()->post->create(
			array(
				'post_type'  => 'gatedmedia_product',
				'post_title' => 'The Bundle',
			)
		);
		$coupon_id  = self::factory()->post->create(
			array(
				'post_type'  => 'gatedmedia_coupon',
				'post_title' => 'SAVE20',
			)
		);

		$payment = $this->store->create_pending( $user_id, $product_id, 1000, 'GBP', array( 'post:1' ), $coupon_id, 250 );
		$this->store->mark_complete( $payment->uuid, 'pi_1' );
		$payment = $this->store->find_by_uuid( $payment->uuid );

		$table = $this->page->build_table();

		$this->assertStringContainsString( 'Terry Buyer', $table->column_holder( $payment ) );
		$this->assertStringContainsString( 'The Bundle', $table->column_product( $payment ) );
		$this->assertSame( esc_html( Money::format( 1000, 'GBP' ) ), $table->column_amount( $payment ) );
		$this->assertStringContainsString( 'SAVE20', $table->column_coupon( $payment ) );
		$this->assertSame( 'Complete', $table->column_status( $payment ) );
		$this->assertStringContainsString( $payment->uuid, $table->column_reference( $payment ) );
	}

	/** @testdox A payment without a coupon shows the em dash, and a deleted buyer stays identifiable. */
	public function test_columns_degrade_without_their_posts(): void {
		$payment = $this->store->create_pending( 987654, 987655, 500, 'GBP', array() );

		$table = $this->page->build_table();

		$this->assertSame( Money::not_applicable(), $table->column_coupon( $payment ) );
		$this->assertStringContainsString( '987654', $table->column_holder( $payment ) );
		$this->assertStringContainsString( '987655', $table->column_product( $payment ) );
	}

	/** @testdox The list is read-only: no bulk actions and no checkbox column. */
	public function test_read_only(): void {
		$table = $this->page->build_table();

		$this->assertSame( array(), $table->get_bulk_actions() );
		$this->assertArrayNotHasKey( 'cb', $table->get_columns() );
	}
}
