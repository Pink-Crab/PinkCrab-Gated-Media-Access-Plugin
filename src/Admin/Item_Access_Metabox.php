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
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Admin\Pickers\Group_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\User_Picker;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The one Access surface on a post or file's own edit screen: who holds it
 * directly — each removable through the revoke action, adding a click away
 * on the pre-filled Add Access form — and which groups it sits in, with add
 * and remove right here. Core's own taxonomy fields (editor panel, tag box,
 * quick edit) are switched off on the taxonomy; this box is the assignment
 * surface, and groups themselves are made on the Groups screen.
 *
 * Links, not forms: a metabox lives inside the editor's own form, and a form
 * in a form posts the wrong one. Removals are nonced links; adding to a
 * group is a picker whose button the admin bundle turns into a nonced
 * navigation. Records still only change through the writer; group
 * membership goes through `wp_set_object_terms()`, where `Restriction`'s
 * marker-and-protect behaviours already hang.
 *
 * Direct records only in the holders list. A user holding a group that
 * contains this item is the group's business.
 */
class Item_Access_Metabox implements Hookable {

	/** The admin-post action moving an item in or out of a group. */
	public const GROUP_ACTION = 'gatedmedia_item_group';

	/** The admin-post action granting a user this item, inline. */
	public const GRANT_ACTION = 'gatedmedia_item_grant';

	/**
	 * Groups resolve through the taxonomy's UUID identity; grants are the
	 * writer's, as everywhere.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID into its term, and back.
	 * @param Access_Writer   $writer   The one writer of access records.
	 */
	public function __construct( private Access_Taxonomy $taxonomy, private Access_Writer $writer ) {
	}

