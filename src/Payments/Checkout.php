<?php
/**
 * Buying a product.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use WP_Post;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Account\Order_History;
use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * The order of operations is architecture §7's whole point: the payment row
 * is created *before* the person leaves for Stripe — so the confirmation
 * has something to attach to and the return page something to poll — and
 * **access lands on Stripe's confirmation and nowhere else**. Nothing here
 * grants for a paid product; `Stripe_Webhook` does, when the event arrives.
 *
 * A free product involves Stripe not at all: direct Access, no payment row
 * (source `free`). A priced product a coupon takes to zero is different —
 * the coupon is spent exactly when a payment completes, so it gets its row,
 * completed on the spot, and grants immediately (source `stripe`, reference
 * the payment uuid, like every payment-backed grant).
 *
 * Eligibility is the allow-list plus the `gatedmedia_product_eligibility`
 * filter. Unlisted never blocks a purchase — visibility restricts how the
 * product is found, not who may buy (architecture §7).
 */
class Checkout {

	/** The source on payment-backed grants; a refund finds them by it. */
	public const SOURCE_STRIPE = 'stripe';

	/** The source on free-product grants — no payment row behind them. */
	public const SOURCE_FREE = 'free';

	/**
	 * The row, the grants and the gateway — nothing else writes any of the
	 * three.
	 *
	 * @param Payment_Store  $store   The payments table's owner.
	 * @param Access_Writer  $writer  The one writer of access records.
	 * @param Stripe_Gateway $gateway The one class that talks to Stripe.
	 */
	public function __construct( private Payment_Store $store, private Access_Writer $writer, private Stripe_Gateway $gateway ) {
	}

	/**
	 * Takes one person buying one product to the right place: straight to
	 * access for free, to Stripe's hosted page for priced.
	 *
	 * @param int    $product_id  The product.
	 * @param int    $user_id     The buyer.
	 * @param string $coupon_code A typed coupon code, '' for none.
	 * @return array{redirect: string}|WP_Error Where to send them, or why not.
	 */
	public function purchase( int $product_id, int $user_id, string $coupon_code = '' ): array|WP_Error {
		$product = get_post( $product_id );

		if ( null === $product || Post_Types::PRODUCT !== $product->post_type || 'publish' !== $product->post_status ) {
			return new WP_Error( 'gatedmedia_no_product', __( 'That product is not for sale.', 'gated-media-access' ) );
		}

		if ( ! $this->eligible( $product, $user_id ) ) {
			return new WP_Error( 'gatedmedia_not_eligible', __( 'This product is not available to you.', 'gated-media-access' ) );
		}

		$price = (int) get_post_meta( $product_id, Product_Meta::META_PRICE, true );

		if ( 0 === $price ) {
			return $this->claim_free( $product, $user_id );
		}

		return $this->priced_purchase( $product, $user_id, $price, $coupon_code );
	}

	/**
	 * A priced product: resolve the coupon, then start the payment.
	 *
	 * @param WP_Post $product     The product.
	 * @param int     $user_id     The buyer.
	 * @param int     $price       Full price, minor units.
	 * @param string  $coupon_code A typed coupon code, '' for none.
	 * @return array{redirect: string}|WP_Error
	 */
	private function priced_purchase( WP_Post $product, int $user_id, int $price, string $coupon_code ): array|WP_Error {
		$coupon = null;

		if ( '' !== $coupon_code ) {
			$coupon = $this->valid_coupon( $coupon_code, $user_id );

			if ( $coupon instanceof WP_Error ) {
				return $coupon;
			}
		}

		$discount = null === $coupon ? 0 : $this->discount( $coupon, $price );

		return $this->begin_payment( $product, $user_id, $price, $discount, null === $coupon ? 0 : $coupon->ID );
	}

