<?php
/**
 * The Add Access form page.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Error;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Adding access is picking a user, an item and a duration (architecture.md
 * §9) — this page is that form and nothing else. The record itself is the
 * writer's: the handler translates the request into `Access_Writer::grant()`
 * arguments and reports what came back.
 *
 * The core editor never opens for an access record (`create_posts` is
 * do_not_allow), so this form is the only admin door in.
 */
class Add_Access_Page implements Hookable {

	/** The page's slug under the plugin menu. */
	public const PAGE_SLUG = 'gatedmedia-add-access';

	/** The admin-post action, and the nonce it carries. */
	public const ACTION = 'gatedmedia_add_access';

	/**
	 * Grants go through the writer; group choices resolve to UUIDs.
	 *
	 * @param Access_Writer   $writer   The one writer of access records.
	 * @param Access_Taxonomy $taxonomy Lists the groups and their identities.
	 */
	public function __construct( private Access_Writer $writer, private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The page, its handler, and the outcome notices.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_page' ) );
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		$loader->admin_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Adds the page under the plugin menu, behind the give-access capability.
	 */
	public function register_page(): void {
		add_submenu_page(
			Settings_Page::MENU_SLUG,
			__( 'Add Access', 'gated-media-access' ),
			__( 'Add Access', 'gated-media-access' ),
			Capabilities::give_access(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The form: user, item, duration.
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add Access', 'gated-media-access' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gatedmedia_user"><?php esc_html_e( 'User', 'gated-media-access' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_users(
								array(
									'name'             => 'gatedmedia_user',
									'id'               => 'gatedmedia_user',
									'show'             => 'display_name_with_login',
									'show_option_none' => __( '— Select a user —', 'gated-media-access' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gatedmedia_item_type"><?php esc_html_e( 'Item type', 'gated-media-access' ); ?></label></th>
						<td>
							<select name="gatedmedia_item_type" id="gatedmedia_item_type">
								<option value="group"><?php esc_html_e( 'Group', 'gated-media-access' ); ?></option>
								<option value="post"><?php esc_html_e( 'Post', 'gated-media-access' ); ?></option>
								<option value="file"><?php esc_html_e( 'File', 'gated-media-access' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gatedmedia_group"><?php esc_html_e( 'Group', 'gated-media-access' ); ?></label></th>
						<td>
							<select name="gatedmedia_group" id="gatedmedia_group">
								<option value=""><?php esc_html_e( '— Select a group —', 'gated-media-access' ); ?></option>
								<?php foreach ( $this->groups() as $uuid => $name ) : ?>
									<option value="<?php echo esc_attr( $uuid ); ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Used when the item type is Group.', 'gated-media-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gatedmedia_item_id"><?php esc_html_e( 'Post or file ID', 'gated-media-access' ); ?></label></th>
						<td>
							<input type="number" min="1" name="gatedmedia_item_id" id="gatedmedia_item_id" />
							<p class="description"><?php esc_html_e( 'Used when the item type is Post or File.', 'gated-media-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gatedmedia_duration"><?php esc_html_e( 'Duration (days)', 'gated-media-access' ); ?></label></th>
						<td>
							<input type="number" min="1" name="gatedmedia_duration" id="gatedmedia_duration" />
							<p class="description"><?php esc_html_e( 'Leave empty for lifetime access.', 'gated-media-access' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add Access', 'gated-media-access' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Guards the post, grants, and lands on the list — or back here on error.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body
	 * alongside the Location header (the `Profile_Writer::handle()` note).
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_die( esc_html__( 'You are not allowed to give access.', 'gated-media-access' ), '', 403 );
		}

		// Sanitised here, validated whole by the writer's Grant_Validator.
		$granted = $this->create(
			array(
				'user'      => isset( $_POST['gatedmedia_user'] ) ? absint( $_POST['gatedmedia_user'] ) : 0,
				'item_type' => isset( $_POST['gatedmedia_item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_item_type'] ) ) : '',
				'group'     => isset( $_POST['gatedmedia_group'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_group'] ) ) : '',
				'item_id'   => isset( $_POST['gatedmedia_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_item_id'] ) ) : '',
				'duration'  => isset( $_POST['gatedmedia_duration'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_duration'] ) ) : '',
			)
		);

		if ( $granted instanceof WP_Error ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'             => self::PAGE_SLUG,
						'gatedmedia_error' => rawurlencode( (string) $granted->get_error_code() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg( 'gatedmedia_granted', '1', admin_url( 'edit.php?post_type=' . Post_Types::ACCESS ) )
		);
		exit;
	}

	/**
	 * Turns the form's fields into one grant, through the writer.
	 *
	 * @param array{user: int, item_type: string, group: string, item_id: string, duration: string} $input The sanitised form values.
	 * @return int|WP_Error The new record, or what the writer refused.
	 */
	public function create( array $input ): int|WP_Error {
		$item_id  = 'group' === $input['item_type'] ? $input['group'] : $input['item_id'];
		$duration = '' === $input['duration'] ? null : absint( $input['duration'] );

		return $this->writer->grant(
			$input['user'],
			$input['item_type'],
			$item_id,
			$duration,
			'admin',
			'',
			array(),
			get_current_user_id()
		);
	}

	/**
	 * The outcome notices, on the list and on this form.
	 */
	public function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flags set by our own redirects.
		if ( isset( $_GET['gatedmedia_granted'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Access granted.', 'gated-media-access' ) );
		}

		if ( isset( $_GET['gatedmedia_revoked'] ) ) {
			echo '1' === sanitize_text_field( wp_unslash( $_GET['gatedmedia_revoked'] ) )
				? sprintf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Access revoked.', 'gated-media-access' ) )
				: sprintf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That access record could not be revoked.', 'gated-media-access' ) );
		}

		if ( isset( $_GET['gatedmedia_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'Access was not granted:', 'gated-media-access' ),
				esc_html( sanitize_text_field( wp_unslash( $_GET['gatedmedia_error'] ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Every group, UUID to name, for the form's select.
	 *
	 * @return array<string, string>
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

		$groups = array();

		foreach ( $terms as $term ) {
			$groups[ $this->taxonomy->uuid_for( $term->term_id ) ] = $term->name;
		}

		return $groups;
	}
}