	/**
	 * The box, its two handlers, and the outcome notice.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		$loader->action( 'admin_post_' . self::GROUP_ACTION, array( $this, 'handle_group' ) );
		$loader->action( 'admin_post_' . self::GRANT_ACTION, array( $this, 'handle_grant' ) );
		$loader->admin_action( 'admin_notices', array( $this, 'render_notices' ) );
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

		$this->render_grant( (int) $post->ID );
		$this->render_groups( (int) $post->ID );
	}

	/**
	 * Granting from right here: a user, optional days, one button. The
	 * admin bundle turns the button into a nonced navigation carrying the
	 * picker's choice — no form inside the editor's form.
	 *
	 * @param int $item_id The post or attachment.
	 */
	private function render_grant( int $item_id ): void {
		echo '<div class="gatedmedia-inline-grant">';

		( new User_Picker( 'gatedmedia_metabox_user', 'gatedmedia_metabox_user_' . $item_id ) )->render();

		printf(
			'<input type="number" min="1" placeholder="%s" class="gatedmedia-inline-grant-days" />
			<button type="button" class="button gatedmedia-grant-access" data-gatedmedia-url="%s">%s</button>
			<p class="description">%s</p>',
			esc_attr__( 'Days', 'gated-media-access' ),
			esc_url(
				wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::GRANT_ACTION,
							'item'   => $item_id,
						),
						admin_url( 'admin-post.php' )
					),
					self::GRANT_ACTION . '_' . $item_id
				)
			),
			esc_html__( 'Grant access', 'gated-media-access' ),
			esc_html__( 'Empty days means lifetime.', 'gated-media-access' )
		);

		echo '</div>';
	}

	/**
	 * Guards the click, grants through the writer, returns to the editor.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body
	 * alongside the Location header (the `Profile_Writer::handle()` note).
	 */
	public function handle_grant(): void {
		$item_id = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The id names which nonce to check; check_admin_referer runs on the next line.

		check_admin_referer( self::GRANT_ACTION . '_' . $item_id );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_die( esc_html__( 'You are not allowed to give access.', 'gated-media-access' ), '', 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$granted = $this->apply_grant( $item_id, $user_id, $days );

		wp_safe_redirect(
			add_query_arg( 'gatedmedia_granted', $granted ? '1' : '0', (string) get_edit_post_link( $item_id, 'url' ) )
		);
		exit;
	}

	/**
	 * One inline grant, through the writer, typed by what the item is.
	 *
	 * @param int $item_id The post or attachment.
	 * @param int $user_id Who gains access.
	 * @param int $days    How long, 0 for lifetime.
	 */
	public function apply_grant( int $item_id, int $user_id, int $days ): bool {
		$item_type = 'attachment' === get_post_type( $item_id ) ? 'file' : 'post';

		$granted = $this->writer->grant(
			$user_id,
			$item_type,
			(string) $item_id,
			0 === $days ? null : $days,
			'admin',
			'',
			array(),
			get_current_user_id()
		);

		return is_int( $granted );
	}

	/**
	 * The item's groups: each removable, and a picker to join another.
	 *
	 * @param int $item_id The post or attachment.
	 */
	private function render_groups( int $item_id ): void {
		$current = $this->current_groups( $item_id );

		printf( '<hr /><p><strong>%s</strong></p>', esc_html__( 'Groups', 'gated-media-access' ) );

		if ( array() === $current ) {
			printf( '<p>%s</p>', esc_html__( 'This item is in no group.', 'gated-media-access' ) );
		}

		if ( array() !== $current ) {
			echo '<ul>';

			foreach ( $current as $uuid => $name ) {
				printf(
					'<li>%s — <a href="%s">%s</a></li>',
					esc_html( $name ),
					esc_url(
						add_query_arg(
							array(
								'op'    => 'remove',
								'group' => $uuid,
							),
							$this->group_url( $item_id )
						)
					),
					esc_html__( 'Remove', 'gated-media-access' )
				);
			}

			echo '</ul>';
		}

		( new Group_Picker( 'gatedmedia_metabox_group', 'gatedmedia_metabox_group_' . $item_id ) )->render();
		printf(
			' <button type="button" class="button gatedmedia-add-to-group" data-gatedmedia-url="%s">%s</button>',
			esc_url( add_query_arg( 'op', 'add', $this->group_url( $item_id ) ) ),
			esc_html__( 'Add to group', 'gated-media-access' )
		);
	}

	/**
	 * Moves the item in or out of a group, then returns to its editor.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body
	 * alongside the Location header (the `Profile_Writer::handle()` note).
	 */
	public function handle_group(): void {
		$item_id = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The id names which nonce to check; check_admin_referer runs on the next line.

		check_admin_referer( self::GROUP_ACTION . '_' . $item_id );

		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		if ( false === $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) ) {
			wp_die( esc_html__( 'You are not allowed to change groups.', 'gated-media-access' ), '', 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above.
		$uuid      = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		$operation = isset( $_GET['op'] ) ? sanitize_text_field( wp_unslash( $_GET['op'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$applied = $this->apply_group( $item_id, $uuid, $operation );

		wp_safe_redirect(
			add_query_arg( 'gatedmedia_group_updated', $applied ? '1' : '0', (string) get_edit_post_link( $item_id, 'url' ) )
		);
		exit;
	}

	/**
	 * One membership change. Adding runs through `wp_set_object_terms()`, so
	 * the restriction behaviours fire exactly as they do everywhere else;
	 * removal does not unrestrict, deliberately.
	 *
	 * @param int    $item_id   The post or attachment.
	 * @param string $uuid      The group.
	 * @param string $operation One of add, remove.
	 */
	public function apply_group( int $item_id, string $uuid, string $operation ): bool {
		$term = $this->taxonomy->find_group( $uuid );

		if ( null === $term || null === get_post( $item_id ) ) {
			return false;
		}

		if ( 'add' === $operation ) {
			return is_array( wp_set_object_terms( $item_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY, true ) );
		}

		if ( 'remove' === $operation ) {
			return true === wp_remove_object_terms( $item_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY );
		}

		return false;
	}

	/**
	 * The outcome notice, from the redirect flag.
	 */
	public function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect.
		if ( ! isset( $_GET['gatedmedia_group_updated'] ) ) {
			return;
		}

		echo '1' === sanitize_text_field( wp_unslash( $_GET['gatedmedia_group_updated'] ) )
			? sprintf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Groups updated.', 'gated-media-access' ) )
			: sprintf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'The group change did not apply.', 'gated-media-access' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The nonced admin-post base for this item's group changes.
	 *
	 * @param int $item_id The post or attachment.
	 */
	private function group_url( int $item_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::GROUP_ACTION,
					'item'   => $item_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::GROUP_ACTION . '_' . $item_id
		);
	}

	/**
	 * The groups this item is in — the marker is not a group.
	 *
	 * @param int $item_id The post or attachment.
	 * @return array<string, string> UUID to name.
	 */
	private function current_groups( int $item_id ): array {
		$terms = get_the_terms( $item_id, Access_Taxonomy::TAXONOMY );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$groups = array();

		foreach ( $terms as $term ) {
			if ( Restriction::MARKER_SLUG === $term->slug ) {
				continue;
			}

			$groups[ $this->taxonomy->uuid_for( $term->term_id ) ] = $term->name;
		}

		return $groups;
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
