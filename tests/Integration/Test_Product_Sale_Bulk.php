<?php
/**
 * The products list's price column, and sales set in bulk.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Product_Sale_Bulk;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Price;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Money;

/**
 * A percentage or an amount comes off each product's own price, End sale removes it, and nothing moves without the nonce and the capability.
 *
 * @group integration
 */
class Test_Product_Sale_Bulk extends WP_UnitTestCase {

	private Product_Sale_Bulk $bulk;

	public function set_up(): void {
		parent::set_up();

		$this->bulk = new Product_Sale_Bulk();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_REQUEST = array();

		parent::tear_down();
	}

	/** @testdox Price is added straight after the title, and the other columns are kept. */
	public function test_price_column_after_the_title(): void {
		$columns = $this->bulk->columns(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame( array( 'cb', 'title', Product_Sale_Bulk::COLUMN, 'date' ), array_keys( $columns ) );
		$this->assertSame( 'Price', $columns[ Product_Sale_Bulk::COLUMN ] );
	}

	/** @testdox With no title column, Price goes on the end. */
	public function test_price_column_without_a_title(): void {
		$this->assertSame( array( 'date', Product_Sale_Bulk::COLUMN ), array_keys( $this->bulk->columns( array( 'date' => 'Date' ) ) ) );
	}

	/** @testdox The column shows the full price when there is no sale. */
	public function test_column_without_a_sale(): void {
		$product = $this->product( 1500 );

		$this->assertSame( Money::format( 1500, 'GBP' ), $this->cell( $product ) );
	}

	/** @testdox The column strikes the full price through beside the sale price. */
	public function test_column_with_a_sale(): void {
		$product = $this->product( 1500, 1200 );

		$this->assertSame(
			'<del>' . Money::format( 1500, 'GBP' ) . '</del> ' . Money::format( 1200, 'GBP' ),
			$this->cell( $product )
		);
	}

	/** @testdox A free product's column says free. */
	public function test_column_free(): void {
		$this->assertSame( Money::format( 0, 'GBP' ), $this->cell( $this->product( 0 ) ) );
	}

	/** @testdox Another column's cell is left alone. */
	public function test_other_columns_draw_nothing(): void {
		ob_start();
		$this->bulk->render_column( 'date', $this->product( 1500 ) );

		$this->assertSame( '', ob_get_clean() );
	}

	/** @testdox The bulk edit box shows the sale fields and its nonce under the Price column. */
	public function test_bulk_box_is_drawn(): void {
		$html = $this->bulk_box( Product_Sale_Bulk::COLUMN, Post_Types::PRODUCT );

		$this->assertStringContainsString( 'name="' . Product_Sale_Bulk::FIELD_MODE . '"', $html );
		$this->assertStringContainsString( 'value="' . Product_Sale_Bulk::MODE_PERCENT . '"', $html );
		$this->assertStringContainsString( 'value="' . Product_Sale_Bulk::MODE_AMOUNT . '"', $html );
		$this->assertStringContainsString( 'value="' . Product_Sale_Bulk::MODE_END . '"', $html );
		$this->assertStringContainsString( 'name="' . Product_Sale_Bulk::FIELD_VALUE . '"', $html );
		$this->assertStringContainsString( 'name="' . Product_Sale_Bulk::NONCE . '"', $html );
	}

	/** @testdox The box is not drawn under another column. */
	public function test_bulk_box_other_column(): void {
		$this->assertSame( '', $this->bulk_box( 'date', Post_Types::PRODUCT ) );
	}

	/** @testdox The box is not drawn on another post type's list. */
	public function test_bulk_box_other_type(): void {
		$this->assertSame( '', $this->bulk_box( Product_Sale_Bulk::COLUMN, 'post' ) );
	}

	/** @testdox The box is not drawn for someone who cannot manage products. */
	public function test_bulk_box_needs_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->bulk_box( Product_Sale_Bulk::COLUMN, Post_Types::PRODUCT ) );
	}

	/** @testdox Percentage off takes the percentage off each product's own price. */
	public function test_put_on_sale(): void {
		$first  = $this->product( 1500 );
		$second = $this->product( 1000 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( $first, $second ) );

		$this->assertSame( 1200, $this->sale( $first ) );
		$this->assertSame( 800, $this->sale( $second ) );
	}

	/** @testdox Percentage off replaces a sale already on. */
	public function test_put_on_sale_replaces(): void {
		$product = $this->product( 1500, 1400 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '50', array( $product ) );

		$this->assertSame( 750, $this->sale( $product ) );
	}

	/** @testdox Percentage off leaves a free product alone. */
	public function test_put_on_sale_skips_free(): void {
		$product = $this->product( 0 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( $product ) );

		$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ) );
	}

	/** @testdox A percentage outside 1 to 99, or none, puts nothing on sale. */
	public function test_put_on_sale_bad_percent(): void {
		$product = $this->product( 1500 );

		foreach ( array( '0', '100', '150', 'abc', '' ) as $percent ) {
			$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, $percent, array( $product ) );

			$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ), "'{$percent}' put it on sale" );
		}
	}

	/** @testdox A percentage typed with decimals uses its whole part. */
	public function test_percent_with_decimals(): void {
		$product = $this->product( 1500 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20.7', array( $product ) );

		$this->assertSame( 1200, $this->sale( $product ) );
	}

	/** @testdox Amount off takes the amount, typed in the currency, off each product's own price. */
	public function test_amount_off(): void {
		$first  = $this->product( 1500 );
		$second = $this->product( 1000 );

		$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, '2.50', array( $first, $second ) );

		$this->assertSame( 1250, $this->sale( $first ) );
		$this->assertSame( 750, $this->sale( $second ) );
	}

	/** @testdox Amount off reads the amount in each product's own currency. */
	public function test_amount_off_in_a_currency_with_no_decimals(): void {
		$product = $this->product( 1500 );
		update_post_meta( $product, Product_Meta::META_CURRENCY, 'JPY' );

		$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, '300', array( $product ) );

		$this->assertSame( 1200, $this->sale( $product ) );
	}

	/** @testdox Amount off replaces a sale already on. */
	public function test_amount_off_replaces(): void {
		$product = $this->product( 1500, 1200 );

		$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, '1', array( $product ) );

		$this->assertSame( 1400, $this->sale( $product ) );
	}

	/** @testdox An amount that takes the price to nothing or below leaves the product alone. */
	public function test_amount_off_too_much(): void {
		$product = $this->product( 1500 );

		foreach ( array( '15', '15.00', '20' ) as $amount ) {
			$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, $amount, array( $product ) );

			$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ), "'{$amount}' put it on sale" );
		}
	}

	/** @testdox No amount, a zero, a negative or text puts nothing on sale. */
	public function test_amount_off_bad_value(): void {
		$product = $this->product( 1500 );

		foreach ( array( '0', '-2', 'abc', '' ) as $amount ) {
			$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, $amount, array( $product ) );

			$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ), "'{$amount}' put it on sale" );
		}
	}

	/** @testdox Amount off leaves a free product alone. */
	public function test_amount_off_skips_free(): void {
		$product = $this->product( 0 );

		$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, '2', array( $product ) );

		$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ) );
	}

	/** @testdox End sale removes the sale. */
	public function test_end_sale(): void {
		$first  = $this->product( 1500, 1200 );
		$second = $this->product( 1000, 800 );

		$this->bulk_save( Product_Sale_Bulk::MODE_END, '', array( $first, $second ) );

		$this->assertSame( '', get_post_meta( $first, Product_Meta::META_SALE_TYPE, true ) );
		$this->assertSame( '', get_post_meta( $first, Product_Meta::META_SALE_VALUE, true ) );
		$this->assertSame( '', get_post_meta( $second, Product_Meta::META_SALE_TYPE, true ) );
		$this->assertSame( 0, $this->sale( $second ) );
	}

	/** @testdox A percentage is stored as the percentage, so it follows the price; an amount is stored in minor units. */
	public function test_stores_the_type_and_value(): void {
		$percent = $this->product( 1500 );
		$amount  = $this->product( 1500 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( $percent ) );
		$this->bulk_save( Product_Sale_Bulk::MODE_AMOUNT, '2.50', array( $amount ) );

		$this->assertSame( Product_Meta::SALE_PERCENT, get_post_meta( $percent, Product_Meta::META_SALE_TYPE, true ) );
		$this->assertSame( 20, (int) get_post_meta( $percent, Product_Meta::META_SALE_VALUE, true ) );
		$this->assertSame( Product_Meta::SALE_AMOUNT, get_post_meta( $amount, Product_Meta::META_SALE_TYPE, true ) );
		$this->assertSame( 250, (int) get_post_meta( $amount, Product_Meta::META_SALE_VALUE, true ) );

		update_post_meta( $percent, Product_Meta::META_PRICE, 2000 );
		$this->assertSame( 1600, $this->sale( $percent ) );
	}

	/** @testdox No change leaves every sale as it was. */
	public function test_no_change(): void {
		$product = $this->product( 1500, 1200 );

		$this->bulk_save( '', '50', array( $product ) );

		$this->assertSame( 1200, $this->sale( $product ) );
	}

	/** @testdox An unknown mode changes nothing. */
	public function test_unknown_mode(): void {
		$product = $this->product( 1500, 1200 );

		$this->bulk_save( 'delete', '50', array( $product ) );

		$this->assertSame( 1200, $this->sale( $product ) );
	}

	/** @testdox Without the nonce nothing changes. */
	public function test_needs_the_nonce(): void {
		$product = $this->product( 1500 );

		$_REQUEST = array(
			Product_Sale_Bulk::FIELD_MODE    => Product_Sale_Bulk::MODE_PERCENT,
			Product_Sale_Bulk::FIELD_VALUE => '20',
			Product_Sale_Bulk::NONCE         => 'not-a-nonce',
		);
		$this->bulk->save( array( $product ) );

		$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ) );
	}

	/** @testdox Someone who cannot manage products changes nothing, nonce or not. */
	public function test_needs_the_capability(): void {
		$product = $this->product( 1500 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( $product ) );

		$this->assertSame( '', get_post_meta( $product, Product_Meta::META_SALE_TYPE, true ) );
	}

	/** @testdox A post that is not a product is left alone. */
	public function test_skips_other_post_types(): void {
		$post = self::factory()->post->create();
		update_post_meta( $post, Product_Meta::META_PRICE, 1500 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( $post ) );

		$this->assertSame( '', get_post_meta( $post, Product_Meta::META_SALE_TYPE, true ) );
	}

	/** @testdox Ids arriving as strings, as core passes them, are handled. */
	public function test_string_ids(): void {
		$product = $this->product( 1500 );

		$this->bulk_save( Product_Sale_Bulk::MODE_PERCENT, '20', array( (string) $product ) );

		$this->assertSame( 1200, $this->sale( $product ) );
	}

	/** @testdox The bulk save is hooked to core's bulk_edit_posts in the admin. */
	public function test_hooks(): void {
		set_current_screen( 'edit-' . Post_Types::PRODUCT );

		$loader = new \PinkCrab\Loader\Hook_Loader();
		$this->bulk->register_hooks( $loader );
		$loader->register_hooks();

		$this->assertNotFalse( has_action( 'bulk_edit_posts', array( $this->bulk, 'save' ) ) );
		$this->assertNotFalse( has_action( 'bulk_edit_custom_box', array( $this->bulk, 'render_bulk_edit' ) ) );
		$this->assertNotFalse( has_filter( 'manage_' . Post_Types::PRODUCT . '_posts_columns', array( $this->bulk, 'columns' ) ) );

		set_current_screen( 'front' );
	}

	/**
	 * A published product at this price, on sale when a sale is given.
	 *
	 * @param int      $price Minor units.
	 * @param int|null $sale  Minor units, null for none.
	 */
	private function product( int $price, ?int $sale = null ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		update_post_meta( $product_id, Product_Meta::META_CURRENCY, 'GBP' );

		// Stored as the amount off that gives this sale price.
		if ( null !== $sale ) {
			update_post_meta( $product_id, Product_Meta::META_SALE_TYPE, Product_Meta::SALE_AMOUNT );
			update_post_meta( $product_id, Product_Meta::META_SALE_VALUE, $price - $sale );
		}

		return $product_id;
	}

	/**
	 * The sale price the product now has, 0 for none.
	 *
	 * @param int $product_id The product.
	 */
	private function sale( int $product_id ): int {
		return Product_Price::sale( $product_id );
	}

	/**
	 * The Price cell for a product.
	 *
	 * @param int $product_id The product.
	 */
	private function cell( int $product_id ): string {
		ob_start();
		$this->bulk->render_column( Product_Sale_Bulk::COLUMN, $product_id );

		return (string) ob_get_clean();
	}

	/**
	 * What the bulk edit box draws.
	 *
	 * @param string $column    The column.
	 * @param string $post_type The list's type.
	 */
	private function bulk_box( string $column, string $post_type ): string {
		ob_start();
		$this->bulk->render_bulk_edit( $column, $post_type );

		return (string) ob_get_clean();
	}

	/**
	 * Saves a nonced bulk edit over these ids.
	 *
	 * @param string            $mode    The mode.
	 * @param string            $percent The percentage as typed.
	 * @param array<int, mixed> $ids     The ids core saved.
	 */
	private function bulk_save( string $mode, string $percent, array $ids ): void {
		$_REQUEST = array(
			Product_Sale_Bulk::FIELD_MODE    => $mode,
			Product_Sale_Bulk::FIELD_VALUE => $percent,
			Product_Sale_Bulk::NONCE         => wp_create_nonce( Product_Sale_Bulk::NONCE ),
		);

		$this->bulk->save( $ids );
	}
}
