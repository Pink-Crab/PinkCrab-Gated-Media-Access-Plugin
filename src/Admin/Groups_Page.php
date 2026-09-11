<?php
/**
 * The Groups screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Admin\Pickers\File_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Post_Picker;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Support\Uuid;
use PinkCrab\Gated_Access\Support\View;

/**
 * A group is not a term in the ordinary sense, so it is not administered as one. Core's taxonomy screens keep no menu entry, because they hung a "Groups" entry under Posts, Pages and Media and can only ever show a name, a slug and a misleading count. The screens themselves stay reachable by URL, so `Access_Taxonomy` maps their four capabilities to this screen's own.
 *
 * A group is a thing that holds content, and that people hold. This screen is those two facts per group in one place, drawn in the Settings screen's vocabulary rather than as a `WP_List_Table`, which would look like the thing this replaces.
 *
 * The term stays the storage: `Resolver` reads group membership from it and grants name a group by its UUID, so nothing about the data model moves, only the administration of it.
 *
 * The markup is in `views/admin/groups/`; everything here turns terms, posts and users into the rows those templates draw.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Moving the markup out added View as a thirteenth name. The screen reads one group's terms, posts, users and pickers, and each is a real dependency of the answer.
 */
class Groups_Page implements Hookable {

	/** The page's own slug. */
	public const PAGE_SLUG = 'gatedmedia-groups';

	/** The query value that opens one group. */
	public const MODE_EDIT = 'edit';

	/**
	 * Reads who holds what.
	 *
	 * @param Access_Lookup   $lookup      Answers who holds a group.
	 * @param Restriction     $restriction Owns the marker term, which is not a group.
	 * @param Access_Taxonomy $taxonomy    Resolves a group from its UUID.
	 */
	public function __construct(
		private Access_Lookup $lookup,
		private Restriction $restriction,
		private Access_Taxonomy $taxonomy,
	) {
	}

