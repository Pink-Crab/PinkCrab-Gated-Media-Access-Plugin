<?php
/**
 * Minting unguessable identity.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * One minter for everything that carries a UUID — groups on term meta,
 * products on post meta — under the one key. Identity is settled the first
 * time anything asks: whoever calls first mints, and every later call
 * reads the same answer.
 */
class Uuid {

	/** The meta key an object's UUID lives under, whatever its type. */
	public const META = 'gatedmedia_uuid';

	/**
	 * The object's UUID, minted now if it has none.
	 *
	 * @param string $meta_type Which meta table — post or term.
	 * @param int    $object_id The object.
	 */
	public static function ensure( string $meta_type, int $object_id ): string {
		$uuid = (string) get_metadata( $meta_type, $object_id, self::META, true );

		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_metadata( $meta_type, $object_id, self::META, $uuid );
		}

		return $uuid;
	}
}
