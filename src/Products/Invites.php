<?php
/**
 * Allow-list invites.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * When an address joins a published product's allow-list, that person is
 * invited: an existing user gets the product's UUID link — and for a free
 * product, access itself, granted on the spot through the same
 * `Checkout::grant_items()` a claim uses (round 6 decision); an address
 * with no account gets a create-an-account variant instead. Four emails,
 * one per user-by-price combination.
 *
 * Sent dates live in one JSON map on the product, owned and registered
 * here — the block's allow-list rows read it for their status column.
 * Removing an address drops its entry, so removing and re-adding sends a
 * fresh invite (round 6 decision). The per-product switch
 * (`Product_Meta::META_SEND_INVITES`) and the per-type switches in
 * Settings both gate the sending.
 */
class Invites implements Hookable {

	/** The source on invite grants — the access-created mail leaves these to us. */
	public const SOURCE_INVITE = 'invite';

	/** The JSON map of address to sent date (UTC `Y-m-d H:i:s`). */
	public const META_INVITES = 'gatedmedia_invites';

	/**
	 * Grants go through checkout's item loop; mail through the one sender.
	 *
	 * @param Checkout            $checkout Grants a product's items.
	 * @param Notification_Sender $sender   The one sender.
	 */
	public function __construct( private Checkout $checkout, private Notification_Sender $sender ) {
	}

	/**
	 * The map's registration and the save listener — after
	 * `Product_Meta::stamp()` at default priority, so the row meta is settled.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
		$loader->action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'process' ), 1, 20 );
	}

	/**
	 * Declares the one key this class writes. Server-side only — the block
	 * reads it through the editor data, never writes it.
	 */
	public function register_meta(): void {
		register_post_meta(
			Post_Types::PRODUCT,
			self::META_INVITES,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( $value ): string => is_string( $value ) && null !== json_decode( $value ) ? $value : '',
				'auth_callback'     => '__return_false',
			)
		);
	}

	/**
	 * Marks our key protected, like every key the plugin writes.
	 *
	 * @param bool   $is_protected Whether the key is already protected.
	 * @param string $meta_key     The key being asked about.
	 * @param string $meta_type    The object type the key is on.
	 */
	public function protect_meta( bool $is_protected, string $meta_key, string $meta_type ): bool {
		return ( 'post' === $meta_type && self::META_INVITES === $meta_key ) ? true : $is_protected;
	}

	/**
	 * The save listener: keeps the sent map in step with the allow-list,
	 * and invites every address new to it.
	 *
	 * @param int $post_id The product being saved.
	 */
	public function process( int $post_id ): void {
		$product = get_post( $post_id );

		if ( null === $product || false !== wp_is_post_revision( $post_id ) || false !== wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$allowed = array_map( 'strtolower', array_map( 'strval', (array) get_post_meta( $post_id, Product_Meta::META_EMAILS, false ) ) );
		$sent    = $this->sent_map( $post_id );

		// Removed addresses forget their invite — re-adding sends afresh.
		$sent = array_intersect_key( $sent, array_flip( $allowed ) );

		if ( $this->invites_enabled( $post_id ) && 'publish' === $product->post_status ) {
			foreach ( array_diff( $allowed, array_keys( $sent ) ) as $address ) {
				if ( $this->invite( $product, $address ) ) {
					$sent[ $address ] = gmdate( 'Y-m-d H:i:s' );
				}
			}
		}

		update_post_meta( $post_id, self::META_INVITES, (string) wp_json_encode( $sent ) );
	}

	/**
	 * The sent map as stored: address to UTC date.
	 *
	 * @param int $post_id The product.
	 * @return array<string, string>
	 */
	public function sent_map( int $post_id ): array {
		$decoded = json_decode( (string) get_post_meta( $post_id, self::META_INVITES, true ), true );

		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}

	/**
	 * One address's invite: the variant follows who they are and what the
	 * product costs — and an existing user on a free product holds access
	 * before the email is even built.
	 *
	 * @param WP_Post $product The product inviting.
	 * @param string  $address The invited address.
	 * @return bool Whether the email went.
	 */
	private function invite( WP_Post $product, string $address ): bool {
		$user = get_user_by( 'email', $address );
		$free = 0 === (int) get_post_meta( $product->ID, Product_Meta::META_PRICE, true );

		if ( false !== $user && $free ) {
			$this->checkout->grant_items( $product, $user->ID, self::SOURCE_INVITE, "user:{$user->ID}:product:{$product->ID}" );
		}

		$types = array(
			// user? free? => the variant.
			'11' => Notification_Sender::TYPE_INVITE_USER_FREE,
			'10' => Notification_Sender::TYPE_INVITE_USER_PAID,
			'01' => Notification_Sender::TYPE_INVITE_GUEST_FREE,
			'00' => Notification_Sender::TYPE_INVITE_GUEST_PAID,
		);

		return $this->sender->send(
			$types[ ( false !== $user ? '1' : '0' ) . ( $free ? '1' : '0' ) ],
			false !== $user ? $user->ID : 0,
			array(
				'item' => get_the_title( $product ),
				'link' => (string) get_permalink( $product ),
			),
			false !== $user ? '' : $address
		);
	}

	/**
	 * The per-product switch: on unless the block stored '0'.
	 *
	 * @param int $post_id The product.
	 */
	private function invites_enabled( int $post_id ): bool {
		return '0' !== (string) get_post_meta( $post_id, Product_Meta::META_SEND_INVITES, true );
	}
}
