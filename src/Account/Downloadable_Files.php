<?php
/**
 * Which files a person can download.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Access_Row;

/**
 * Answers the `files` block — §7.2.
 *
 * `available` is everything reachable now, the contents of held groups
 * included. `past` is what has run out and is kept as history. `downloading`
 * is a state the browser owns and passes through untouched.
 *
 * **A file can run out one way and still be reachable another.** A direct
 * grant expiring does not put a file in the past if a live group still holds
 * it, which is why `past` is filtered against what is currently allowed rather
 * than simply listing expired records.
 */
class Downloadable_Files implements Hookable {

	/**
	 * Reads, never writes.
	 *
	 * @param Resolver        $resolver What this person is allowed to see.
	 * @param Access_Taxonomy $taxonomy Group contents, for a lapsed group's files.
	 * @param Access_Row      $rows     One file as a row.
	 */
	public function __construct(
		private Resolver $resolver,
		private Access_Taxonomy $taxonomy,
		private Access_Row $rows,
	) {
	}

	/**
	 * Answers the block.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_files_data', array( $this, 'files' ) );
	}

	/**
	 * Everything downloadable, and everything that used to be.
	 *
	 * @param array<string, mixed> $data The view's defaults.
	 * @return array<string, mixed>
	 */
	public function files( array $data ): array {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $data;
		}

		$allowed = $this->resolver->allowed_for( $user_id );

		foreach ( $allowed->files() as $file_id => $expires_at ) {
			$item = $this->rows->file( $file_id, $expires_at );

			if ( null !== $item ) {
				$data['available'][] = $item;
			}
		}

		foreach ( $this->expired_file_ids( $user_id ) as $file_id ) {
			// Still reachable another way — a live group, a fresh grant — is
			// not past.
			if ( $allowed->has_file( $file_id ) ) {
				continue;
			}

			$item = $this->rows->file( $file_id, null );

			if ( null === $item ) {
				continue;
			}

			// History carries no expiry chip: it has already gone.
			unset( $item['expiry_state'], $item['expiry_label'] );

			$data['past'][] = $item;
		}

		return $data;
	}

	/**
	 * Files whose access has run out: direct records past their date, and the
	 * contents of groups past theirs.
	 *
	 * Both statuses, because history outlives the sweep: it moves records past
	 * their date to expired, and `Access_Writer::set_expiry()` writes that
	 * status straight away when an admin backdates one. Revoked is left out —
	 * that is a withdrawal, not something that ran out.
	 *
	 * @param int $user_id Whose history.
	 * @return array<int, int>
	 */
	private function expired_file_ids( int $user_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$file_ids = array();

		foreach ( array_map( 'intval', $ids ) as $access_id ) {
			$expires = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

			if ( '' === $expires || (int) strtotime( $expires . ' +0000' ) > time() ) {
				continue;
			}

			$item_type = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true );
			$item_id   = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true );

			if ( 'file' === $item_type ) {
				$file_ids[] = (int) $item_id;
			} elseif ( 'group' === $item_type ) {
				$file_ids = array_merge( $file_ids, $this->group_file_ids( $item_id ) );
			}
		}

		return array_values( array_unique( $file_ids ) );
	}

	/**
	 * The attachments a group contains right now.
	 *
	 * @param string $uuid The group.
	 * @return array<int, int>
	 */
	private function group_file_ids( string $uuid ): array {
		$term = $this->taxonomy->find_group( $uuid );

		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$object_ids = get_objects_in_term( $term->term_id, Access_Taxonomy::TAXONOMY );

		if ( ! is_array( $object_ids ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'intval', $object_ids ),
				static fn ( int $object_id ): bool => 'attachment' === get_post_type( $object_id )
			)
		);
	}
}
