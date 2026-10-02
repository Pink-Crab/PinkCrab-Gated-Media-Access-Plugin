<?php
/**
 * What a product costs, sale included.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

/**
 * The full price, the sale price when one is on, and what a purchase is charged.
 *
 * A sale is stored as a percentage or an amount off, and worked out from the current price every time, so a percentage stays a percentage when the price changes. It is on only while it gives a price above zero and below the full price.
 */
class Product_Price {

	/**
	 * The full price, minor units.
	 *
	 * @param int $product_id The product.
	 */
	public static function full( int $product_id ): int {
		return max( 0, (int) get_post_meta( $product_id, Product_Meta::META_PRICE, true ) );
	}

	/**
	 * The sale price, minor units, 0 when no sale is on.
	 *
	 * @param int $product_id The product.
	 */
	public static function sale( int $product_id ): int {
		$type  = (string) get_post_meta( $product_id, Product_Meta::META_SALE_TYPE, true );
		$value = (int) get_post_meta( $product_id, Product_Meta::META_SALE_VALUE, true );
		$full  = self::full( $product_id );

		return match ( $type ) {
			Product_Meta::SALE_PERCENT => self::percent_off( $full, $value ),
			Product_Meta::SALE_AMOUNT  => self::amount_off( $full, $value ),
			default                    => 0,
		};
	}

	/**
	 * What a purchase is charged before any coupon: the sale price when one is on, otherwise the full price.
	 *
	 * @param int $product_id The product.
	 */
	public static function charge( int $product_id ): int {
		$sale = self::sale( $product_id );

		return 0 === $sale ? self::full( $product_id ) : $sale;
	}

	/**
	 * A sale price this many percent below a full price, rounded to the minor unit, or 0 when that would not be a sale.
	 *
	 * @param int $full    The full price, minor units.
	 * @param int $percent The percentage off, 1 to 99.
	 */
	public static function percent_off( int $full, int $percent ): int {
		if ( $full <= 0 || $percent < 1 || $percent > 99 ) {
			return 0;
		}

		$sale = (int) round( $full * ( 100 - $percent ) / 100 );

		return $sale > 0 && $sale < $full ? $sale : 0;
	}

	/**
	 * A sale price this much below a full price, or 0 when that would not be a sale (nothing off, or everything).
	 *
	 * @param int $full The full price, minor units.
	 * @param int $off  The amount off, minor units.
	 */
	public static function amount_off( int $full, int $off ): int {
		$sale = $full - $off;

		return $off > 0 && $sale > 0 && $sale < $full ? $sale : 0;
	}
}
