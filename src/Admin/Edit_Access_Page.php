<?php
/**
 * The Edit Access page.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use DateTimeImmutable;
use DateTimeZone;
use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Support\View;

/**
 * Editing one access record: its expiry, and only its expiry.
 *
 * Holder, item and provenance are the record's identity, so changing who or what is a new grant, and a revoked record stays revoked. The change itself is `Access_Writer::set_expiry()`, and this page is a date field in front of it.
 *
 * Reached from the list's Edit row action, and registered under the plugin menu but hidden from it, because a visit with no record has nothing to edit.
 */
class Edit_Access_Page implements Hookable {

	/** The page's slug. */
	public const PAGE_SLUG = 'gatedmedia-edit-access';

	/** The admin-post action, and the nonce it carries. */
	public const ACTION = 'gatedmedia_edit_access';

	/**
	 * The summary rows render as the list renders them, and the change is the writer's.
	 *
	 * @param Access_Writer $writer      The one writer of access records.
	 * @param Access_List   $access_list The list screen's column renderers.
	 */
	public function __construct( private Access_Writer $writer, private Access_List $access_list ) {
	}

	/**
	 * The page, its handler, and the outcome notice.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'admin_menu', array( $this, 'register_page' ) );
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		$loader->admin_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Registers the page reachable but unlisted, because a menu entry with no record to edit is a dead click.
	 *
	 * An empty parent is core's hidden-page registration, and registering under the plugin menu then calling `remove_submenu_page()` only looks equivalent: the removal breaks `user_can_access_admin_page()` and every visit dies "not allowed".
	 */
	public function register_page(): void {
		add_submenu_page(
			'',
			__( 'Edit Access', 'gated-media-access' ),
			__( 'Edit Access', 'gated-media-access' ),
			Capabilities::give_access(),
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The URL the list's Edit action points at.
	 *
	 * @param int $access_id The record to edit.
	 */
	public static function url_for( int $access_id ): string {
		return add_query_arg(
			array(
				'page'   => self::PAGE_SLUG,
				'access' => $access_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The record's summary, and the one editable field.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display; the write handler carries the nonce.
		$access_id = isset( $_GET['access'] ) ? absint( $_GET['access'] ) : 0;
		$message   = $this->refusal( get_post( $access_id ) );
		$editable  = '' === $message;

		View::render(
			'admin/edit-access',
			array(
				'message'   => $message,
				'access_id' => $access_id,
				'action'    => self::ACTION,
				'form_url'  => admin_url( 'admin-post.php' ),
				'expires'   => $editable ? $this->local_expiry( $access_id ) : '',
				'summary'   => $editable ? $this->summary( $access_id ) : array(),
			)
		);
	}

	/**
	 * Why this record cannot be edited, or '' when it can.
	 *
	 * @param WP_Post|null $record What the `access` argument named.
	 */
	private function refusal( ?WP_Post $record ): string {
		if ( ! $record instanceof WP_Post || Post_Types::ACCESS !== $record->post_type ) {
			return __( 'No access record to edit. Pick one from the Access screen.', 'gated-media-access' );
		}

		if ( Post_Types::STATUS_REVOKED === $record->post_status ) {
			return __( 'This record is revoked and stays that way. Grant access again from the Add Access screen instead.', 'gated-media-access' );
		}

		return '';
	}

	/**
	 * Guards the post, reschedules, and returns to the list.
	 *
	 * The `exit` is required: a redirect that does not halt emits a body alongside the Location header, as `Profile_Writer::handle()` also notes.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The id names which nonce to check; check_admin_referer runs on the next line.
		$access_id = isset( $_POST['access'] ) ? absint( $_POST['access'] ) : 0;

		check_admin_referer( self::ACTION . '_' . $access_id );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_die( esc_html__( 'You are not allowed to edit access.', 'gated-media-access' ), '', 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$expires = isset( $_POST['gatedmedia_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['gatedmedia_expires'] ) ) : '';

		$updated = $this->apply( $access_id, $expires );

		wp_safe_redirect(
			add_query_arg( 'gatedmedia_updated', $updated ? '1' : '0', admin_url( 'edit.php?post_type=' . Post_Types::ACCESS ) )
		);
		exit;
	}

	/**
	 * Turns the form's site-timezone datetime into the writer's UTC one.
	 *
	 * @param int    $access_id The record to reschedule.
	 * @param string $local     `Y-m-d\TH:i` in the site's timezone, '' for lifetime.
	 */
	public function apply( int $access_id, string $local ): bool {
		if ( '' === $local ) {
			return $this->writer->set_expiry( $access_id, null );
		}

		try {
			$utc = ( new DateTimeImmutable( $local, wp_timezone() ) )
				->setTimezone( new DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
		} catch ( \Exception ) {
			return false;
		}

		return $this->writer->set_expiry( $access_id, $utc );
	}

	/**
	 * The outcome notice, from the redirect flag.
	 */
	public function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect.
		if ( ! isset( $_GET['gatedmedia_updated'] ) ) {
			return;
		}

		echo '1' === sanitize_text_field( wp_unslash( $_GET['gatedmedia_updated'] ) )
			? sprintf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Access updated.', 'gated-media-access' ) )
			: sprintf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'That access record could not be updated.', 'gated-media-access' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * What the record is, label to markup, using the list's own cells.
	 *
	 * `Access_List::render_column()` prints, so each cell is captured rather than returned.
	 *
	 * @param int $access_id The record.
	 * @return array<string, string>
	 */
	private function summary( int $access_id ): array {
		$columns = array(
			'gatedmedia_holder' => __( 'Holder', 'gated-media-access' ),
			'gatedmedia_item'   => __( 'Item', 'gated-media-access' ),
			'gatedmedia_status' => __( 'Status', 'gated-media-access' ),
			'gatedmedia_source' => __( 'Source', 'gated-media-access' ),
		);

		$summary = array();

		foreach ( $columns as $column => $label ) {
			ob_start();
			$this->access_list->render_column( $column, $access_id );
			$summary[ $label ] = (string) ob_get_clean();
		}

		return $summary;
	}

	/**
	 * The stored expiry as the field wants it, in the site's timezone.
	 *
	 * @param int $access_id The record.
	 */
	private function local_expiry( int $access_id ): string {
		$stored = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

		return '' === $stored
			? ''
			: ( new DateTimeImmutable( $stored, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	}
}
