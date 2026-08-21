<?php
/**
 * The account views' data.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Expiry;

/**
 * Turns the resolver's allowed items into the shapes the view blocks declare.
 *
 * The render files cannot reach container services, so each view's data
 * arrives by filter — `gatedmedia_my_access_data` and `gatedmedia_files_data`
 * — defaulting to empty lists. Third parties get the same surface we use.
 *
 * The item keys are the blocks' contract: `title`, `meta`, `href`, `state`,
 * `action_label`, `expiry_state`, `expiry_label`, with `expiry_state` one of
 * the four values `blocks/expiry/block.json` enumerates.
 *
 * File URLs are plain `wp_get_attachment_url()` — the dependency filters that
 * itself and rewrites a protected file's URL to its hash form
 * (`Attachments.php`, `modify_attachment_url`).
 */
class View_Data implements Hookable {

	/**
	 * Reads, never writes.
	 *
	 * @param Resolver        $resolver The per-user allowed items.
	 * @param Access_Taxonomy $taxonomy Group identity and contents.
	 */
	public function __construct(
		private Resolver $resolver,
		private Access_Taxonomy $taxonomy,
	) {
	}

	/**
	 * Supplies both views.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_my_access_data', array( $this, 'my_access' ) );
		$loader->filter( 'gatedmedia_files_data', array( $this, 'files' ) );
	}

	/**
	 * What the person holds, as granted: groups, posts, files — §7.1.
	 *
	 * @param array{groups: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>} $data The view's defaults.
	 * @return array{groups: array<int, array<string, mixed>>, posts: array<int, array<string, mixed>>, files: array<int, array<string, mixed>>}
	 */
	public function my_access( array $data ): array {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $data;
		}

		foreach ( $this->resolver->allowed_for( $user_id )->records() as $record ) {
			$item = $this->record_item( $record );

			if ( null === $item ) {
				continue;
			}

			if ( 'group' === $record['item_type'] ) {
				$data['groups'][] = $item;
			} elseif ( 'post' === $record['item_type'] ) {
				$data['posts'][] = $item;
			} else {
				$data['files'][] = $item;
			}
		}

