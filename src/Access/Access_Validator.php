<?php
/**
 * Validation of a grant's four facts.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use WP_Error;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Checks that the four facts every route hands the writer point at real things: who, what, how long, and where from.
 *
 * Split from `Access_Writer` for size rather than reuse. The writer is its only caller.
 */
class Access_Validator {

	/** What an access record may point at. */
	private const ITEM_TYPES = array( 'file', 'post', 'group' );

	/**
	 * Group targets resolve through the taxonomy's UUID identity.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID into its term.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The four facts, checked whole.
	 *
	 * @param int      $user_id       Who holds it.
	 * @param string   $item_type     One of file, post, group.
	 * @param string   $item_id       The target's identifier.
	 * @param int|null $duration_days Days, or null for lifetime.
	 * @param string   $source        The system it came from.
	 * @return WP_Error|null Null when everything checks out.
	 */
	public function validate( int $user_id, string $item_type, string $item_id, ?int $duration_days, string $source ): ?WP_Error {
		if ( false === get_userdata( $user_id ) ) {
			return new WP_Error( 'gatedmedia_invalid_user', 'No such user to hold the access.' );
		}

		if ( ! in_array( $item_type, self::ITEM_TYPES, true ) ) {
			return new WP_Error( 'gatedmedia_invalid_item_type', 'Item type must be file, post or group.' );
		}

		if ( null !== $duration_days && $duration_days < 1 ) {
			return new WP_Error( 'gatedmedia_invalid_duration', 'Duration is days from now, or null for lifetime.' );
		}

		if ( '' === trim( $source ) ) {
			return new WP_Error( 'gatedmedia_invalid_source', 'Every record names the system it came from.' );
		}

		return $this->validate_target( $item_type, $item_id );
	}

	/**
	 * The target must exist: an attachment, a non-attachment post, or a group.
	 *
	 * @param string $item_type One of file, post, group.
	 * @param string $item_id   The target's identifier.
	 * @return WP_Error|null Null when the target resolves.
	 */
	private function validate_target( string $item_type, string $item_id ): ?WP_Error {
		if ( 'group' === $item_type ) {
			$exists = null !== $this->taxonomy->find_group( $item_id );
		} else {
			$target        = get_post( (int) $item_id );
			$is_attachment = null !== $target && 'attachment' === $target->post_type;
			$exists        = 'file' === $item_type ? $is_attachment : ( null !== $target && ! $is_attachment );
		}

		if ( ! $exists ) {
			return new WP_Error( 'gatedmedia_invalid_item', sprintf( 'No %s found for "%s".', $item_type, $item_id ) );
		}

		return null;
	}
}
