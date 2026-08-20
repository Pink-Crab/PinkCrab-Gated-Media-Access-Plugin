<?php
/**
 * The file boundary.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

/**
 * Where the dependency asks us whether to serve a file, and tells us it did.
 *
 * Not in Plugin::SERVICES — files are served on `parse_request`, before the
 * main query, so the bootstrap attaches the two hooks at plugin load and
 * resolves this class lazily on the first protected-file request
 * (the SERVICES docblock's one exception).
 *
 * The boundary is narrow on purpose (architecture.md §5): the dependency's
 * own helper turns a hash into an attachment, the resolver has the last word,
 * and an unrecognised request keeps whatever decision came in.
 */
class File_Boundary {

	/**
	 * The hash as the protector splits it: hex, optional image-size suffix
	 * (AttachmentsProtector::extract_hash_and_size_suffix — the sized URL
	 * carries no file extension).
	 */
	private const HASH_SHAPE = '/^([a-f0-9]+)(?:-\d+x\d+)?$/';

	/**
	 * Access decisions come from the one resolver.
	 *
	 * @param Resolver $resolver The last word on who sees what.
	 */
	public function __construct( private Resolver $resolver ) {
	}

	/**
	 * The decision for `restrict_media_file_access_protect_file`.
	 *
	 * Returning true refuses the file (the dependency serves its substitute).
	 * A hash we cannot place leaves the incoming decision alone.
	 *
	 * @param bool   $refuse         The decision so far (the dependency defaults to refusing the signed out).
	 * @param string $protected_file The raw URL segment: the file hash, possibly size-suffixed.
	 */
	public function protect_file( bool $refuse, string $protected_file ): bool {
		$attachment_id = $this->attachment_from( $protected_file );

		if ( null === $attachment_id ) {
			return $refuse;
		}

		return ! $this->resolver->can_see( get_current_user_id(), 'file', (string) $attachment_id );
	}

	/**
	 * Re-publishes `restrict_media_file_access_before_serve` as ours.
	 *
	 * The dependency fires it only on the allowed path — a refusal serves the
	 * substitute GIF and never reaches it — so every announcement is a real
	 * download.
	 *
	 * @param int    $attachment_id The attachment served.
	 * @param string $file_path     The absolute path being served.
	 */
	public function announce_download( int $attachment_id, string $file_path ): void {
		/**
		 * Fires when a protected file is served to someone allowed to have it.
		 *
		 * @param int    $user_id       Who downloaded it. 0 when signed out.
		 * @param int    $attachment_id The attachment served.
		 * @param string $file_path     The absolute path served.
		 */
		do_action( 'gatedmedia_file_downloaded', get_current_user_id(), $attachment_id, $file_path );
	}

	/**
	 * The attachment behind a protected-file request, if it can be placed.
	 *
	 * @param string $protected_file The raw URL segment.
	 */
	private function attachment_from( string $protected_file ): ?int {
		if ( 1 !== preg_match( self::HASH_SHAPE, $protected_file, $matches ) ) {
			return null;
		}

		return rmfa_find_attachment_id_by_hash( $matches[1] );
	}
}
