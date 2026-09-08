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

/**
 * A group is not a term in the ordinary sense, so it is not administered as one. Core's taxonomy screens are off, because they hung a "Groups" entry under Posts, Pages and Media and can only ever show a name, a slug and a misleading count.
 *
 * A group is a thing that holds content, and that people hold. This screen is those two facts per group in one place, drawn in the Settings screen's vocabulary rather than as a `WP_List_Table`, which would look like the thing this replaces.
 *
 * The term stays the storage: `Resolver` reads group membership from it and grants name a group by its UUID, so nothing about the data model moves, only the administration of it.
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
	 * The take-it-out button for one row.
	 *
	 * A posting form rather than a link: removing something is a write, and a write behind a GET is one prefetch away from happening by itself.
	 *
	 * @param string $uuid    The group.
	 * @param int    $item_id The item to take out.
	 */
	private function remove_control( string $uuid, int $item_id ): string {
		return sprintf(
			'<form method="post" action="%s" class="gatedmedia-admin-row-action">
				<input type="hidden" name="action" value="%s" />
				<input type="hidden" name="group" value="%s" />
				<input type="hidden" name="item" value="%d" />
				<input type="hidden" name="op" value="remove" />
				%s
				<button type="submit" class="button-link">%s</button>
			</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( Group_Actions::ITEM_ACTION ),
			esc_attr( $uuid ),
			$item_id,
			wp_nonce_field( Group_Actions::ITEM_ACTION, '_wpnonce', true, false ),
			esc_html__( 'Remove', 'gated-media-access' )
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
		?>
		<div class="wrap">
			<div class="gatedmedia-admin">
				<header class="gatedmedia-admin-header">
					<div>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
						<h1><?php echo esc_html( $group instanceof WP_Term ? $group->name : __( 'Groups', 'gated-media-access' ) ); ?></h1>
					</div>
				</header>

				<nav class="gatedmedia-admin-tabs">
					<a class="<?php echo $group instanceof WP_Term ? '' : 'is-active'; ?>" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All groups', 'gated-media-access' ); ?></a>
					<?php if ( $group instanceof WP_Term ) : ?>
						<a class="is-active" href="<?php echo esc_url( self::url_for( $uuid ) ); ?>"><?php echo esc_html( $group->name ); ?></a>
					<?php endif; ?>
				</nav>

				<?php $this->render_notice(); ?>

				<?php if ( $group instanceof WP_Term ) : ?>
					<?php $this->render_edit( $group, $uuid ); ?>
				<?php else : ?>
					<?php $this->render_create(); ?>

					<div class="gatedmedia-admin-section-head">
						<h2><?php esc_html_e( 'Every group', 'gated-media-access' ); ?></h2>
						<span class="gatedmedia-admin-caps"><?php esc_html_e( 'What it holds, and who holds it', 'gated-media-access' ); ?></span>
					</div>

					<?php $this->render_groups(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
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
	 * One group: its name, what it holds, and who holds it.
	 *
	 * @param WP_Term $group The group.
	 * @param string  $uuid  Its identity.
	 */
	private function render_edit( WP_Term $group, string $uuid ): void {
		$holders = $this->lookup->holders_of( 'group', $uuid );
		$objects = $this->contents_of( $group );
		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Details', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'What this group is called', 'gated-media-access' ); ?></span>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Group_Actions::SAVE_ACTION ); ?>" />
			<input type="hidden" name="group" value="<?php echo esc_attr( $uuid ); ?>" />
			<?php wp_nonce_field( Group_Actions::SAVE_ACTION ); ?>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_group_edit_name"><?php esc_html_e( 'Name', 'gated-media-access' ); ?></label>
				<input type="text" class="regular-text" id="gatedmedia_group_edit_name" name="group_name" value="<?php echo esc_attr( $group->name ); ?>" required />
			</div>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_group_edit_description"><?php esc_html_e( 'Description', 'gated-media-access' ); ?></label>
				<textarea rows="3" class="large-text" id="gatedmedia_group_edit_description" name="group_description"><?php echo esc_textarea( $group->description ); ?></textarea>
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'For your own reference. Nobody outside the admin sees it.', 'gated-media-access' ); ?></p>
			</div>

			<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Save group', 'gated-media-access' ); ?></button>
		</form>

		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Contents', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps">
				<?php
				printf(
					/* translators: %d: number of items. */
					esc_html( _n( '%d item', '%d items', count( $objects ), 'gated-media-access' ) ),
					count( $objects )
				);
				?>
			</span>
		</div>

		<?php $this->render_contents( $objects, $uuid ); ?>
		<?php $this->render_add( $uuid ); ?>

		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Who has access', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps">
				<?php
				printf(
					/* translators: %d: number of people. */
					esc_html( _n( '%d person', '%d people', count( $holders ), 'gated-media-access' ) ),
					count( $holders )
				);
				?>
			</span>
		</div>

		<?php $this->render_holders( $holders ); ?>
		<?php
	}

	/**
	 * Two search fields, one per kind of thing a group can hold.
	 *
	 * Search rather than a select: the list is every post and every file on the site, which is not a dropdown.
	 *
	 * @param string $uuid The group being added to.
	 */
	private function render_add( string $uuid ): void {
		$fields = array(
			'post' => array( new Post_Picker( 'item', 'gatedmedia_group_post_' . $uuid ), __( 'Add a post or page', 'gated-media-access' ) ),
			'file' => array( new File_Picker( 'item', 'gatedmedia_group_file_' . $uuid ), __( 'Add a file', 'gated-media-access' ) ),
		);

		foreach ( $fields as $picker ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Group_Actions::ITEM_ACTION ); ?>" />
				<input type="hidden" name="group" value="<?php echo esc_attr( $uuid ); ?>" />
				<input type="hidden" name="op" value="add" />
				<?php wp_nonce_field( Group_Actions::ITEM_ACTION ); ?>

				<div class="gatedmedia-admin-field">
					<label class="gatedmedia-admin-caps"><?php echo esc_html( $picker[1] ); ?></label>
					<span class="gatedmedia-admin-inline">
						<?php $picker[0]->render(); ?>
						<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Add', 'gated-media-access' ); ?></button>
					</span>
				</div>
			</form>
			<?php
		}
	}

	/**
	 * What just happened, if anything did.
	 */
	private function render_notice(): void {
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
			return;
		}

		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			in_array( $notice, array( 'created', 'saved', 'added', 'removed' ), true ) ? 'success' : 'error',
			esc_html( $messages[ $notice ] )
		);
	}

	/**
	 * The create form. A name is all a group needs to exist.
	 */
	private function render_create(): void {
		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'New group', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'A name is all it needs', 'gated-media-access' ); ?></span>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Group_Actions::CREATE_ACTION ); ?>" />
			<?php wp_nonce_field( Group_Actions::CREATE_ACTION ); ?>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_group_name"><?php esc_html_e( 'Name', 'gated-media-access' ); ?></label>
				<span class="gatedmedia-admin-inline">
					<input type="text" class="regular-text" id="gatedmedia_group_name" name="group_name" required />
					<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Create group', 'gated-media-access' ); ?></button>
				</span>
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'Content joins a group from the item’s own Access panel.', 'gated-media-access' ); ?></p>
			</div>
		</form>
		<?php
	}

	/**
	 * One panel per group.
	 */
	private function render_groups(): void {
		$groups = $this->groups();

		if ( array() === $groups ) {
			printf( '<p class="gatedmedia-admin-help">%s</p>', esc_html__( 'No groups yet.', 'gated-media-access' ) );

			return;
		}

		// The first is open, as the Notifications panels are, because the point of the screen is seeing what a group holds.
		foreach ( $groups as $index => $group ) {
			$this->render_group( $group, 0 === $index );
		}
	}

	/**
	 * A group, its contents and its people.
	 *
	 * @param WP_Term $group The group.
	 * @param bool    $open  Whether it starts open.
	 */
	private function render_group( WP_Term $group, bool $open ): void {
		$uuid    = Uuid::ensure( 'term', (int) $group->term_id );
		$holders = $this->lookup->holders_of( 'group', $uuid );
		$objects = get_objects_in_term( (int) $group->term_id, Access_Taxonomy::TAXONOMY );
		$objects = is_array( $objects ) ? array_map( 'intval', $objects ) : array();
		?>
		<details class="gatedmedia-admin-panel" <?php echo $open ? 'open' : ''; ?>>
			<summary>
				<span class="gatedmedia-admin-panel-title"><a href="<?php echo esc_url( self::url_for( $uuid ) ); ?>"><?php echo esc_html( $group->name ); ?></a></span>
				<span class="gatedmedia-admin-caps">
					<?php
					printf(
						'%s · %s',
						esc_html(
							sprintf(
								/* translators: %d: number of items. */
								_n( '%d item', '%d items', count( $objects ), 'gated-media-access' ),
								count( $objects )
							)
						),
						esc_html(
							sprintf(
								/* translators: %d: number of people with access. */
								_n( '%d with access', '%d with access', count( $holders ), 'gated-media-access' ),
								count( $holders )
							)
						)
					);
					?>
				</span>
			</summary>

			<div class="gatedmedia-admin-panel-body">
				<p class="gatedmedia-admin-caps"><?php esc_html_e( 'Contains', 'gated-media-access' ); ?></p>
				<?php $this->render_contents( $objects ); ?>

				<p class="gatedmedia-admin-caps"><?php esc_html_e( 'Who has access', 'gated-media-access' ); ?></p>
				<?php $this->render_holders( $holders ); ?>
			</div>
		</details>
		<?php
	}

	/**
	 * What a group holds, files and posts alike.
	 *
	 * @param array<int, int> $object_ids The objects in the term.
	 * @param string          $uuid       The group, '' on the list where there is no remove.
	 */
	private function render_contents( array $object_ids, string $uuid = '' ): void {
		if ( array() === $object_ids ) {
			printf( '<p class="gatedmedia-admin-help">%s</p>', esc_html__( 'Nothing yet.', 'gated-media-access' ) );

			return;
		}

		echo '<ul class="gatedmedia-admin-list">';

		foreach ( $object_ids as $object_id ) {
			$object = get_post( $object_id );

			if ( ! $object instanceof WP_Post ) {
				continue;
			}

			printf(
				'<li><a href="%s">%s</a> <span class="gatedmedia-admin-caps">%s</span>%s</li>',
				esc_url( (string) get_edit_post_link( $object_id ) ),
				esc_html( '' === $object->post_title ? __( '(no title)', 'gated-media-access' ) : $object->post_title ),
				esc_html( 'attachment' === $object->post_type ? __( 'file', 'gated-media-access' ) : $object->post_type ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- remove_control() escapes every value it interpolates and returns finished markup.
				'' === $uuid ? '' : $this->remove_control( $uuid, $object_id )
			);
		}

		echo '</ul>';
	}

	/**
	 * Who holds the group.
	 *
	 * @param array<int, int> $holders User ids.
	 */
	private function render_holders( array $holders ): void {
		if ( array() === $holders ) {
			printf( '<p class="gatedmedia-admin-help">%s</p>', esc_html__( 'Nobody yet.', 'gated-media-access' ) );

			return;
		}

		echo '<ul class="gatedmedia-admin-list">';

		foreach ( $holders as $user_id ) {
			$user = get_userdata( $user_id );

			if ( false === $user ) {
				continue;
			}

			printf(
				'<li><a href="%s">%s</a> <span class="gatedmedia-admin-caps">%s</span></li>',
				esc_url( (string) get_edit_user_link( $user_id ) ),
				esc_html( $user->display_name ),
				esc_html( $user->user_email )
			);
		}

		echo '</ul>';
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
