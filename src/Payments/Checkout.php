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
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
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
		// Built here rather than injected: they are calculations over the same
		// store, with no lifecycle of their own and nobody else resolving them.
		$this->holds   = new Coupon_Hold();
		$this->coupons = new Coupon_Pricing( $store, $this->holds );
	}

	/**
	 * The coupon rules, asked to price a page and again to charge for it.
	 *
	 * @var Coupon_Pricing
	 */
	private Coupon_Pricing $coupons;

	/**
	 * The short reservation a checkout puts on a limited coupon.
	 *
	 * @var Coupon_Hold
	 */
	private Coupon_Hold $holds;

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
	 * What a coupon would take off, without committing to anything.
	 *
	 * §6.14's flow is apply, *see the discount*, then buy. This answers the
	 * middle step and writes nothing: no payment row, no coupon spent, no
	 * Stripe. The same `valid_coupon()` runs again inside `purchase()`, so a
	 * coupon that expires or hits its limit between the two is refused there —
	 * what this returns is never what a price is charged on.
	 *
	 * @param int    $product_id The product being looked at.
	 * @param int    $user_id    Who is looking, 0 signed out.
	 * @param string $code       The typed code.
	 * @return array{applied: bool, discount: int, total: int, error: string}
	 */
	public function preview( int $product_id, int $user_id, string $code ): array {
		$price = (int) get_post_meta( $product_id, Product_Meta::META_PRICE, true );
		$none  = array(
			'applied'  => false,
			'discount' => 0,
			'total'    => $price,
			'error'    => '',
		);

		$product = get_post( $product_id );

		// A free product has nothing to discount, and a coupon cannot be
		// judged for somebody who is not signed in — per-user limits need a
		// user. Both answer "no coupon" rather than an error.
		if ( '' === $code || 0 === $price || 0 === $user_id
			|| ! $product instanceof WP_Post || Post_Types::PRODUCT !== $product->post_type ) {
			return $none;
		}

		$coupon = $this->coupons->valid_coupon( $code, $user_id );

		if ( $coupon instanceof WP_Error ) {
			return array( 'error' => $coupon->get_error_message() ) + $none;
		}

		$discount = $this->coupons->discount( $coupon, $price );

		return array(
			'applied'  => true,
			'discount' => $discount,
			'total'    => $price - $discount,
			'error'    => '',
		);
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
			$coupon = $this->coupons->valid_coupon( $coupon_code, $user_id );

			if ( $coupon instanceof WP_Error ) {
				return $coupon;
			}
		}

		$discount = null === $coupon ? 0 : $this->coupons->discount( $coupon, $price );

		return $this->begin_payment( $product, $user_id, $price, $discount, $coupon );
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
	 * @param WP_Post      $product  The product.
	 * @param int          $user_id  The buyer.
	 * @param int          $price    Full price, minor units.
	 * @param int          $discount What the coupon takes off, minor units.
	 * @param WP_Post|null $coupon   The coupon, null for none.
	 * @return array{redirect: string}|WP_Error
	 */
	private function begin_payment( WP_Post $product, int $user_id, int $price, int $discount, ?WP_Post $coupon ): array|WP_Error {
		$currency = (string) get_post_meta( $product->ID, Product_Meta::META_CURRENCY, true );
		$currency = '' === $currency ? 'GBP' : $currency;
		$total    = max( 0, $price - $discount );
		$snapshot = array_map( 'strval', (array) get_post_meta( $product->ID, Product_Meta::META_ITEMS, false ) );

		$payment = $this->store->create_pending( $user_id, $product->ID, $total, $currency, $snapshot, null === $coupon ? 0 : $coupon->ID, $discount );

		if ( null === $payment ) {
			return new WP_Error( 'gatedmedia_payment_row', __( 'The payment could not be started.', 'gated-media-access' ) );
		}

		// The row is the hold's name, so the reservation comes after it. A
		// buyer who loses the race has taken nothing and is told the same
		// thing the limit check tells everybody else.
		if ( null !== $coupon && ! $this->reserve( $coupon, $payment ) ) {
			$this->store->mark_failed( $payment->uuid );

			return new WP_Error( 'gatedmedia_bad_coupon', __( 'That coupon cannot be used.', 'gated-media-access' ) );
		}

		return 0 === $total
			? $this->complete_now( $payment )
			: $this->to_stripe( $product, $payment );
	}

	/**
	 * A coupon took the total to zero: the row completes here rather than on
	 * a confirmation that will never arrive, and grants on the spot.
	 *
	 * @param Payment $payment The pending row.
	 * @return array{redirect: string}|WP_Error
	 */
	private function complete_now( Payment $payment ): array|WP_Error {
		// mark_complete() answers whether we moved the row, and only the
		// mover grants — the same rule the webhook follows.
		if ( ! $this->store->mark_complete( $payment->uuid ) ) {
			return new WP_Error( 'gatedmedia_payment_row', __( 'The payment could not be completed.', 'gated-media-access' ) );
		}

		$failed = $this->grant_snapshot( $payment );

		if ( $failed instanceof WP_Error ) {
			$this->store->record_grant_error( $payment->uuid, $failed->get_error_message() );
		}

		// The completion counts from here, so the reservation standing in for
		// it is given back.
		$this->release_hold( $payment );

		/** This hook is documented in Stripe_Webhook. */
		do_action( 'gatedmedia_payment_completed', $payment->payment_id );

		return array( 'redirect' => $this->return_url( $payment ) );
	}

	/**
	 * The hosted session, and the buyer's road to it. A gateway that refuses
	 * fails the row and gives back whatever it was holding — nothing was
	 * charged and nobody is going to Stripe.
	 *
	 * @param WP_Post $product The product being bought.
	 * @param Payment $payment The pending row.
	 * @return array{redirect: string}|WP_Error
	 */
	private function to_stripe( WP_Post $product, Payment $payment ): array|WP_Error {
		$user    = get_userdata( $payment->user_id );
		$session = $this->gateway->create_checkout_session(
			$payment,
			$product->post_title,
			$this->return_url( $payment ),
			(string) get_permalink( $product ),
			false === $user ? '' : $user->user_email
		);

		if ( $session instanceof WP_Error ) {
			$this->store->mark_failed( $payment->uuid );
			$this->release_hold( $payment );
			$this->log_failure( $payment, $session );

			return $session;
		}

		$this->store->attach_session( $payment->uuid, $session['id'] );

		return array( 'redirect' => $session['url'] );
	}

	/**
	 * The gateway's own reason, to the debug log and to whoever hooked on.
	 *
	 * @param Payment  $payment The row that failed.
	 * @param WP_Error $error   What the gateway said.
	 */
	private function log_failure( Payment $payment, WP_Error $error ): void {
		// wp-config need not define it, so ask before reading it.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug only, and the buyer is told nothing but the generic wording.
			error_log( sprintf( 'Gated Media Access: checkout %s could not be started: %s', $payment->uuid, $error->get_error_message() ) );
		}

		/**
		 * Fires when a checkout could not be started at all.
		 *
		 * @param int      $payment_id The failed payment's row id.
		 * @param WP_Error $error      What the gateway said.
		 */
		do_action( 'gatedmedia_checkout_failed', $payment->payment_id, $error );
	}

	/**
	 * Reserves the coupon for this payment, both limits it carries.
	 *
	 * @param WP_Post $coupon  The coupon being spent.
	 * @param Payment $payment The pending row the hold is named after.
	 */
	private function reserve( WP_Post $coupon, Payment $payment ): bool {
		if ( ! $this->holds->take( $coupon->ID, $payment->uuid, $this->coupons->room( $coupon ) ) ) {
			return false;
		}

		return $this->holds->take_for_user(
			$coupon->ID,
			$payment->user_id,
			$payment->uuid,
			$this->coupons->room_for_user( $coupon, $payment->user_id )
		);
	}

	/**
	 * Gives back whatever this payment reserved — once it has completed, once
	 * it has failed, or once Stripe says its session expired. A payment with
	 * no coupon reserved nothing and this does nothing.
	 *
	 * @param Payment $payment The payment that is no longer in flight.
	 */
	public function release_hold( Payment $payment ): void {
		$this->holds->release( $payment->coupon_id, $payment->user_id, $payment->uuid );
	}

	/**
	 * One Access record per snapshot row — how payment-backed access lands,
	 * on completion. The snapshot, not the product's live items: groups are
	 * live and the contents at purchase are not recoverable later (spec §3).
	 * Duration still reads from the product — a duration is a promise about
	 * time, not contents.
	 *
	 * Every item that can be granted is, and the failures come back together:
	 * a snapshot item deleted since purchase must not silently cost the buyer
	 * the rest of what they paid for.
	 *
	 * @param Payment $payment The completed payment.
	 * @return WP_Error|null Null when every item landed.
	 */
	public function grant_snapshot( Payment $payment ): ?WP_Error {
		return $this->grant_each(
			$payment->contents_snapshot,
			$payment->user_id,
			$this->duration_days( $payment->product_id ),
			self::SOURCE_STRIPE,
			$payment->uuid
		);
	}

	/**
	 * The product's duration as the writer wants it: null for lifetime, or
	 * the day count. `Product_Meta::sanitize_duration()` normalises on write
	 * and the key defaults to lifetime, so nothing falsey reaches here.
	 *
	 * @param int $product_id The product being granted from.
	 */
	private function duration_days( int $product_id ): ?int {
		$duration = (string) get_post_meta( $product_id, Product_Meta::META_DURATION, true );

		return Product_Meta::DURATION_LIFETIME === $duration ? null : (int) $duration;
	}

	/**
	 * One Access record per product item, all the same source and
	 * reference — the writer's guard covers each item separately.
	 *
	 * @param WP_Post $product   The product whose items grant.
	 * @param int     $user_id   Who receives.
	 * @param string  $source    stripe or free.
	 * @param string  $reference The payment uuid, or the free claim's key.
	 * @return WP_Error|null Null when every item landed.
	 */
	public function grant_items( WP_Post $product, int $user_id, string $source, string $reference ): ?WP_Error {
		return $this->grant_each(
			array_map( 'strval', (array) get_post_meta( $product->ID, Product_Meta::META_ITEMS, false ) ),
			$user_id,
			$this->duration_days( $product->ID ),
			$source,
			$reference
		);
	}

	/**
	 * The grant loop both paths share: every `type:id` row is attempted, and
	 * whatever the writer refused comes back as one error carrying each
	 * refusal. Discarding these is how a buyer paid and received nothing.
	 *
	 * @param array<int, string> $items     The `type:id` rows.
	 * @param int                $user_id   Who receives.
	 * @param int|null           $days      Duration, null for lifetime.
	 * @param string             $source    stripe or free.
	 * @param string             $reference The payment uuid, or the free claim's key.
	 * @return WP_Error|null Null when every item landed.
	 */
	private function grant_each( array $items, int $user_id, ?int $days, string $source, string $reference ): ?WP_Error {
		$failed = new WP_Error();

		foreach ( $items as $item ) {
			list( $type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

			if ( '' === $identifier ) {
				continue;
			}

			$granted = $this->writer->grant( $user_id, $type, $identifier, $days, $source, $reference );

			if ( $granted instanceof WP_Error ) {
				$failed->add( $granted->get_error_code(), sprintf( '%s: %s', $item, $granted->get_error_message() ) );
			}
		}

		return $failed->has_errors() ? $failed : null;
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