	/**
	 * The menu entry. The writes are `Group_Actions`.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Under the plugin's own menu, where somebody looking for groups looks.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__( 'Groups', 'gated-media-access' ),
			__( 'Groups', 'gated-media-access' ),
			Capabilities::manage_settings(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The screen, in one of its two modes.
	 *
	 * One slug with a mode on the query, as `Settings_Page` does, so a single group has a linkable URL without a second menu entry.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Chooses which view renders; the write handlers carry nonces.
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		$uuid = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$group = self::MODE_EDIT === $mode ? $this->taxonomy->find_group( $uuid ) : null;

		View::render(
			'admin/groups/index',
			array(
				'title'      => $group instanceof WP_Term ? $group->name : __( 'Groups', 'gated-media-access' ),
				'group_name' => $group instanceof WP_Term ? $group->name : '',
				'group_url'  => self::url_for( $uuid ),
				'list_url'   => self::url(),
				'notice'     => $this->notice(),
				'edit'       => $group instanceof WP_Term ? $this->edit_data( $group, $uuid ) : null,
				'list'       => $group instanceof WP_Term ? null : $this->list_data(),
			)
		);
	}

	/**
	 * The list's own URL.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * One group's URL.
	 *
	 * @param string $uuid The group's identity.
	 */
	public static function url_for( string $uuid ): string {
		return add_query_arg(
			array(
				'page'  => self::PAGE_SLUG,
				'mode'  => self::MODE_EDIT,
				'group' => rawurlencode( $uuid ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * One group's screen: its name, what it holds, who holds it, and the two ways to add.
	 *
	 * Search fields rather than selects: the list is every post and every file on the site, which is not a dropdown.
	 *
	 * @param WP_Term $group The group.
	 * @param string  $uuid  Its identity.
	 * @return array<string, mixed>
	 */
	private function edit_data( WP_Term $group, string $uuid ): array {
		return array(
			'uuid'        => $uuid,
			'name'        => $group->name,
			'description' => $group->description,
			'form_url'    => admin_url( 'admin-post.php' ),
			'save_action' => Group_Actions::SAVE_ACTION,
			'item_action' => Group_Actions::ITEM_ACTION,
			'items'       => $this->item_rows( $this->contents_of( $group ) ),
			'holders'     => $this->holder_rows( $this->lookup->holders_of( 'group', $uuid ) ),
			'pickers'     => array(
				array(
					'picker' => new Post_Picker( 'item', 'gatedmedia_group_post_' . $uuid ),
					'label'  => __( 'Add a post or page', 'gated-media-access' ),
				),
				array(
					'picker' => new File_Picker( 'item', 'gatedmedia_group_file_' . $uuid ),
					'label'  => __( 'Add a file', 'gated-media-access' ),
				),
			),
		);
	}

	/**
	 * The create form and one panel per group.
	 *
	 * The first panel is open, as the Notifications panels are, because the point of the screen is seeing what a group holds.
	 *
	 * @return array<string, mixed>
	 */
	private function list_data(): array {
		$panels = array();

		foreach ( $this->groups() as $index => $group ) {
			$uuid = Uuid::ensure( 'term', (int) $group->term_id );

			$panels[] = array(
				'name'    => $group->name,
				'url'     => self::url_for( $uuid ),
				'open'    => 0 === $index,
				'items'   => $this->item_rows( $this->contents_of( $group ) ),
				'holders' => $this->holder_rows( $this->lookup->holders_of( 'group', $uuid ) ),
			);
		}

		return array(
			'form_url'      => admin_url( 'admin-post.php' ),
			'create_action' => Group_Actions::CREATE_ACTION,
			'groups'        => $panels,
		);
	}

	/**
	 * What just happened, if anything did.
	 *
	 * @return array{message: string, type: string}|null
	 */
	private function notice(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['gatedmedia_notice'] ) ? sanitize_key( wp_unslash( $_GET['gatedmedia_notice'] ) ) : '';

		$messages = array(
			'created' => __( 'Group created.', 'gated-media-access' ),
			'exists'  => __( 'A group by that name already exists.', 'gated-media-access' ),
			'empty'   => __( 'A group needs a name.', 'gated-media-access' ),
			'saved'   => __( 'Group saved.', 'gated-media-access' ),
			'added'   => __( 'Added to the group.', 'gated-media-access' ),
			'removed' => __( 'Taken out of the group.', 'gated-media-access' ),
			'no-item' => __( 'That item could not be found.', 'gated-media-access' ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return null;
		}

		return array(
			'message' => $messages[ $notice ],
			'type'    => in_array( $notice, array( 'created', 'saved', 'added', 'removed' ), true ) ? 'success' : 'error',
		);
	}

	/**
	 * What a group holds right now.
	 *
	 * @param WP_Term $group The group.
	 * @return array<int, int>
	 */
	private function contents_of( WP_Term $group ): array {
		$objects = get_objects_in_term( (int) $group->term_id, Access_Taxonomy::TAXONOMY );

		return is_array( $objects ) ? array_map( 'intval', $objects ) : array();
	}

	/**
	 * The contents as rows: what it is called, where it is edited, and what kind of thing it is.
	 *
	 * @param array<int, int> $object_ids The objects in the term.
	 * @return array<int, array{title: string, edit_url: string, type_label: string, id: int}>
	 */
	private function item_rows( array $object_ids ): array {
		$rows = array();

		foreach ( $object_ids as $object_id ) {
			$object = get_post( $object_id );

			if ( ! $object instanceof WP_Post ) {
				continue;
			}

			$rows[] = array(
				'id'         => $object_id,
				'title'      => '' === $object->post_title ? __( '(no title)', 'gated-media-access' ) : $object->post_title,
				'edit_url'   => (string) get_edit_post_link( $object_id ),
				'type_label' => 'attachment' === $object->post_type ? __( 'file', 'gated-media-access' ) : $object->post_type,
			);
		}

		return $rows;
	}

	/**
	 * The holders as rows, deleted users left out.
	 *
	 * @param array<int, int> $user_ids Who holds the group.
	 * @return array<int, array{name: string, email: string, edit_url: string}>
	 */
	private function holder_rows( array $user_ids ): array {
		$rows = array();

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			$rows[] = array(
				'name'     => $user->display_name,
				'email'    => $user->user_email,
				'edit_url' => (string) get_edit_user_link( $user_id ),
			);
		}

		return $rows;
	}

	/**
	 * Every group, the marker excluded, since it is not one.
	 *
	 * @return array<int, WP_Term>
	 */
	private function groups(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Access_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$marker  = $this->restriction->marker();
		$exclude = null === $marker ? 0 : (int) $marker->term_id;

		return array_values(
			array_filter(
				$terms,
				static fn( $term ): bool => $term instanceof WP_Term && $term->term_id !== $exclude
			)
		);
	}
}
