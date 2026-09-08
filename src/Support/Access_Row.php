<?php
/**
 * One held thing, as a row.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

use WP_Term;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * A group, a post or a file turned into the keys the row block declares: `title`, `meta`, `href`, `state`, `action_label`, `expiry_state`, `expiry_label`.
 *
 * Shared because two pages draw the same file: My Access lists it among what a person holds and Files lists it among what they can download, so it is described once or the two pages drift apart.
 *
 * Returns null when the thing no longer resolves, a deleted post, an unpublished one, a group whose term has gone, because a row for something that is not there is worse than no row.
 */
class Access_Row {

	/**
	 * Groups are named by their term.
	 *
	 * @param Access_Taxonomy $taxonomy Group identity.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * A held group: its name over a count of what it contains right now.
	 *
	 * @param string   $uuid       The group.
	 * @param int|null $expires_at When the access runs out.
	 * @return array<string, mixed>|null
	 */
	public function group( string $uuid, ?int $expires_at ): ?array {
		$term = $this->taxonomy->find_group( $uuid );

		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$expiry = Expiry::describe( $expires_at );

		return array(
			'title'        => $term->name,
			/* translators: %d: how many files and posts the group contains. */
			'meta'         => sprintf( _n( '%d item', '%d items', $term->count, 'gated-media-access' ), $term->count ),
			// The row opens the group, because the count was only half the answer and the contents are live, so they are a page rather than a list.
			'href'         => Account_Url::detail( 'my-access', $uuid ),
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
	public function post( int $post_id, ?int $expires_at ): ?array {
		$post = get_post( $post_id );

		// Gated counts as readable: the status is the whole point of the grant, and its permalink is already the UUID URL.
		if ( null === $post || ! in_array( $post->post_status, array( 'publish', Post_Types::STATUS_GATED ), true ) ) {
			return null;
		}

		$expiry = Expiry::describe( $expires_at );

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
	 * A downloadable file: title over type and size, its URL as the action.
	 *
	 * @param int      $file_id    The attachment.
	 * @param int|null $expires_at When the access runs out.
	 * @return array<string, mixed>|null
	 */
	public function file( int $file_id, ?int $expires_at ): ?array {
		$file = get_post( $file_id );

		if ( null === $file || 'attachment' !== $file->post_type ) {
			return null;
		}

		$path   = get_attached_file( $file_id );
		$size   = is_string( $path ) && file_exists( $path ) ? (string) size_format( (int) filesize( $path ) ) : '';
		$mime   = (string) get_post_mime_type( $file );
		$type   = strtoupper( substr( (string) strrchr( $mime, '/' ), 1 ) );
		$title  = '' !== $file->post_title ? $file->post_title : basename( (string) $path );
		$expiry = Expiry::describe( $expires_at );

		return array(
			'title'        => $title,
			'meta'         => self::joined( array( $type, $size ) ),
			'href'         => (string) wp_get_attachment_url( $file_id ),
			'state'        => 'normal',
			'type'         => self::filter_type( $mime ),
			'action_label' => __( 'Download', 'gated-media-access' ),
			'expiry_state' => $expiry['state'],
			'expiry_label' => $expiry['label'],
		);
	}

	/**
	 * Which of the Files view's type filters a file answers to.
	 *
	 * Anything outside the list answers to none of them and shows only under All, because the filter names four kinds rather than every kind.
	 *
	 * @param string $mime The attachment's mime type.
	 */
	public static function filter_type( string $mime ): string {
		if ( str_starts_with( $mime, 'video/' ) ) {
			return 'video';
		}

		if ( str_starts_with( $mime, 'audio/' ) ) {
			return 'audio';
		}

		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}

		return in_array( $mime, array( 'application/zip', 'application/x-zip-compressed' ), true ) ? 'zip' : '';
	}

	/**
	 * The parts of a meta line that are actually there, dot-separated.
	 *
	 * @param array<int, string> $parts The candidates.
	 */
	public static function joined( array $parts ): string {
		return implode(
			' · ',
			array_filter( $parts, static fn ( string $part ): bool => '' !== $part )
		);
	}
}
