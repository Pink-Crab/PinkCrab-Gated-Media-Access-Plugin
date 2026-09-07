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
 *
 * Over phpmd's class ceiling, and the score is markup rather than logic:
 * three sections are printed here, each with its own empty state and its own
 * list. Issue #54 moves the admin views to templates, which is where that
 * belongs; splitting the class would scatter one screen across several.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Item_Access_Metabox implements Hookable {

	/** The admin-post action moving an item in or out of a group. */
	public const GROUP_ACTION = 'gatedmedia_item_group';

	/** The admin-post action granting a user this item, inline. */
	public const GRANT_ACTION = 'gatedmedia_item_grant';

	/** What the box's own nonce protects. */
	public const SAVE_ACTION = 'gatedmedia_item_access_save';

	/** The nonce field carrying it. */
	public const SAVE_NONCE = 'gatedmedia_item_access_nonce';

	/** Who the pending grant is for. */
	public const FIELD_USER = 'gatedmedia_grant_user';

	/** How many days it runs for, empty for lifetime. */
	public const FIELD_DAYS = 'gatedmedia_grant_days';

	/** The group the item is to join, by UUID. */
	public const FIELD_GROUP = 'gatedmedia_join_group';

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
		$loader->action( 'save_post', array( $this, 'save_item' ) );
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
					// The same word the Access list and the settings page use:
					// this runs the site's revoke behaviour, up to deleting
					// the record outright, which "Remove" does not suggest.
					esc_html__( 'Revoke', 'gated-media-access' )
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

		// Labelled rather than left to the placeholders: this sits inside the
		// editor, where the surrounding fields all carry one, and a
		// placeholder is gone as soon as anything is typed. The ids carry the
		// item so two metaboxes on one screen never collide.
		$days_id = 'gatedmedia_metabox_days_' . $item_id;
		$user_id = 'gatedmedia_metabox_user_' . $item_id . '_search';

		printf(
			'<label class="screen-reader-text" for="%s">%s</label>',
			esc_attr( $user_id ),
			esc_html__( 'User to give access to', 'gated-media-access' )
		);

		( new User_Picker( self::FIELD_USER, 'gatedmedia_metabox_user_' . $item_id ) )->render();

		printf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label>
			<input type="number" min="1" id="%1$s" name="%3$s" placeholder="%4$s" class="gatedmedia-inline-grant-days" />
			<p class="description">%5$s</p>',
			esc_attr( $days_id ),
			esc_html__( 'Days of access, or empty for lifetime', 'gated-media-access' ),
			esc_attr( self::FIELD_DAYS ),
			esc_attr__( 'Days', 'gated-media-access' ),
			esc_html__( 'Empty days means lifetime. Access is given when you save.', 'gated-media-access' )
		);

		wp_nonce_field( self::SAVE_ACTION . '_' . $item_id, self::SAVE_NONCE );

		echo '</div>';
	}

	/**
	 * Applies a staged grant when the item itself is saved.
	 *
	 * The button used to set `window.location` to an admin-post URL the moment
	 * it was pressed, which left the editor mid-edit: unsaved work went, and
	 * the access was written against a post that might never be saved. The box
	 * carries its own fields inside the editor's form, and this reads them.
	 *
	 * @param int $item_id The post or attachment being saved.
	 */
	public function save_item( int $item_id ): void {
		if ( ! $this->may_save( $item_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
		$user_id = isset( $_POST[ self::FIELD_USER ] ) ? absint( $_POST[ self::FIELD_USER ] ) : 0;
		$days    = isset( $_POST[ self::FIELD_DAYS ] ) ? absint( $_POST[ self::FIELD_DAYS ] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 0 !== $user_id ) {
			$this->apply_grant( $item_id, $user_id, $days );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$group = isset( $_POST[ self::FIELD_GROUP ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_GROUP ] ) ) : '';

		if ( '' !== $group ) {
			$this->apply_group( $item_id, $group, 'add' );
		}
	}

	/**
	 * Whether this save is the administrator's own, on this very item.
	 *
	 * An autosave is not: it fires while they are still typing, and would
	 * grant against a post they have not finished.
	 *
	 * @param int $item_id The post or attachment being saved.
	 */
	private function may_save( int $item_id ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		$nonce = isset( $_POST[ self::SAVE_NONCE ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::SAVE_NONCE ] ) )
			: '';

		if ( false === wp_verify_nonce( $nonce, self::SAVE_ACTION . '_' . $item_id ) ) {
			return false;
		}

		return current_user_can( Capabilities::give_access() );
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

		$group_id = 'gatedmedia_metabox_group_' . $item_id;

		printf(
			'<label class="screen-reader-text" for="%s_search">%s</label>',
			esc_attr( $group_id ),
			esc_html__( 'Group to add this item to', 'gated-media-access' )
		);

		( new Group_Picker( self::FIELD_GROUP, $group_id ) )->render();

		printf( '<p class="description">%s</p>', esc_html__( 'The item joins the group when you save.', 'gated-media-access' ) );
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

		// Adding a group restricts the item and moves an attachment's file,
		// so it needs the right to edit that item, not just the taxonomy.
		if ( ! current_user_can( 'edit_post', $item_id ) ) {
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
