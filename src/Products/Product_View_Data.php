<?php
/**
 * What the product page draws.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * Turns a product into the shape §7.6 draws — its contents, its price, and
 * which of the six states the person looking at it is in.
 *
 * The render file cannot reach container services, so it arrives by filter —
 * `gatedmedia_product_data` — as My Access, Files and Orders do.
 *
 * **It reads and decides nothing that matters.** The state chooses what to
 * show; `Checkout` decides whether a purchase may actually happen, and it is
 * asked again on submit. A page that offered a button it should not have still
 * could not buy anything.
 */
class Product_View_Data implements Hookable {

	/** Nobody is signed in: the page invites them to create an account. */
	public const STATE_SIGNED_OUT = 'signed_out';

	/** They already hold everything this product grants. */
	public const STATE_HELD = 'held';

	/** They held it and it ran out. */
	public const STATE_LAPSED = 'lapsed';

	/** An allow-list exists and their address is not on it. */
	public const STATE_INELIGIBLE = 'ineligible';

	/** Free to claim. */
	public const STATE_FREE = 'free';

	/** For sale. */
	public const STATE_PAID = 'paid';

	/**
	 * Reads, never writes.
	 *
	 * @param Checkout      $checkout The purchase flow, asked only about eligibility.
	 * @param Resolver      $resolver What this person can already see.
	 * @param Access_Lookup $lookup   Access they used to have.
	 * @param Item_Label    $labels   Names the items a product grants.
	 */
	public function __construct(
		private Checkout $checkout,
		private Resolver $resolver,
		private Access_Lookup $lookup,
		private Item_Label $labels,
	) {
	}

	/**
	 * Supplies the view.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_product_data', array( $this, 'product' ), 2 );
	}

	/**
	 * Everything §7.6 needs about one product.
	 *
	 * @param array<string, mixed> $data       The view's defaults.
	 * @param int                  $product_id The product being drawn.
	 * @return array<string, mixed>
	 */
	public function product( array $data, int $product_id = 0 ): array {
		$product = get_post( $product_id );

		if ( ! $product instanceof WP_Post || Post_Types::PRODUCT !== $product->post_type ) {
			return $data;
		}

		$user_id = get_current_user_id();
		$items   = $this->items( $product_id );
		$price   = (int) get_post_meta( $product_id, Product_Meta::META_PRICE, true );

		$currency = (string) get_post_meta( $product_id, Product_Meta::META_CURRENCY, true );
		$duration = (string) get_post_meta( $product_id, Product_Meta::META_DURATION, true );

		$data['product_id'] = $product_id;
		$data['state']      = $this->state( $product, $user_id, $items, $price );
		$data['items']      = $this->contents( $items );
		$data['price']      = $price;
		$data['currency']   = '' === $currency ? 'GBP' : $currency;
		$data['term']       = $this->term( $duration );
		$data['nonce']      = wp_create_nonce( Checkout_Action::ACTION );
		$data['action_url'] = admin_url( 'admin-post.php' );
		$data['error']      = $this->error();

		return $data;
	}

	/**
	 * Which of the six §7.6 states this person is in.
	 *
	 * Ordered by what matters most to say: that you already have it beats
	 * every other message, and that you cannot buy it beats its price.
	 *
	 * @param WP_Post            $product The product.
	 * @param int                $user_id Who is looking, 0 signed out.
	 * @param array<int, string> $items   Its `type:identifier` entries.
	 * @param int                $price   Minor units.
	 */
	private function state( WP_Post $product, int $user_id, array $items, int $price ): string {
		if ( 0 === $user_id ) {
			return self::STATE_SIGNED_OUT;
		}

		if ( array() !== $items && $this->holds_everything( $user_id, $items ) ) {
			return self::STATE_HELD;
		}

		if ( $this->held_before( $user_id, $items ) ) {
			return self::STATE_LAPSED;
		}

		if ( ! $this->checkout->eligible( $product, $user_id ) ) {
			return self::STATE_INELIGIBLE;
		}

		return 0 === $price ? self::STATE_FREE : self::STATE_PAID;
	}

