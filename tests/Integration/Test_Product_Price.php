<?php
/**
 * What a product costs, sale included.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Price;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * A sale is a percentage or an amount off, worked out from the current price, and on only while it lands above zero and below the price.
 *
 * @group integration
 */
class Test_Product_Price extends WP_UnitTestCase {

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		// The framework's tear_down unregisters every meta key.
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();

		$this->product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
	}

	/** @testdox With no sale, the full price is what is charged. */
	public function test_no_sale(): void {
		$this->price( 1500 );

		$this->assertSame( 1500, Product_Price::full( $this->product_id ) );
		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 1500, Product_Price::charge( $this->product_id ) );
	}

	/** @testdox A percentage sale comes off the price, and is what is charged. */
	public function test_a_percentage_sale(): void {
		$this->price( 1500 );
		$this->sale( Product_Meta::SALE_PERCENT, 20 );

		$this->assertSame( 1200, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 1200, Product_Price::charge( $this->product_id ) );
		$this->assertSame( 1500, Product_Price::full( $this->product_id ) );
	}

	/** @testdox An amount sale comes off the price, and is what is charged. */
	public function test_an_amount_sale(): void {
		$this->price( 1500 );
		$this->sale( Product_Meta::SALE_AMOUNT, 300 );

		$this->assertSame( 1200, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 1200, Product_Price::charge( $this->product_id ) );
	}

	/** @testdox A percentage sale follows the price when the price changes. */
	public function test_a_percentage_follows_the_price(): void {
		$this->price( 1500 );
		$this->sale( Product_Meta::SALE_PERCENT, 20 );

		$this->price( 2000 );

		$this->assertSame( 1600, Product_Price::sale( $this->product_id ) );
	}

	/** @testdox An amount sale stays the same amount off when the price changes. */
	public function test_an_amount_follows_the_price(): void {
		$this->price( 1500 );
		$this->sale( Product_Meta::SALE_AMOUNT, 300 );

		$this->price( 2000 );

		$this->assertSame( 1700, Product_Price::sale( $this->product_id ) );
	}

	/** @testdox An amount sale left at or above a price that was later lowered is off. */
	public function test_an_amount_above_a_lowered_price(): void {
		$this->price( 1500 );
		$this->sale( Product_Meta::SALE_AMOUNT, 1000 );

		$this->price( 1000 );

		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 1000, Product_Price::charge( $this->product_id ) );
	}

	/** @testdox A percentage outside 1 to 99 is no sale. */
	public function test_a_percentage_out_of_range(): void {
		$this->price( 1500 );

		foreach ( array( 0, 100, 150 ) as $percent ) {
			$this->sale( Product_Meta::SALE_PERCENT, $percent );

			$this->assertSame( 0, Product_Price::sale( $this->product_id ), "{$percent}%" );
		}
	}

	/** @testdox A sale type with no value is no sale. */
	public function test_a_type_without_a_value(): void {
		$this->price( 1500 );
		update_post_meta( $this->product_id, Product_Meta::META_SALE_TYPE, Product_Meta::SALE_AMOUNT );

		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
	}

	/** @testdox A value with no sale type is no sale. */
	public function test_a_value_without_a_type(): void {
		$this->price( 1500 );
		update_post_meta( $this->product_id, Product_Meta::META_SALE_VALUE, 300 );

		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
	}

	/** @testdox An unknown sale type is no sale. */
	public function test_an_unknown_type(): void {
		global $wpdb;

		$this->price( 1500 );
		update_post_meta( $this->product_id, Product_Meta::META_SALE_VALUE, 300 );

		// The meta's sanitiser blanks an unknown type, so the row is written as a raw import would.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $this->product_id,
				'meta_key'   => Product_Meta::META_SALE_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'bogof', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $this->product_id, 'post_meta' );

		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
	}

	/** @testdox A free product stays free whatever sale is stored. */
	public function test_a_free_product(): void {
		$this->price( 0 );
		$this->sale( Product_Meta::SALE_PERCENT, 20 );

		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 0, Product_Price::charge( $this->product_id ) );
	}

	/** @testdox A negative price written straight to the database, past the meta's absint, reads as free. */
	public function test_a_negative_price(): void {
		global $wpdb;

		// update_post_meta() would run absint and store 500, so the row is written as a raw import would.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $this->product_id,
				'meta_key'   => Product_Meta::META_PRICE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '-500', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $this->product_id, 'post_meta' );

		$this->assertSame( 0, Product_Price::full( $this->product_id ) );
	}

	/** @testdox A product with no price meta at all is free, with no sale. */
	public function test_no_meta_at_all(): void {
		$this->assertSame( 0, Product_Price::full( $this->product_id ) );
		$this->assertSame( 0, Product_Price::sale( $this->product_id ) );
		$this->assertSame( 0, Product_Price::charge( $this->product_id ) );
	}

	/** @testdox A percentage off gives the sale price, rounded to the minor unit. */
	public function test_percent_off(): void {
		$this->assertSame( 1200, Product_Price::percent_off( 1500, 20 ) );
		$this->assertSame( 670, Product_Price::percent_off( 1000, 33 ) );
		$this->assertSame( 990, Product_Price::percent_off( 1000, 1 ) );
		$this->assertSame( 10, Product_Price::percent_off( 1000, 99 ) );
		$this->assertSame( 2, Product_Price::percent_off( 3, 50 ) );
	}

	/** @testdox A percentage outside 1 to 99 gives no sale. */
	public function test_percent_off_out_of_range(): void {
		foreach ( array( 0, 100, 150, -5 ) as $percent ) {
			$this->assertSame( 0, Product_Price::percent_off( 1500, $percent ), "{$percent}%" );
		}
	}

	/** @testdox A percentage off a free product gives no sale. */
	public function test_percent_off_free(): void {
		$this->assertSame( 0, Product_Price::percent_off( 0, 20 ) );
	}

	/** @testdox A percentage that rounds back to the price gives no sale. */
	public function test_percent_off_rounding_to_the_price(): void {
		$this->assertSame( 0, Product_Price::percent_off( 1, 50 ) );
	}

	/** @testdox An amount off gives the price less that amount. */
	public function test_amount_off(): void {
		$this->assertSame( 1250, Product_Price::amount_off( 1500, 250 ) );
		$this->assertSame( 1, Product_Price::amount_off( 1500, 1499 ) );
		$this->assertSame( 1499, Product_Price::amount_off( 1500, 1 ) );
	}

	/** @testdox An amount off that takes the price to nothing or below gives no sale. */
	public function test_amount_off_too_much(): void {
		$this->assertSame( 0, Product_Price::amount_off( 1500, 1500 ) );
		$this->assertSame( 0, Product_Price::amount_off( 1500, 2000 ) );
	}

	/** @testdox Nothing off, or a negative amount, gives no sale. */
	public function test_amount_off_nothing(): void {
		$this->assertSame( 0, Product_Price::amount_off( 1500, 0 ) );
		$this->assertSame( 0, Product_Price::amount_off( 1500, -200 ) );
	}

	/** @testdox An amount off a free product gives no sale. */
	public function test_amount_off_free(): void {
		$this->assertSame( 0, Product_Price::amount_off( 0, 100 ) );
	}

	/** @testdox Both sale keys are registered in REST, protected, and writable only by product managers, like the price. */
	public function test_sale_meta_is_registered(): void {
		$meta       = new Product_Meta( new Settings(), new Access_Taxonomy() );
		$registered = get_registered_meta_keys( 'post', Post_Types::PRODUCT );

		foreach ( array( Product_Meta::META_SALE_TYPE, Product_Meta::META_SALE_VALUE ) as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, Post_Types::PRODUCT ), $key );
			$this->assertTrue( $meta->protect_meta( false, $key, 'post' ), $key );
			$this->assertTrue( (bool) $registered[ $key ]['show_in_rest'], $key );
		}

		$this->assertSame( 'string', $registered[ Product_Meta::META_SALE_TYPE ]['type'] );
		$this->assertSame( 'integer', $registered[ Product_Meta::META_SALE_VALUE ]['type'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_SALE_TYPE ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_SALE_VALUE ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_SALE_TYPE ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_SALE_VALUE ) );
	}

	/** @testdox The sale type stores only percent or amount, and anything else as no sale. */
	public function test_sale_type_is_sanitised(): void {
		$this->assertSame( 'percent', sanitize_meta( Product_Meta::META_SALE_TYPE, 'percent', 'post', Post_Types::PRODUCT ) );
		$this->assertSame( 'amount', sanitize_meta( Product_Meta::META_SALE_TYPE, 'amount', 'post', Post_Types::PRODUCT ) );
		$this->assertSame( '', sanitize_meta( Product_Meta::META_SALE_TYPE, 'bogof', 'post', Post_Types::PRODUCT ) );
		$this->assertSame( '', sanitize_meta( Product_Meta::META_SALE_TYPE, '', 'post', Post_Types::PRODUCT ) );
	}

	/** @testdox The sale value is stored as a whole positive number or zero. */
	public function test_sale_value_is_sanitised(): void {
		$this->assertSame( 300, (int) sanitize_meta( Product_Meta::META_SALE_VALUE, '300', 'post', Post_Types::PRODUCT ) );
		$this->assertSame( 300, (int) sanitize_meta( Product_Meta::META_SALE_VALUE, -300, 'post', Post_Types::PRODUCT ) );
		$this->assertSame( 0, (int) sanitize_meta( Product_Meta::META_SALE_VALUE, 'nope', 'post', Post_Types::PRODUCT ) );
	}

	/**
	 * Sets the price.
	 *
	 * @param int $price Minor units.
	 */
	private function price( int $price ): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, $price );
	}

	/**
	 * Puts the product on sale.
	 *
	 * @param string $type  percent or amount.
	 * @param int    $value Whole percent, or minor units.
	 */
	private function sale( string $type, int $value ): void {
		update_post_meta( $this->product_id, Product_Meta::META_SALE_TYPE, $type );
		update_post_meta( $this->product_id, Product_Meta::META_SALE_VALUE, $value );
	}
}
