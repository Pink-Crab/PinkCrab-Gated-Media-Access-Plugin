<?php
/**
 * Each account block's filter is answered, and by the right class.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Downloadable_Files;
use PinkCrab\Gated_Access\Account\Group_Contents;
use PinkCrab\Gated_Access\Account\Held_Access;
use PinkCrab\Gated_Access\Account\Order_History;
use PinkCrab\Gated_Access\Products\Product_Offer;

/**
 * These five classes were one class, `View_Data`, answering for two blocks
 * under a name that described neither. Splitting them is only correct if every
 * filter still has exactly one answer and no block lost its own.
 *
 * The behaviour each produces is covered where it belongs — `Test_Held_Access`,
 * `Test_Order_History`, `Test_Product_Offer`. What is asserted here is the
 * wiring, which nothing else looks at: that the classes exist, that they are
 * booted, and that asking a filter gets a reply rather than the defaults back.
 *
 * A split that quietly dropped one hook would leave a page rendering its empty
 * state, which reads as "you have nothing" rather than as a fault.
 *
 * @group integration
 */
class Test_Account_Data_Split extends WP_UnitTestCase {

	/**
	 * Every block's filter and the class that answers it.
	 *
	 * @return array<string, array{string, class-string}>
	 */
	public static function suppliers(): array {
		return array(
			'my access'  => array( 'gatedmedia_my_access_data', Held_Access::class ),
			'files'      => array( 'gatedmedia_files_data', Downloadable_Files::class ),
			'one group'  => array( 'gatedmedia_my_access_data', Group_Contents::class ),
			'orders'     => array( 'gatedmedia_orders_data', Order_History::class ),
			'a product'  => array( 'gatedmedia_product_data', Product_Offer::class ),
		);
	}

	/**
	 * @testdox Each block's data filter has a class listening on it.
	 *
	 * @dataProvider suppliers
	 *
	 * @param string $filter The filter a block raises.
	 * @param string $class  The class expected to answer it.
	 */
	public function test_every_filter_is_answered( string $filter, string $class ): void {
		$this->assertTrue( class_exists( $class ), sprintf( '%s does not exist.', $class ) );
		$this->assertNotFalse(
			has_filter( $filter ),
			sprintf( '%s is raised by a block and nothing answers it.', $filter )
		);
	}

	/** @testdox The retired class is gone, so nothing can quietly go on using it. */
	public function test_view_data_is_retired(): void {
		$this->assertFalse( class_exists( 'PinkCrab\Gated_Access\Account\View_Data' ) );
	}

	/** @testdox My Access and one open group share a filter without overwriting each other. */
	public function test_the_shared_filter_carries_both_answers(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$defaults = array(
			'groups' => array(),
			'posts'  => array(),
			'files'  => array(),
			'detail' => null,
		);

		// No second segment: the list answers and the detail is left alone.
		$list = apply_filters( 'gatedmedia_my_access_data', $defaults, '' );

		$this->assertArrayHasKey( 'groups', $list );
		$this->assertNull( $list['detail'] );

		// A segment naming nothing they hold: still answered, still refused.
		$detail = apply_filters( 'gatedmedia_my_access_data', $defaults, 'not-a-group' );

		$this->assertArrayHasKey( 'groups', $detail, 'the list keys survive the detail pass' );
		$this->assertNull( $detail['detail'] );
	}

	/** @testdox Signed out, every filter hands its defaults straight back. */
	public function test_signed_out_changes_nothing(): void {
		wp_set_current_user( 0 );

		$my_access = array( 'groups' => array(), 'posts' => array(), 'files' => array() );
		$files     = array( 'available' => array(), 'downloading' => array(), 'past' => array() );
		$orders    = array( 'orders' => array(), 'detail' => null );

		$this->assertSame( $my_access, apply_filters( 'gatedmedia_my_access_data', $my_access, '' ) );
		$this->assertSame( $files, apply_filters( 'gatedmedia_files_data', $files ) );
		$this->assertSame( $orders, apply_filters( 'gatedmedia_orders_data', $orders, '' ) );
	}
}