	/**
	 * Whether every item this product grants is already theirs.
	 *
	 * Every one, not any: a product bundling four things is not "held" because
	 * one of them arrived another way.
	 *
	 * @param int                $user_id Who is looking.
	 * @param array<int, string> $items   Its `type:identifier` entries.
	 */
	private function holds_everything( int $user_id, array $items ): bool {
		foreach ( $items as $entry ) {
			list( $type, $identifier ) = Item_Label::split( $entry );

			if ( '' === $type || ! $this->resolver->can_see( $user_id, $type, $identifier ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether any of it is access they used to have.
	 *
	 * Any, not every — one lapsed item is enough for the page to say the
	 * access ended rather than presenting it as a first purchase.
	 *
	 * @param int                $user_id Who is looking.
	 * @param array<int, string> $items   Its `type:identifier` entries.
	 */
	private function held_before( int $user_id, array $items ): bool {
		foreach ( $items as $entry ) {
			list( $type, $identifier ) = Item_Label::split( $entry );

			if ( '' === $type ) {
				continue;
			}

			if ( array() !== $this->lookup->past_records_for_item( $user_id, $type, $identifier ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The product's items, as stored.
	 *
	 * @param int $product_id The product.
	 * @return array<int, string>
	 */
	private function items( int $product_id ): array {
		return array_map( 'strval', (array) get_post_meta( $product_id, Product_Meta::META_ITEMS, false ) );
	}

	/**
	 * Those items as the contents block's rows.
	 *
	 * @param array<int, string> $items The `type:identifier` entries.
	 * @return array<int, array<string, string>>
	 */
	private function contents( array $items ): array {
		$rows = array();

		foreach ( $items as $entry ) {
			list( $type, $identifier ) = Item_Label::split( $entry );

			$text = $this->labels->text( $type, $identifier );

			if ( '' === $text ) {
				continue;
			}

			$rows[] = array(
				'text' => $text,
				'icon' => $this->labels->icon( $type ),
			);
		}

		return $rows;
	}

	/**
	 * How long the access lasts, in words.
	 *
	 * @param string $duration The stored days, '' for lifetime.
	 */
	private function term( string $duration ): string {
		if ( '' === $duration || 0 === (int) $duration ) {
			return __( 'Lifetime access', 'gated-media-access' );
		}

		$days = (int) $duration;

		if ( 0 === $days % 365 ) {
			$years = intdiv( $days, 365 );

			/* translators: %d: number of years. */
			return sprintf( _n( 'Access for %d year', 'Access for %d years', $years, 'gated-media-access' ), $years );
		}

		if ( 0 === $days % 30 ) {
			$months = intdiv( $days, 30 );

			/* translators: %d: number of months. */
			return sprintf( _n( 'Access for %d month', 'Access for %d months', $months, 'gated-media-access' ), $months );
		}

		/* translators: %d: number of days. */
		return sprintf( _n( 'Access for %d day', 'Access for %d days', $days, 'gated-media-access' ), $days );
	}

	/**
	 * The message for a checkout that came back refused.
	 *
	 * `Checkout_Action` redirects here carrying the error's code rather than
	 * its sentence, so the wording is chosen here. An unrecognised code still
	 * says something — a silent failed purchase is the worst of the options.
	 */
	private function error(): string {
		// A flag chosen from a fixed list of wordings; nothing is written and
		// no form is processed, so there is nothing for a nonce to protect.
		$raw = $_GET[ Checkout_Action::ERROR_FLAG ] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; see above.

		$code = sanitize_key( wp_unslash( (string) $raw ) );

		if ( '' === $code ) {
			return '';
		}

		$messages = array(
			'gatedmedia_no_product'   => __( 'That product is not for sale.', 'gated-media-access' ),
			'gatedmedia_not_eligible' => __( 'This product is not available to you.', 'gated-media-access' ),
			'gatedmedia_bad_coupon'   => __( 'That coupon cannot be used.', 'gated-media-access' ),
			'gatedmedia_payment_row'  => __( 'The payment could not be started. Nothing has been charged.', 'gated-media-access' ),
		);

		return $messages[ $code ] ?? __( 'We could not start that purchase. Nothing has been charged.', 'gated-media-access' );
	}
}
