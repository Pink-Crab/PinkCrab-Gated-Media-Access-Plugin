<?php
/**
 * The per-item access metabox.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Who has access to this post or file, on its own edit screen — with each
 * holder removable through the revoke action, and adding a click away on the
 * pre-filled Add Access form.
 *
 * Links, not forms: a metabox lives inside the editor's own form, and a form
 * in a form posts the wrong one. Both links go where the writing already
 * happens; nothing here touches a record.
 *
 * Direct records only. A user holding a group that contains this item is the
 * group's business — remove the item from the group, or the user from the
 * group, on their own screens.
 */
class Item_Access_Metabox implements Hookable {

	/**
	 * Registers on the restrictable types' edit screens.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
	}

	/**
	 * One metabox per restrictable type, for those who may give access.
	 */
	public function register_metabox(): void {
		if ( ! current_user_can( Capabilities::give_access() ) ) {
			return;
		}

		add_meta_box(
			'gatedmedia_item_access',
			__( 'Access', 'gated-media-access' ),
			array( $this, 'render' ),
			Access_Taxonomy::object_types(),
			'side'
		);
	}

	/**
	 * The holders, their expiries, and the two links out.
	 *
	 * @param WP_Post $post The post or attachment being edited.
	 */
	public function render( WP_Post $post ): void {
		$item_type = 'attachment' === $post->post_type ? 'file' : 'post';
		$holders   = $this->holders( $item_type, (string) $post->ID );

		if ( array() === $holders ) {
			printf( '<p>%s</p>', esc_html__( 'Nobody holds direct access to this item.', 'gated-media-access' ) );
		}

		if ( array() !== $holders ) {
			echo '<ul>';

			foreach ( $holders as $access_id => $holder ) {
				printf(
					'<li>%s <span class="description">(%s)</span> — <a href="%s">%s</a></li>',
					esc_html( $holder['name'] ),
					esc_html( $holder['expires'] ),
					esc_url( Revoke_Action::url_for( $access_id ) ),
					esc_html__( 'Remove', 'gated-media-access' )
				);
			}

			echo '</ul>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page'            => Add_Access_Page::PAGE_SLUG,
						'gatedmedia_type' => $item_type,
						'gatedmedia_item' => (string) $post->ID,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'Add access', 'gated-media-access' )
		);
	}

	/**
	 * The active direct records for one item: record ID to holder and expiry.
	 *
	 * @param string $item_type One of file, post.
	 * @param string $item_id   The item.
	 * @return array<int, array{name: string, expires: string}>
	 */
	private function holders( string $item_type, string $item_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				// Named, never 'any' — ours are excluded from 'any'.
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One edit screen, direct records only.
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

		foreach ( array_map( 'intval', $ids ) as $access_id ) {
			$record = get_post( $access_id );
			$user   = null === $record ? false : get_userdata( (int) $record->post_author );
			$expiry = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

			$holders[ $access_id ] = array(
				'name'    => false === $user ? __( 'Unknown user', 'gated-media-access' ) : $user->display_name,
				'expires' => '' === $expiry
					? __( 'Lifetime', 'gated-media-access' )
					: (string) wp_date( (string) get_option( 'date_format' ), (int) strtotime( $expiry . ' +0000' ) ),
			);
		}

		return $holders;
	}
}
