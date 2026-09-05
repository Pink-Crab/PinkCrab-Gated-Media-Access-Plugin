<?php
/**
 * What is inside one group a person holds.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * Answers the `my-access` block when it is drawing one group rather than the
 * list — `/account/my-access/{group-uuid}`.
 *
 * A different question from the one `Held_Access` answers. That asks what a
 * person holds; this asks what is inside one of the things they hold, which is
 * a taxonomy query and not an access record at all. They share a filter
 * because they draw the same block, and nothing else.
 *
 * **Holding the group is the whole permission.** The contents are only listed
 * once the access records say this person holds it, so the page cannot be used
 * to read a group nobody gave them. A group they do not hold and a uuid that
 * never existed answer the same nothing.
 */
class Group_Contents implements Hookable {

	/**
	 * Reads, never writes.
	 *
	 * @param Resolver        $resolver Whether they hold the group.
	 * @param Access_Taxonomy $taxonomy The group's identity and its contents.
	 */
	public function __construct(
		private Resolver $resolver,
		private Access_Taxonomy $taxonomy,
	) {
	}

	/**
	 * Fills in the detail the block asks for, after the list has been built.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_my_access_data', array( $this, 'detail' ), 2, 20 );
	}

	/**
	 * One held group, opened.
	 *
	 * Groups are live (architecture.md §1) — an administrator moves things in
	 * and out — so this is what the group holds now, not what it held when
	 * access was granted.
	 *
	 * @param array<string, mixed> $data  The view's data so far.
	 * @param string               $group The second URL segment, or ''.
	 * @return array<string, mixed>
	 */
	public function detail( array $data, string $group = '' ): array {
		if ( '' === $group ) {
			return $data;
		}

		$data['section_url'] = Account_Url::section( 'my-access' );
		$data['detail']      = $this->contents( $group, get_current_user_id() );

		return $data;
	}

	/**
	 * The group's name and what is in it, for its holder only.
	 *
	 * @param string $uuid    The group being opened.
	 * @param int    $user_id Who is asking.
	 * @return array<string, mixed>|null
	 */
	private function contents( string $uuid, int $user_id ): ?array {
		if ( $user_id < 1 || ! $this->resolver->allowed_for( $user_id )->has_group( $uuid ) ) {
			return null;
		}

		$term = $this->taxonomy->find_group( $uuid );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		return array(
			'uuid'  => $uuid,
			'title' => $term->name,
			'items' => $this->items( $uuid ),
		);
	}

	/**
	 * Each thing in the group as a row.
	 *
	 * @param string $uuid The group.
	 * @return array<int, array<string, string>>
	 */
	private function items( string $uuid ): array {
		$items    = array();
		$contents = $this->taxonomy->contents( $uuid );

		// contents() answers ids, so each row below would be its own query.
		_prime_post_caches( $contents, false, false );

		foreach ( $contents as $object_id ) {
			$title = (string) get_the_title( $object_id );

			if ( '' === $title ) {
				continue;
			}

			$items[] = array(
				'title' => $title,
				'meta'  => 'attachment' === get_post_type( $object_id )
					? __( 'File', 'gated-media-access' )
					: __( 'Post', 'gated-media-access' ),
				'href'  => (string) get_permalink( $object_id ),
			);
		}

		return $items;
	}
}
