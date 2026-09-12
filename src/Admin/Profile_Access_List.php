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
use PinkCrab\Gated_Access\Support\View;

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

		$columns = array(
			'gatedmedia_item'   => __( 'Item', 'gated-media-access' ),
			'gatedmedia_status' => __( 'Status', 'gated-media-access' ),
			'gatedmedia_expiry' => __( 'Expires', 'gated-media-access' ),
			'gatedmedia_source' => __( 'Source', 'gated-media-access' ),
		);

		View::render(
			'admin/profile-access',
			array(
				'columns'    => $columns,
				'rows'       => $this->rows( $this->records_for( $user->ID ), array_keys( $columns ) ),
				'manage_url' => add_query_arg( 'author', $user->ID, admin_url( 'edit.php?post_type=' . Post_Types::ACCESS ) ),
			)
		);
	}

	/**
	 * One row per record, its cells rendered exactly as the Access list renders them.
	 *
	 * `Access_List::render_column()` prints, so each cell is captured rather than returned.
	 *
	 * @param array<int, int>    $record_ids The records.
	 * @param array<int, string> $columns    Which columns, in order.
	 * @return array<int, array<string, string>>
	 */
	private function rows( array $record_ids, array $columns ): array {
		$rows = array();

		foreach ( $record_ids as $access_id ) {
			$row = array();

			foreach ( $columns as $column ) {
				ob_start();
				$this->access_list->render_column( $column, $access_id );
				$row[ $column ] = (string) ob_get_clean();
			}

			$rows[] = $row;
		}

		return $rows;
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
