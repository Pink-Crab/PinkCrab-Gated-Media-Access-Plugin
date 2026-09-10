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
 * Not in `Plugin::SERVICES`, because files are served on `parse_request` before the main query. The bootstrap attaches its two hooks at plugin load and resolves this class lazily on the first protected-file request.
 *
 * Narrow on purpose: the dependency's helper turns a hash into an attachment, the resolver has the last word, and a request that places no attachment is refused.
 */
class File_Boundary {

	/**
	 * The hash as the protector splits it: hex, with an optional image-size suffix and no file extension.
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
	 * True refuses the file. Nothing placed is refused too: the incoming default allows anyone signed in.
	 *
	 * @param bool   $refuse         The decision so far, unused.
	 * @param string $protected_file The raw URL segment: the file hash, possibly size-suffixed.
	 */
	public function protect_file( bool $refuse, string $protected_file ): bool {
		$attachment_id = $this->attachment_from( $protected_file );

		if ( null === $attachment_id ) {
			return true;
		}

		return ! $this->resolver->can_see( get_current_user_id(), 'file', (string) $attachment_id );
	}

	/**
	 * Re-publishes `restrict_media_file_access_before_serve` as ours.
	 *
	 * The dependency fires it only on the allowed path, so every announcement is a real download.
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
		// Placed as the dependency places it, or it resolves a file this never judged.
		$hash = 1 === preg_match( self::HASH_SHAPE, $protected_file, $matches ) ? $matches[1] : $protected_file;

		return rmfa_find_attachment_id_by_hash( $hash );
	}
}