		return $data;
	}

	/**
	 * Everything downloadable — §7.2. `available` is the whole reachable set,
	 * group contents included; `past` is what has run out. `downloading` is a
	 * client-side state and passes through untouched.
	 *
	 * @param array{available: array<int, array<string, mixed>>, downloading: array<int, array<string, mixed>>, past: array<int, array<string, mixed>>} $data The view's defaults.
	 * @return array{available: array<int, array<string, mixed>>, downloading: array<int, array<string, mixed>>, past: array<int, array<string, mixed>>}
	 */
	public function files( array $data ): array {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $data;
		}

		$items = $this->resolver->allowed_for( $user_id );

		foreach ( $items->files() as $file_id => $expires_at ) {
			$item = $this->file_item( $file_id, $expires_at );

			if ( null !== $item ) {
				$data['available'][] = $item;
			}
		}

		foreach ( $this->expired_file_ids( $user_id ) as $file_id ) {
			// Still reachable another way — a live group, a fresh grant — is
			// not past.
			if ( $items->has_file( $file_id ) ) {
				continue;
			}

			$item = $this->file_item( $file_id, null );

			if ( null !== $item ) {
				unset( $item['expiry_state'], $item['expiry_label'] );

				$data['past'][] = $item;
			}
		}

		return $data;
	}

	/**
	 * One direct record as a row: a group, a post, or a file.
	 *
	 * @param array{access_id: int, item_type: string, item_id: string, expires_at: int|null} $record The record.
	 * @return array<string, mixed>|null Null when the target no longer resolves.
	 */
	private function record_item( array $record ): ?array {
		if ( 'group' === $record['item_type'] ) {
			return $this->group_item( $record['item_id'], $record['expires_at'] );
		}

		if ( 'post' === $record['item_type'] ) {
			return $this->post_item( (int) $record['item_id'], $record['expires_at'] );
		}

		if ( 'file' === $record['item_type'] ) {
			// §7.1 folds a file's expiry into its meta line.
			$item = $this->file_item( (int) $record['item_id'], $record['expires_at'] );

			if ( null !== $item ) {
				$item['meta'] = implode(
					' · ',
					array_filter(
						array( (string) $item['meta'], (string) $item['expiry_label'] ),
						static fn ( string $part ): bool => '' !== $part
					)
				);
			}

			return $item;
		}

		return null;
	}

	/**
	 * A held group: its name over a count of what it contains right now.
	 *
	 * @param string   $uuid       The group.
	 * @param int|null $expires_at When the access runs out.
	 * @return array<string, mixed>|null
	 */
	private function group_item( string $uuid, ?int $expires_at ): ?array {
		$term = $this->taxonomy->find_group( $uuid );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$expiry = $this->expiry( $expires_at );

		return array(
			'title'        => $term->name,
			/* translators: %d: how many files and posts the group contains. */
			'meta'         => sprintf( _n( '%d item', '%d items', $term->count, 'gated-media-access' ), $term->count ),
			'href'         => '',
			'state'        => 'normal',
			'action_label' => '',
			'expiry_state' => $expiry['state'],
			'expiry_label' => $expiry['label'],
		);
	}

	/**
	 * A held post: its title, linking to it.
	 *
	 * @param int      $post_id    The post.
	 * @param int|null $expires_at When the access runs out.
	 * @return array<string, mixed>|null
	 */
	private function post_item( int $post_id, ?int $expires_at ): ?array {
		$post = get_post( $post_id );

		if ( null === $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$expiry = $this->expiry( $expires_at );

		return array(
			'title'        => get_the_title( $post ),
			'meta'         => '',
			'href'         => (string) get_permalink( $post ),
			'state'        => 'normal',
			'action_label' => __( 'View', 'gated-media-access' ),
			'expiry_state' => $expiry['state'],
			'expiry_label' => $expiry['label'],
		);
	}

	/**
	 * A downloadable file: title over type and size, the protected URL as the
	 * action.
	 *
	 * @param int      $file_id    The attachment.
	 * @param int|null $expires_at When the access runs out.
	 * @return array<string, mixed>|null
	 */
	private function file_item( int $file_id, ?int $expires_at ): ?array {
		$file = get_post( $file_id );

		if ( null === $file || 'attachment' !== $file->post_type ) {
			return null;
		}

		$path   = get_attached_file( $file_id );
		$size   = is_string( $path ) && file_exists( $path ) ? (string) size_format( (int) filesize( $path ) ) : '';
		$mime   = (string) get_post_mime_type( $file );
		$type   = strtoupper( substr( (string) strrchr( $mime, '/' ), 1 ) );
		$title  = '' !== $file->post_title ? $file->post_title : basename( (string) $path );
		$expiry = $this->expiry( $expires_at );

		return array(
			'title'        => $title,
			'meta'         => implode(
				' · ',
				array_filter(
					array( $type, $size ),
					static fn ( string $part ): bool => '' !== $part
				)
			),
			'href'         => (string) wp_get_attachment_url( $file_id ),
			'state'        => 'normal',
			'action_label' => __( 'Download', 'gated-media-access' ),
			'expiry_state' => $expiry['state'],
			'expiry_label' => $expiry['label'],
		);
	}

	/**
	 * Files whose access has run out: direct records past their date, and the
	 * contents of groups past theirs. Kept as history, per the brief.
	 *
	 * @param int $user_id Whose history.
	 * @return array<int, int>
	 */
	private function expired_file_ids( int $user_id ): array {
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

	/**
	 * The expiry, as the expiry block speaks it.
	 *
	 * @param int|null $expires_at UTC timestamp, or null for lifetime.
	 * @return array{state: string, label: string}
	 */
	private function expiry( ?int $expires_at ): array {
		return Expiry::describe( $expires_at );
	}
}
