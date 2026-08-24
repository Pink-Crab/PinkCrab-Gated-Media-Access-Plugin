<?php
/**
 * Whether a coupon holds, and what it takes off.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use WP_Post;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * The coupon rules, asked twice and answered the same way both times.
 *
 * §6.14's flow shows the buyer what a coupon saves before they commit, and
 * `Checkout` then charges them — so the same code is judged once to price a
 * page and again to take the money. They have to agree, and the second answer
 * is the one that counts: a coupon that expires or hits its limit between the
 * two is refused at purchase, whatever the page said.
 *
 * Split out of `Checkout` because it is a different question. `Checkout` takes
 * somebody's money; this decides what a code is worth, writes nothing, and
 * spends nothing. Usage is counted from completed payments rather than stored,
 * so an abandoned checkout consumes nothing (spec §1b).
 */
class Coupon_Pricing {

	/**
	 * Reads completions, and nothing else.
	 *
	 * @param Payment_Store $store The payments table's owner.
	 */
	public function __construct( private Payment_Store $store ) {
	}

	/**
	 * A typed code to its valid coupon, or the reason it is not.
	 *
	 * @param string $code    The code as typed.
	 * @param int    $user_id The buyer.
	 */
	public function valid_coupon( string $code, int $user_id ): WP_Post|WP_Error {
		$found  = get_page_by_path( sanitize_title( $code ), OBJECT, Post_Types::COUPON );
		$coupon = $found instanceof WP_Post && 'publish' === $found->post_status ? $found : null;
		$valid  = null !== $coupon && $this->not_expired( $coupon ) && $this->within_limits( $coupon, $user_id );

		/**
		 * Filters whether a coupon applies for this buyer.
		 *
		 * @param bool         $valid   The checks' answer so far.
		 * @param WP_Post|null $coupon  The coupon, when the code matched one.
		 * @param int          $user_id The buyer.
		 */
		$valid = (bool) apply_filters( 'gatedmedia_coupon_valid', $valid, $coupon, $user_id );

		return $valid && null !== $coupon
			? $coupon
			: new WP_Error( 'gatedmedia_bad_coupon', __( 'That coupon cannot be used.', 'gated-media-access' ) );
	}

	/**
	 * What the coupon takes off a subtotal, clamped to it. Filterable last.
	 *
	 * @param WP_Post $coupon   The valid coupon.
	 * @param int     $subtotal The price before it, minor units.
	 */
	public function discount( WP_Post $coupon, int $subtotal ): int {
		$value  = (int) get_post_meta( $coupon->ID, Coupon_Metabox::META_VALUE, true );
		$amount = 'fixed' === (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_TYPE, true )
			? $value
			: (int) round( $subtotal * min( 100, $value ) / 100 );

		/**
		 * Filters what a coupon takes off.
		 *
		 * @param int     $amount   The computed discount, minor units.
		 * @param WP_Post $coupon   The coupon.
		 * @param int     $subtotal The price before it, minor units.
		 */
		$amount = (int) apply_filters( 'gatedmedia_coupon_discount', $amount, $coupon, $subtotal );

		return max( 0, min( $subtotal, $amount ) );
	}

	/**
	 * Whether the coupon's day has not passed. Empty is never.
	 *
	 * The stored value is a UTC MySQL datetime, not a timestamp.
	 *
	 * @param WP_Post $coupon The coupon.
	 */
	private function not_expired( WP_Post $coupon ): bool {
		$expires = (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_EXPIRES_AT, true );

		if ( '' === $expires ) {
			return true;
		}

		$timestamp = strtotime( $expires . ' +0000' );

		return false !== $timestamp && $timestamp >= time();
	}

	/**
	 * Whether both usage limits have room, counted from completed payments.
	 *
	 * @param WP_Post $coupon  The coupon.
	 * @param int     $user_id The buyer.
	 */
	private function within_limits( WP_Post $coupon, int $user_id ): bool {
		$usage_limit = (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_USAGE_LIMIT, true );

		if ( '' !== $usage_limit && $this->store->coupon_completions( $coupon->ID ) >= (int) $usage_limit ) {
			return false;
		}

		$per_user = (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_PER_USER_LIMIT, true );

		return '' === $per_user || $this->store->coupon_completions( $coupon->ID, $user_id ) < (int) $per_user;
	}
}
