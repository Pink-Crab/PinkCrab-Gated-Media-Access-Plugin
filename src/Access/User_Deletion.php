<?php
/**
 * Access leaves with the person who held it.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Removes a deleted user's access records before core can do anything with them.
 *
 * Access records name their holder in `post_author`, which makes them ordinary authored content to core.
 *
 * `wp_delete_user()` with a reassign target rewrites `post_author` on every post the person owns with no post type filter, so the chosen account would inherit their access, paid records included, and without a target the records are left behind, still active against a user id that no longer exists.
 *
 * Access is personal and is not inheritable, so both cases end the same way, and `delete_user` is the hook because it fires before either branch runs.
 *
 * Every removal goes through `Access_Writer::delete()`.
 */
class User_Deletion implements Hookable {

	/**
	 * Reads with the lookup, writes only through the writer.
	 *
	 * @param Access_Writer $writer The one writer of access records.
	 * @param Access_Lookup $lookup Finds what the person holds.
	 */
	public function __construct( private Access_Writer $writer, private Access_Lookup $lookup ) {
	}

	/**
	 * Listens for a user being deleted.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'delete_user', array( $this, 'remove' ) );
	}

	/**
	 * Takes every record this person holds.
	 *
	 * @param int $user_id The user being deleted.
	 * @return int How many records were removed.
	 */
	public function remove( int $user_id ): int {
		$removed = 0;

		foreach ( $this->lookup->records_for_user( $user_id ) as $access_id ) {
			if ( $this->writer->delete( $access_id ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}
