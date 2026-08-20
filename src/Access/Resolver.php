<?php
/**
 * The one place access is evaluated.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Answers the brief's two questions — can this user see this thing right now,
 * and what can this user see right now — from one per-user set of allowed
 * items. Nothing else evaluates expiry, revocation or group membership
 * (architecture.md §4).
 *
 * A shared service others call; its only hooks keep the memo honest. The file
 * boundary (round 3) attaches at plugin load and resolves this lazily.
 *
 * The allowed items are memoised per instance, and instances are shared
 * through the container, so a page asking once per image size pays for one
 * build. A grant or revocation forgets that holder's items, so a write is
 * visible to the rest of its own request. (A stacked expiry extension is not
 * — the writer fires nothing there, and the held item stays held either way.)
 * Expiry is compared against now at read time — no sweep has to have run.
 */
class Resolver implements Hookable {

	/**
	 * One built set of allowed items per user, for this request.
	 *
	 * @var array<int, Allowed_Items>
	 */
	private array $allowed = array();

	/**
	 * Groups resolve through the taxonomy's UUID identity.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID into its term.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * Watches the writer, so a write never leaves a stale answer behind.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'gatedmedia_access_granted', array( $this, 'forget_holder' ), 2 );
		$loader->action( 'gatedmedia_access_revoked', array( $this, 'forget_holder' ), 2 );
	}

	/**
	 * Drops one holder's memoised items; the next ask rebuilds them.
	 *
	 * @param int $access_id The record written (unused; both actions lead with it).
	 * @param int $user_id   Whose items are now out of date.
	 */
	public function forget_holder( int $access_id, int $user_id ): void {
		unset( $this->allowed[ $user_id ] );
	}

	/**
	 * What this user can see right now.
	 *
	 * @param int $user_id The user asking, 0 for signed out.
	 */
	public function allowed_for( int $user_id ): Allowed_Items {
		if ( ! isset( $this->allowed[ $user_id ] ) ) {
			$this->allowed[ $user_id ] = $this->build( $user_id );
		}

		return $this->allowed[ $user_id ];
	}

	/**
	 * Can this user see this thing right now?
	 *
	 * A membership test against the allowed items — no query after the first ask.
	 *
	 * @param int    $user_id   The user asking.
	 * @param string $item_type One of file, post, group.
	 * @param string $item_id   Attachment ID, post ID, or group UUID.
	 */
	public function can_see( int $user_id, string $item_type, string $item_id ): bool {
		$items = $this->allowed_for( $user_id );

		$allowed = match ( $item_type ) {
			'file'  => $items->has_file( (int) $item_id ),
			'post'  => $items->has_post( (int) $item_id ),
			'group' => $items->has_group( $item_id ),
			default => false,
		};

		/**
		 * The last word on an access decision, deliberately (architecture.md §4).
		 *
		 * @param bool   $allowed   What the records say.
		 * @param int    $user_id   The user asking.
		 * @param string $item_type One of file, post, group.
		 * @param string $item_id   The target's identifier.
		 */
		return (bool) apply_filters( 'gatedmedia_user_can_access', $allowed, $user_id, $item_type, $item_id );
	}

	/**
	 * Builds the allowed items: live direct records, expanded through groups.
	 *
	 * @param int $user_id Whose access.
	 */
	private function build( int $user_id ): Allowed_Items {
		$records = array();
		$files   = array();
		$posts   = array();
		$groups  = array();

		foreach ( $this->live_records( $user_id ) as $record ) {
			$records[] = $record;

			if ( 'file' === $record['item_type'] ) {
				$files = $this->merge( $files, (int) $record['item_id'], $record['expires_at'] );
			} elseif ( 'post' === $record['item_type'] ) {
				$posts = $this->merge( $posts, (int) $record['item_id'], $record['expires_at'] );
			} elseif ( 'group' === $record['item_type'] ) {
				// array_key_exists, not ?? — a held lifetime group stores null,
				// and ?? would read that as absent and downgrade it to dated.
				$current = array_key_exists( $record['item_id'], $groups ) ? $groups[ $record['item_id'] ] : PHP_INT_MIN;

				$groups[ $record['item_id'] ] = $this->most_generous( $current, $record['expires_at'] );
			}
		}

		foreach ( $groups as $uuid => $expires_at ) {
			foreach ( $this->group_contents( (string) $uuid ) as $object_id => $is_file ) {
				if ( $is_file ) {
					$files = $this->merge( $files, $object_id, $expires_at );
				} else {
					$posts = $this->merge( $posts, $object_id, $expires_at );
				}
			}
		}

		return new Allowed_Items( $records, $files, $posts, $groups );
	}

