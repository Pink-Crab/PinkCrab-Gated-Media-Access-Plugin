<?php
/**
 * Whether someone may buy a product again.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * The product's buy-again setting against what this person holds and has held, asked by the product page and again by checkout.
 *
 * Held and had-before are about the product's items, not its orders, so access given by an administrator counts the same as access bought.
 */
class Repurchase {

	/**
	 * Reads only.
	 *
	 * @param Resolver      $resolver What a person can see now.
	 * @param Access_Lookup $lookup   What they used to be able to.
	 */
	public function __construct( private Resolver $resolver, private Access_Lookup $lookup ) {
	}

	/**
	 * The product's setting, REPURCHASE_LAPSED unless it says otherwise.
	 *
	 * @param int $product_id The product.
	 */
	public static function mode( int $product_id ): string {
		$mode = (string) get_post_meta( $product_id, Product_Meta::META_REPURCHASE, true );

		return in_array( $mode, array( Product_Meta::REPURCHASE_ALWAYS, Product_Meta::REPURCHASE_NEVER ), true )
			? $mode
			: Product_Meta::REPURCHASE_LAPSED;
	}

	/**
	 * Why this person may not buy it: Product_Offer::STATE_HELD while they hold it, Product_Offer::STATE_ONCE when it is once only and they have had it, '' when they may.
	 *
	 * Signed out is always '', because there is nobody to judge.
	 *
	 * @param int $product_id The product.
	 * @param int $user_id    The would-be buyer.
	 */
	public function refusal( int $product_id, int $user_id ): string {
		$mode = self::mode( $product_id );

		if ( 0 === $user_id || Product_Meta::REPURCHASE_ALWAYS === $mode ) {
			return '';
		}

		$items = $this->items( $product_id );
		$holds = array() !== $items && $this->holds_everything( $user_id, $items );

		if ( Product_Meta::REPURCHASE_NEVER === $mode ) {
			return $holds || $this->held_before( $user_id, $items ) ? Product_Offer::STATE_ONCE : '';
		}

		return $holds ? Product_Offer::STATE_HELD : '';
	}

	/**
	 * Whether every item this product grants is already theirs.
	 *
	 * Every one, not any: a product bundling four things is not "held" because one of them arrived another way.
	 *
	 * @param int                $user_id Who is looking.
	 * @param array<int, string> $items   Its `type:identifier` entries.
	 */
	public function holds_everything( int $user_id, array $items ): bool {
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
	 * Any, not every: one lapsed item is enough for the page to say the access ended rather than presenting it as a first purchase.
	 *
	 * @param int                $user_id Who is looking.
	 * @param array<int, string> $items   Its `type:identifier` entries.
	 */
	public function held_before( int $user_id, array $items ): bool {
		$pairs = array();

		foreach ( $items as $entry ) {
			list( $type, $identifier ) = Item_Label::split( $entry );

			if ( '' !== $type ) {
				$pairs[] = array( $type, $identifier );
			}
		}

		return array() !== $this->lookup->past_records_for_items( $user_id, $pairs );
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
}
