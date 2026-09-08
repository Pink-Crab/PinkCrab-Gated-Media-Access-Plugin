<?php
/**
 * The access list on a user's profile.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_User;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * The same list, for one person, on their profile screen: every record they hold, whatever its status, read-only because granting and revoking live on the Access screens one link away.
 */
class Profile_Access_List implements Hookable {

	/**
	 * Cells render exactly as the Access list renders them.
	 *
	 * @param Access_List $access_list The list screen's column renderers.
	 */
	public function __construct( private Access_List $access_list ) {
	}

	/**
	 * Both profile surfaces: your own, and someone else's.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'show_user_profile', array( $this, 'render' ) );
		$loader->admin_action( 'edit_user_profile', array( $this, 'render' ) );
	}

	/**
	 * The person's records, or nothing at all without the capability.
	 *
	 * @param WP_User $user The profile being viewed.
	 */
	public function render( WP_User $user ): void {
		if ( ! current_user_can( Capabilities::give_access() ) ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Access', 'gated-media-access' ) );

		$records = $this->records_for( $user->ID );

		if ( array() === $records ) {
			printf( '<p>%s</p>', esc_html__( 'This user holds no access records.', 'gated-media-access' ) );

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf(
			'<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
			esc_html__( 'Item', 'gated-media-access' ),
			esc_html__( 'Status', 'gated-media-access' ),
			esc_html__( 'Expires', 'gated-media-access' ),
			esc_html__( 'Source', 'gated-media-access' )
		);
		echo '</tr></thead><tbody>';

		foreach ( $records as $access_id ) {
			echo '<tr>';

			foreach ( array( 'gatedmedia_item', 'gatedmedia_status', 'gatedmedia_expiry', 'gatedmedia_source' ) as $column ) {
				echo '<td>';
				$this->access_list->render_column( $column, $access_id );
				echo '</td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( add_query_arg( 'author', $user->ID, admin_url( 'edit.php?post_type=' . Post_Types::ACCESS ) ) ),
			esc_html__( 'Manage on the Access screen', 'gated-media-access' )
		);
	}

	/**
	 * Every record the person holds: all three statuses named, never 'any', newest first.
	 *
	 * @param int $user_id Whose records.
	 * @return array<int, int>
	 */
	private function records_for( int $user_id ): array {
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