	/**
	 * The user's active records, expiry already applied against now.
	 *
	 * One query on the `type_status_author` index, one meta prime.
	 *
	 * @param int $user_id Whose records.
	 * @return array<int, array{access_id: int, item_type: string, item_id: string, expires_at: int|null}>
	 */
	private function live_records( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		update_postmeta_cache( array_map( 'intval', $ids ) );

		$records = array();

		foreach ( $ids as $access_id ) {
			$expires    = (string) get_post_meta( (int) $access_id, Access_Writer::META_EXPIRES_AT, true );
			$expires_at = '' === $expires ? null : (int) strtotime( $expires . ' +0000' );

			// Expiry is a date, not a status — a record is expired the moment
			// it is expired, swept or not.
			if ( null !== $expires_at && $expires_at <= time() ) {
				continue;
			}

			$records[] = array(
				'access_id'  => (int) $access_id,
				'item_type'  => (string) get_post_meta( (int) $access_id, Access_Writer::META_ITEM_TYPE, true ),
				'item_id'    => (string) get_post_meta( (int) $access_id, Access_Writer::META_ITEM_ID, true ),
				'expires_at' => $expires_at,
			);
		}

		return $records;
	}

	/**
	 * What a group contains right now — live, per the brief: everyone holding
	 * the group sees whatever is in it at the moment they ask.
	 *
	 * @param string $uuid The group.
	 * @return array<int, bool> Object ID → true when it is an attachment.
	 */
	private function group_contents( string $uuid ): array {
		$term = $this->taxonomy->find_group( $uuid );

		if ( null === $term ) {
			return array();
		}

		$object_ids = get_objects_in_term( $term->term_id, Access_Taxonomy::TAXONOMY );

		if ( ! is_array( $object_ids ) ) {
			return array();
		}

		$contents = array();

		foreach ( array_map( 'intval', $object_ids ) as $object_id ) {
			$object = get_post( $object_id );

			if ( null === $object ) {
				continue;
			}

			// Attachments live at inherit; anything else must be published —
			// a draft in a group is not viewable content for anyone.
			$is_file = 'attachment' === $object->post_type;

			if ( ( $is_file && 'inherit' === $object->post_status ) || ( ! $is_file && 'publish' === $object->post_status ) ) {
				$contents[ $object_id ] = $is_file;
			}
		}

		return $contents;
	}

	/**
	 * Folds one sighting of an item into a map, keeping the generous expiry.
	 *
	 * @param array<int, int|null> $map        The map so far.
	 * @param int                  $item_id    The item seen.
	 * @param int|null             $expires_at When this sighting expires.
	 * @return array<int, int|null>
	 */
	private function merge( array $map, int $item_id, ?int $expires_at ): array {
		$map[ $item_id ] = $this->most_generous(
			array_key_exists( $item_id, $map ) ? $map[ $item_id ] : PHP_INT_MIN,
			$expires_at
		);

		return $map;
	}

	/**
	 * Lifetime (null) beats any date; otherwise the later date wins.
	 *
	 * PHP_INT_MIN stands for "no expiry seen yet".
	 *
	 * @param int|null $current   What the map holds.
	 * @param int|null $candidate The new sighting.
	 */
	private function most_generous( ?int $current, ?int $candidate ): ?int {
		if ( null === $current || null === $candidate ) {
			return null;
		}

		return max( $current, $candidate );
	}
}
