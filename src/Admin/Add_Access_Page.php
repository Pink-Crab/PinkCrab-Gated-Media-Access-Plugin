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
use PinkCrab\Gated_Access\Admin\Pickers\File_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Group_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Post_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\User_Picker;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * Adding access is picking a user, an item and a duration, and this page is that form and nothing else.
 *
 * The record itself is the writer's: the handler turns the request into `Access_Writer::grant()` arguments and reports what came back.
 *
 * The core editor never opens for an access record, because `create_posts` is do_not_allow, so this form is the only admin door in.
 */
class Add_Access_Page implements Hookable {

	/** The page's slug under the plugin menu. */
	public const PAGE_SLUG = 'gatedmedia-add-access';

	/** The admin-post action, and the nonce it carries. */
	public const ACTION = 'gatedmedia_add_access';

	/**
	 * Grants go through the writer, nothing else.
	 *
	 * @param Access_Writer $writer   The one writer of access records.
	 */
	public function __construct( private Access_Writer $writer ) {
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
	 * The form: user, item and duration, composed from the picker components.
	 *
	 * The item metabox links here pre-filled: its type and item arrive as GET args and become the pickers' initial values, printed server-side so the prefill stands without the script.
	 */
	public function render(): void {
		$prefill = $this->prefill();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add Access', 'gated-media-access' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gatedmedia_user_search"><?php esc_html_e( 'User', 'gated-media-access' ); ?></label></th>
						<td><?php ( new User_Picker( 'gatedmedia_user', 'gatedmedia_user' ) )->render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="gatedmedia_item_type"><?php esc_html_e( 'Item type', 'gated-media-access' ); ?></label></th>
						<td>
							<select name="gatedmedia_item_type" id="gatedmedia_item_type">
								<option value="group" <?php selected( $prefill['type'], 'group' ); ?>><?php esc_html_e( 'Group', 'gated-media-access' ); ?></option>
								<option value="post" <?php selected( $prefill['type'], 'post' ); ?>><?php esc_html_e( 'Post', 'gated-media-access' ); ?></option>
								<option value="file" <?php selected( $prefill['type'], 'file' ); ?>><?php esc_html_e( 'File', 'gated-media-access' ); ?></option>
							</select>
						</td>
					</tr>
					<tr data-gatedmedia-row="group">
						<th scope="row"><label for="gatedmedia_group_search"><?php esc_html_e( 'Group', 'gated-media-access' ); ?></label></th>
						<td><?php ( new Group_Picker( 'gatedmedia_group', 'gatedmedia_group' ) )->render(); ?></td>
					</tr>
					<tr data-gatedmedia-row="post">
						<th scope="row"><label for="gatedmedia_post_search"><?php esc_html_e( 'Post', 'gated-media-access' ); ?></label></th>
						<td>
							<?php
							( new Post_Picker(
								'gatedmedia_post',
								'gatedmedia_post',
								$prefill['post_id'],
								$prefill['post_title']
							) )->render();
							?>
						</td>
					</tr>
					<tr data-gatedmedia-row="file">
						<th scope="row"><label for="gatedmedia_file_search"><?php esc_html_e( 'File', 'gated-media-access' ); ?></label></th>
						<td>
							<?php
							( new File_Picker(
								'gatedmedia_file',
								'gatedmedia_file',
								$prefill['file_id'],
								$prefill['file_title']
							) )->render();
							?>
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
	 * Guards the post, grants, and lands on the list, or back here on error.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body alongside the Location header, as `Profile_Writer::handle()` also notes.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_die( esc_html__( 'You are not allowed to give access.', 'gated-media-access' ), '', 403 );
		}

		// Sanitised in submitted_input(), validated whole by the writer's Access_Validator.
		$granted = $this->create( $this->submitted_input() );

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
	 * The posted fields, sanitised. The nonce was checked before this reads.
	 *
	 * @return array{user: int, item_type: string, group: string, post: string, file: string, duration: string}
	 */
	private function submitted_input(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() ran check_admin_referer() before calling this.
		$text = static fn ( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

		return array(
			'user'      => isset( $_POST['gatedmedia_user'] ) ? absint( $_POST['gatedmedia_user'] ) : 0,
			'item_type' => $text( 'gatedmedia_item_type' ),
			'group'     => $text( 'gatedmedia_group' ),
			'post'      => $text( 'gatedmedia_post' ),
			'file'      => $text( 'gatedmedia_file' ),
			'duration'  => $text( 'gatedmedia_duration' ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * What the metabox link pre-fills: the item type, plus a post or file's id and current name, ready for the pickers.
	 *
	 * @return array{type: string, post_id: string, post_title: string, file_id: string, file_title: string}
	 */
	private function prefill(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only prefill from our own metabox link.
		$type = isset( $_GET['gatedmedia_type'] ) ? sanitize_text_field( wp_unslash( $_GET['gatedmedia_type'] ) ) : '';
		$item = isset( $_GET['gatedmedia_item'] ) ? absint( $_GET['gatedmedia_item'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$prefill = array(
			'type'       => $type,
			'post_id'    => '',
			'post_title' => '',
			'file_id'    => '',
			'file_title' => '',
		);

		if ( $item > 0 && in_array( $type, array( 'post', 'file' ), true ) ) {
			$prefill[ $type . '_id' ]    = (string) $item;
			$prefill[ $type . '_title' ] = (string) get_the_title( $item );
		}

		return $prefill;
	}

	/**
	 * Turns the form's fields into one grant, through the writer.
	 *
	 * @param array{user: int, item_type: string, group: string, post: string, file: string, duration: string} $input The sanitised form values.
	 * @return int|WP_Error The new record, or what the writer refused.
	 */
	public function create( array $input ): int|WP_Error {
		$item_id = match ( $input['item_type'] ) {
			'group' => $input['group'],
			'file'  => $input['file'],
			default => $input['post'],
		};

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
				esc_html( self::refusal( sanitize_key( wp_unslash( $_GET['gatedmedia_error'] ) ) ) )
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * What a refusal reads as, for a person.
	 *
	 * The redirect carries `WP_Error::get_error_code()`, a machine name, so an administrator was reading "Access was not granted: gatedmedia_invalid_user".
	 *
	 * The codes come from `Access_Validator`, and any it grows later falls through to the general wording rather than leaking its name onto the screen.
	 *
	 * @param string $code The error code the redirect carried.
	 */
	private static function refusal( string $code ): string {
		$messages = array(
			'gatedmedia_invalid_user'      => __( 'that user does not exist.', 'gated-media-access' ),
			'gatedmedia_invalid_item_type' => __( 'access is given to a file, post or group.', 'gated-media-access' ),
			'gatedmedia_invalid_duration'  => __( 'the duration is a number of days, or empty for lifetime.', 'gated-media-access' ),
			'gatedmedia_invalid_source'    => __( 'every record has to say where it came from.', 'gated-media-access' ),
			'gatedmedia_invalid_item'      => __( 'that item could not be found.', 'gated-media-access' ),
		);

		return $messages[ $code ] ?? __( 'something about it was not valid.', 'gated-media-access' );
	}
}
