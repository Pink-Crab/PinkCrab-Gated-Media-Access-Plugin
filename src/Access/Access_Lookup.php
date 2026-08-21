<?php
/**
 * Read-side queries over access records.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * The grant path's two lookups: has this source and reference been seen
 * (the retry guard), and which live records could a re-grant stack onto.
 * Reads only — writing stays the writer's, which is this class's only
 * caller today.
 */
class Access_Lookup {

	/**
	 * An already-recorded source and reference, if we hold one — narrowed to
	 * one item when the caller names it, since a product purchase writes one
	 * record per item all carrying the same source and reference (spec §4).
	 *
	 * @param string $source    The system.
	 * @param string $reference That system's reference.
	 * @param string $item_type The record's item type, '' for any.
	 * @param string $item_id   The record's item, '' for any.
	 * @return int|null The existing record's ID, or null.
	 */
	public function find_by_reference( string $source, string $reference, string $item_type = '', string $item_id = '' ): ?int {
		$clauses = array(
			array(
				'key'   => Access_Writer::META_SOURCE,
				'value' => $source,
			),
			array(
				'key'   => Access_Writer::META_REFERENCE,
				'value' => $reference,
			),
		);

		if ( '' !== $item_type && '' !== $item_id ) {
			$clauses[] = array(
				'key'   => Access_Writer::META_ITEM_TYPE,
				'value' => $item_type,
			);
			$clauses[] = array(
				'key'   => Access_Writer::META_ITEM_ID,
				'value' => $item_id,
			);
		}

		$found = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				// Never 'any': it excludes statuses registered with
				// exclude_from_search, which is all three of ours — the guard
				// would find nothing and every retry would grant again.
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The write path is rare; retry safety needs the pair.
				'meta_query'     => $clauses,
			)
		);

		return array() === $found ? null : (int) $found[0];
	}

	/**
	 * Every record a source and reference created — what a refund revokes:
	 * a product purchase writes one per item, and the refund takes them all.
	 *
	 * @param string $source    The system.
	 * @param string $reference That system's reference.
	 * @return array<int, int> The record IDs.
	 */
	public function records_for_reference( string $source, string $reference ): array {
		$found = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Refunds are rare; the pair is the whole condition.
				'meta_query'     => array(
					array(
						'key'   => Access_Writer::META_SOURCE,
						'value' => $source,
					),
					array(
						'key'   => Access_Writer::META_REFERENCE,
						'value' => $reference,
					),
				),
			)
		);

		return array_map( 'intval', $found );
	}

	/**
	 * The user's active records for one item.
	 *
	 * @param int    $user_id   Who holds them.
	 * @param string $item_type One of file, post, group.
	 * @param string $item_id   The target's identifier.
	 * @return array<int, int>
	 */
	public function records_for_item( int $user_id, string $item_type, string $item_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The write path is rare; stacking needs the item pair.
				'meta_query'     => array(
					array(
						'key'   => Access_Writer::META_ITEM_TYPE,
						'value' => $item_type,
					),
					array(
						'key'   => Access_Writer::META_ITEM_ID,
						'value' => $item_id,
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Access this person once had for an item and no longer does.
	 *
	 * The sibling of `records_for_item()`, looking the other way: expired and
	 * revoked rather than active. §7.6's lapsed state is the whole reason —
	 * a product page says "your access to this ended" rather than offering it
	 * as though it had never been bought.
	 *
	 * @param int    $user_id   Whose access.
	 * @param string $item_type group, post or file.
	 * @param string $item_id   The item's identifier.
	 * @return array<int, int>
	 */
	public function past_records_for_item( int $user_id, string $item_type, string $item_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One product page view; the item pair is the whole condition.
				'meta_query'     => array(
					array(
						'key'   => Access_Writer::META_ITEM_TYPE,
						'value' => $item_type,
					),
					array(
						'key'   => Access_Writer::META_ITEM_ID,
						'value' => $item_id,
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}
}
