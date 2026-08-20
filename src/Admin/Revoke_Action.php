<?php
/**
 * The revoke action on the Access list.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * Takes the row's revoke click and puts it through the writer, doing whatever
 * the site's revoke behaviour says — mark revoked, expire now, or delete
 * (architecture.md §9). All three are writer methods; this class decides
 * nothing about the record itself.
 */
class Revoke_Action implements Hookable {

	/** The admin-post action, and the nonce it carries. */
	public const ACTION = 'gatedmedia_revoke_access';

	/**
	 * The writer changes the record; the settings say how.
	 *
	 * @param Access_Writer $writer   The one writer of access records.
	 * @param Settings      $settings The site's revoke behaviour.
	 */
	public function __construct( private Access_Writer $writer, private Settings $settings ) {
	}

	/**
	 * One admin-post handler.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The URL a row's revoke link posts to, nonced per record.
	 *
	 * @param int $access_id The record the link revokes.
	 */
	public static function url_for( int $access_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'access' => $access_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $access_id
		);
	}

	/**
	 * Guards the click, applies it, and returns to the list.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body
	 * alongside the Location header (the `Profile_Writer::handle()` note).
	 */
	public function handle(): void {
		$access_id = isset( $_GET['access'] ) ? absint( $_GET['access'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The id names which nonce to check; check_admin_referer runs on the next line.

		check_admin_referer( self::ACTION . '_' . $access_id );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_die( esc_html__( 'You are not allowed to revoke access.', 'gated-media-access' ), '', 403 );
		}

		$applied = $this->apply( $access_id );

		wp_safe_redirect( add_query_arg( 'gatedmedia_revoked', $applied ? '1' : '0', $this->return_url() ) );
		exit;
	}

	/**
	 * Back where the click came from — the list, a metabox, wherever —
	 * defaulting to the Access list.
	 */
	private function return_url(): string {
		$referer = wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			return remove_query_arg( 'gatedmedia_revoked', $referer );
		}

		return admin_url( 'edit.php?post_type=' . Post_Types::ACCESS );
	}

	/**
	 * Puts one record through the site's revoke behaviour, via the writer.
	 *
	 * @param int $access_id The record to withdraw.
	 */
	public function apply( int $access_id ): bool {
		return match ( $this->settings->revoke_behaviour() ) {
			Settings::REVOKE_BEHAVIOUR_EXPIRE => $this->writer->expire( $access_id ),
			Settings::REVOKE_BEHAVIOUR_DELETE => $this->writer->delete( $access_id ),
			default                           => $this->writer->revoke( $access_id ),
		};
	}
}