	/**
	 * Whether this person may buy this product: on the allow-list when one
	 * exists, and whatever `gatedmedia_product_eligibility` decides on top.
	 *
	 * @param WP_Post $product The product.
	 * @param int     $user_id The would-be buyer.
	 */
	public function eligible( WP_Post $product, int $user_id ): bool {
		$allowed  = array_map( 'strval', (array) get_post_meta( $product->ID, Product_Meta::META_EMAILS, false ) );
		$eligible = true;

		if ( array() !== $allowed ) {
			$user     = get_userdata( $user_id );
			$email    = false === $user ? '' : strtolower( $user->user_email );
			$eligible = in_array( $email, array_map( 'strtolower', $allowed ), true );
		}

		/**
		 * Filters whether a user may buy a product — a site can gate on a
		 * membership level or a CRM flag we know nothing about.
		 *
		 * @param bool $eligible   The allow-list's answer.
		 * @param int  $product_id The product.
		 * @param int  $user_id    The would-be buyer.
		 */
		return (bool) apply_filters( 'gatedmedia_product_eligibility', $eligible, $product->ID, $user_id );
	}

	/**
	 * A free product: direct Access, no payment row, Stripe uninvolved.
	 * Free is not a zero-value order (architecture §7).
	 *
	 * @param WP_Post $product The free product.
	 * @param int     $user_id The claimant.
	 * @return array{redirect: string}
	 */
	private function claim_free( WP_Post $product, int $user_id ): array {
		$this->grant_items(
			$product,
			$user_id,
			self::SOURCE_FREE,
			"user:{$user_id}:product:{$product->ID}"
		);

		return array( 'redirect' => (string) get_permalink( $product ) );
	}

	/**
	 * A priced product: the pending row first, then the hosted session —
	 * unless a coupon took it to zero, where the row completes on the spot
	 * (the coupon is spent at completion, so the completion must exist).
	 *
	 * @param WP_Post $product   The product.
	 * @param int     $user_id   The buyer.
	 * @param int     $price     Full price, minor units.
	 * @param int     $discount  What the coupon takes off, minor units.
	 * @param int     $coupon_id The coupon, 0 for none.
	 * @return array{redirect: string}|WP_Error
	 */
	private function begin_payment( WP_Post $product, int $user_id, int $price, int $discount, int $coupon_id ): array|WP_Error {
		$currency = (string) get_post_meta( $product->ID, Product_Meta::META_CURRENCY, true );
		$currency = '' === $currency ? 'GBP' : $currency;
		$total    = max( 0, $price - $discount );
		$snapshot = array_map( 'strval', (array) get_post_meta( $product->ID, Product_Meta::META_ITEMS, false ) );

		$payment = $this->store->create_pending( $user_id, $product->ID, $total, $currency, $snapshot, $coupon_id, $discount );

		if ( null === $payment ) {
			return new WP_Error( 'gatedmedia_payment_row', __( 'The payment could not be started.', 'gated-media-access' ) );
		}

		if ( 0 === $total ) {
			$this->store->mark_complete( $payment->uuid );
			$this->grant_snapshot( $payment );

			/** This hook is documented in Stripe_Webhook. */
			do_action( 'gatedmedia_payment_completed', $payment->payment_id );

			return array( 'redirect' => $this->return_url( $payment ) );
		}

		$user    = get_userdata( $user_id );
		$session = $this->gateway->create_checkout_session(
			$payment,
			$product->post_title,
			$this->return_url( $payment ),
			(string) get_permalink( $product ),
			false === $user ? '' : $user->user_email
		);

		if ( $session instanceof WP_Error ) {
			$this->store->mark_failed( $payment->uuid );

			return $session;
		}

		$this->store->attach_session( $payment->uuid, $session['id'] );

		return array( 'redirect' => $session['url'] );
	}

