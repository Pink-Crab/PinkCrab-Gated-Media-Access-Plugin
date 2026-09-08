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
 * The grant path's two lookups: has this source and reference been seen, and which live records could a re-grant stack onto.
 *
 * Reads only. Writing stays `Access_Writer`'s.
 */
class Access_Lookup {

	/**
	 * An already-recorded source and reference, narrowed to one item when the caller names it, since a product purchase writes one record per item under the same pair.
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
				// Never 'any': it excludes all three of our statuses, so every retry would grant again.
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
	 * Every record a source and reference created, which is what a refund revokes.
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
	 * Everyone currently holding one item.
	 *
	 * The other way round from `records_for_item()`: that asks about one person, this asks about one thing and answers with the people.
	 *
	 * The holder is the record's author, so the user ids come off the records rather than a meta key of their own.
	 *
	 * Active only, because a screen listing expired or revoked records as holders would be lying.
	 *
	 * @param string $item_type One of file, post, group.
	 * @param string $item_id   The target's identifier.
	 * @return array<int, int> User ids, each once.
	 */
	public function holders_of( string $item_type, string $item_id ): array {
		$records = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One admin screen; the item pair is the whole condition.
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

		$holders = array();

		foreach ( $records as $record ) {
			$holders[] = (int) $record->post_author;
		}

		return array_values( array_unique( $holders ) );
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
	 * The sibling of `records_for_item()`, looking the other way: expired and revoked rather than active. It is what lets the product page say "your access to this ended".
	 *
	 * @param int                                     $user_id Who held them.
	 * @param array<int, array{0: string, 1: string}> $items   Type and identifier pairs.
	 * @return array<int, int>
	 */
	public function past_records_for_items( int $user_id, array $items ): array {
		if ( array() === $items ) {
			return array();
		}

		$types = array();
		$ids   = array();

		foreach ( $items as list( $item_type, $item_id ) ) {
			$types[] = $item_type;
			$ids[]   = $item_id;
		}

		$found = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One query for the whole product; the item pair is the condition.
				'meta_query'     => array(
					array(
						'key'     => Access_Writer::META_ITEM_TYPE,
						'value'   => array_values( array_unique( $types ) ),
						'compare' => 'IN',
					),
					array(
						'key'     => Access_Writer::META_ITEM_ID,
						'value'   => array_values( array_unique( $ids ) ),
						'compare' => 'IN',
					),
				),
			)
		);

		return array_map( 'intval', $found );
	}

	/**
	 * Every record one person holds, whatever state it is in.
	 *
	 * All three statuses, because this answers "what is theirs" rather than "what works". Deleting a user takes their history too.
	 *
	 * @param int $user_id Whose records.
	 * @return array<int, int>
	 */
	public function records_for_user( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $ids );
	}
}
