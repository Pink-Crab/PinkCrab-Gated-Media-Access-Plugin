<?php
/**
 * The products list's price column, and putting products on sale in bulk.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Price;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Money;
use PinkCrab\Gated_Access\Support\View;

/**
 * A Price column on the products list, and in its bulk edit a sale: a percentage or an amount off each product's own price, or the sale ended.
 *
 * Core only draws bulk edit fields under a custom column, so the column carries them.
 */
class Product_Sale_Bulk implements Hookable {

	/** The column, and the bulk edit box drawn under it. */
	public const COLUMN = 'gatedmedia_price';

	/** The nonce action and field name. */
	public const NONCE = 'gatedmedia_bulk_sale_nonce';

	/** What to do: '' for no change, or one of the MODE_* values. */
	public const FIELD_MODE = 'gatedmedia_bulk_sale';

	/** How much off: a whole percentage, or a decimal amount in the product's currency. */
	public const FIELD_VALUE = 'gatedmedia_bulk_sale_value';

	/** A percentage off each product's own price. */
	public const MODE_PERCENT = Product_Meta::SALE_PERCENT;

	/** An amount off each product's own price. */
	public const MODE_AMOUNT = Product_Meta::SALE_AMOUNT;

	/** End the sale. */
	public const MODE_END = 'end';

	/**
	 * The column, its cells, the bulk edit box and the save.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_filter( 'manage_' . Post_Types::PRODUCT . '_posts_columns', array( $this, 'columns' ) );
		$loader->admin_action( 'manage_' . Post_Types::PRODUCT . '_posts_custom_column', array( $this, 'render_column' ), 2 );
		$loader->admin_action( 'bulk_edit_custom_box', array( $this, 'render_bulk_edit' ), 2 );
		$loader->admin_action( 'bulk_edit_posts', array( $this, 'save' ) );
	}

	/**
	 * Adds Price after the title.
	 *
	 * @param array<string, string> $columns The list's columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$placed = array();

		foreach ( $columns as $key => $label ) {
			$placed[ $key ] = $label;

			if ( 'title' === $key ) {
				$placed[ self::COLUMN ] = __( 'Price', 'gated-media-access' );
			}
		}

		$placed[ self::COLUMN ] ??= __( 'Price', 'gated-media-access' );

		return $placed;
	}

	/**
	 * The price, with the full price struck through while a sale is on.
	 *
	 * @param string $column  The column being drawn.
	 * @param int    $post_id The product.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$currency = $this->currency( $post_id );
		$full     = Money::format( Product_Price::full( $post_id ), $currency );
		$sale     = Product_Price::sale( $post_id );

		if ( 0 === $sale ) {
			echo esc_html( $full );
			return;
		}

		printf( '<del>%s</del> %s', esc_html( $full ), esc_html( Money::format( $sale, $currency ) ) );
	}

	/**
	 * The sale fields, under the Price column, for someone who manages products.
	 *
	 * @param string $column    The column the box is being drawn for.
	 * @param string $post_type The list's post type.
	 */
	public function render_bulk_edit( string $column, string $post_type ): void {
		if ( self::COLUMN !== $column || Post_Types::PRODUCT !== $post_type || ! current_user_can( Capabilities::manage_products() ) ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE, false );

		View::render( 'admin/bulk-sale' );
	}

	/**
	 * Puts the bulk-edited products on sale, or ends their sale, once core has saved them.
	 *
	 * An amount that gives no real sale (a free product, nothing off, or everything off) leaves that product as it was.
	 *
	 * @param array<int, int|string> $updated The products core saved.
	 */
	public function save( array $updated ): void {
		$mode = $this->mode();

		if ( '' === $mode ) {
			return;
		}

		foreach ( array_map( 'intval', $updated ) as $product_id ) {
			if ( Post_Types::PRODUCT !== get_post_type( $product_id ) || ! current_user_can( 'edit_post', $product_id ) ) {
				continue;
			}

			if ( self::MODE_END === $mode ) {
				delete_post_meta( $product_id, Product_Meta::META_SALE_TYPE );
				delete_post_meta( $product_id, Product_Meta::META_SALE_VALUE );
				continue;
			}

			$value = $this->stored_value( $mode, $product_id );

			if ( 0 !== $value ) {
				update_post_meta( $product_id, Product_Meta::META_SALE_TYPE, $mode );
				update_post_meta( $product_id, Product_Meta::META_SALE_VALUE, $value );
			}
		}
	}

	/**
	 * What to store as the sale's value for one product: the whole percentage, or the amount in its minor units. 0 when that would be no sale for its price.
	 *
	 * @param string $mode       MODE_PERCENT or MODE_AMOUNT.
	 * @param int    $product_id The product.
	 */
	private function stored_value( string $mode, int $product_id ): int {
		$full = Product_Price::full( $product_id );

		if ( self::MODE_PERCENT === $mode ) {
			$percent = (int) $this->value();

			return 0 === Product_Price::percent_off( $full, $percent ) ? 0 : $percent;
		}

		$amount = Money::to_minor( $this->value(), $this->currency( $product_id ) );

		return 0 === Product_Price::amount_off( $full, $amount ) ? 0 : $amount;
	}

	/**
	 * The chosen mode, '' unless the nonce, the capability and the mode all check out.
	 */
	private function mode(): string {
		$nonce = isset( $_REQUEST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE ] ) ) : '';

		if ( false === wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( Capabilities::manage_products() ) ) {
			return '';
		}

		$mode = isset( $_REQUEST[ self::FIELD_MODE ] ) ? sanitize_key( wp_unslash( $_REQUEST[ self::FIELD_MODE ] ) ) : '';

		return in_array( $mode, array( self::MODE_PERCENT, self::MODE_AMOUNT, self::MODE_END ), true ) ? $mode : '';
	}

	/**
	 * How much off, as typed: a number or ''.
	 */
	private function value(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- mode() verified the nonce before this is read.
		$value = isset( $_REQUEST[ self::FIELD_VALUE ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::FIELD_VALUE ] ) ) : '';

		return is_numeric( $value ) ? $value : '';
	}

	/**
	 * The product's currency, GBP when none is stamped.
	 *
	 * @param int $product_id The product.
	 */
	private function currency( int $product_id ): string {
		$currency = (string) get_post_meta( $product_id, Product_Meta::META_CURRENCY, true );

		return '' === $currency ? 'GBP' : $currency;
	}
}