	/**
	 * One Access record per snapshot row — how payment-backed access lands,
	 * on completion. The snapshot, not the product's live items: groups are
	 * live and the contents at purchase are not recoverable later (spec §3).
	 * Duration still reads from the product — a duration is a promise about
	 * time, not contents.
	 *
	 * @param Payment $payment The completed payment.
	 */
	public function grant_snapshot( Payment $payment ): void {
		$duration = (string) get_post_meta( $payment->product_id, Product_Meta::META_DURATION, true );
		$days     = '' === $duration ? null : (int) $duration;

		foreach ( $payment->contents_snapshot as $item ) {
			list( $type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

			if ( '' === $identifier ) {
				continue;
			}

			$this->writer->grant( $payment->user_id, $type, $identifier, $days, self::SOURCE_STRIPE, $payment->uuid );
		}
	}

	/**
	 * One Access record per product item, all the same source and
	 * reference — the writer's guard covers each item separately.
	 *
	 * @param WP_Post $product   The product whose items grant.
	 * @param int     $user_id   Who receives.
	 * @param string  $source    stripe or free.
	 * @param string  $reference The payment uuid, or the free claim's key.
	 */
	public function grant_items( WP_Post $product, int $user_id, string $source, string $reference ): void {
		$duration = (string) get_post_meta( $product->ID, Product_Meta::META_DURATION, true );
		$days     = '' === $duration ? null : (int) $duration;

		foreach ( array_map( 'strval', (array) get_post_meta( $product->ID, Product_Meta::META_ITEMS, false ) ) as $item ) {
			list( $type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

			if ( '' === $identifier ) {
				continue;
			}

			$this->writer->grant( $user_id, $type, $identifier, $days, $source, $reference );
		}
	}

	/**
	 * A typed code to its valid coupon, or the reason it is not.
	 *
	 * Usage is counted from completed payments, never stored — so an
	 * abandoned checkout consumes nothing (spec §1b).
	 *
	 * @param string $code    The code as typed.
	 * @param int    $user_id The buyer.
	 */
	private function valid_coupon( string $code, int $user_id ): WP_Post|WP_Error {
		$found  = get_page_by_path( sanitize_title( $code ), OBJECT, Post_Types::COUPON );
		$coupon = $found instanceof WP_Post && 'publish' === $found->post_status ? $found : null;
		$valid  = null !== $coupon && $this->coupon_not_expired( $coupon ) && $this->coupon_within_limits( $coupon, $user_id );

		/**
		 * Filters whether a coupon applies for this buyer.
		 *
		 * @param bool          $valid   The checks' answer so far.
		 * @param WP_Post|null  $coupon  The coupon, when the code matched one.
		 * @param int           $user_id The buyer.
		 */
		$valid = (bool) apply_filters( 'gatedmedia_coupon_valid', $valid, $coupon, $user_id );

		return $valid && null !== $coupon
			? $coupon
			: new WP_Error( 'gatedmedia_bad_coupon', __( 'That coupon cannot be used.', 'gated-media-access' ) );
	}

	/**
	 * Whether the coupon's day has not passed. Empty is never.
	 *
	 * @param WP_Post $coupon The coupon.
	 */
	private function coupon_not_expired( WP_Post $coupon ): bool {
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
	private function coupon_within_limits( WP_Post $coupon, int $user_id ): bool {
		$usage_limit = (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_USAGE_LIMIT, true );

		if ( '' !== $usage_limit && $this->store->coupon_completions( $coupon->ID ) >= (int) $usage_limit ) {
			return false;
		}

		$per_user = (string) get_post_meta( $coupon->ID, Coupon_Metabox::META_PER_USER_LIMIT, true );

		return '' === $per_user || $this->store->coupon_completions( $coupon->ID, $user_id ) < (int) $per_user;
	}

	/**
	 * What the coupon takes off a subtotal, clamped to it. Filterable last.
	 *
	 * @param WP_Post $coupon   The valid coupon.
	 * @param int     $subtotal The price before it, minor units.
	 */
	private function discount( WP_Post $coupon, int $subtotal ): int {
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
	 * Where the buyer lands after Stripe: their own order, marked as the one
	 * just placed. Reads only — access is granted on the confirmation and
	 * nowhere else. Filterable, so a site can send them somewhere of its own.
	 *
	 * @param Payment $payment The payment they will be asking about.
	 */
	private function return_url( Payment $payment ): string {
		// The order itself, flagged as just placed. §7.8's states are drawn on
		// that page by the `payment-status` block, so there is no return page
		// of its own — and the account area already sends a buyer whose session
		// lapsed through wp-login and back, which a standalone page could not.
		$url = add_query_arg(
			Order_History::NEW_ORDER,
			$payment->uuid,
			Account_Url::detail( 'orders', $payment->uuid )
		);

		/**
		 * Filters the checkout return URL.
		 *
		 * @param string  $url     Where Stripe sends the buyer back to.
		 * @param Payment $payment The payment in flight.
		 */
		return (string) apply_filters( 'gatedmedia_checkout_return_url', $url, $payment );
	}
}
