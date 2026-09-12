<?php
/**
 * Granting access from quick edit.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Admin\Pickers\User_Picker;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\View;

/**
 * An Access column on the restrictable list tables, and a grant inside its quick edit: pick a user, give days or lifetime, save the row.
 *
 * Quick edit renders one template row with no per-row state, so this box only adds. The holders themselves live on the item's metabox and the Access screen.
 *
 * The save is core's inline save, and the record still comes from `Access_Writer::grant()`, keyed off our own nonced fields that a plain editor save does not carry.
 *
 * Attachments are out, because the media list has no quick edit, and their door is the metabox on the attachment edit screen.
 */
class Quick_Edit_Grant implements Hookable {

	/** The column key, and the quick edit box it unlocks. */
	public const COLUMN = 'gatedmedia_access';

	/** The nonce action marking an inline save that carries a grant. */
	public const NONCE = 'gatedmedia_quick_grant';

	/**
	 * Holder counts for the rows already looked up, keyed by post id.
	 *
	 * @var array<int, int>
	 */
	private array $counts = array();

	/**
	 * Grants go through the writer, nothing else.
	 *
	 * @param Access_Writer $writer The one writer of access records.
	 */
	public function __construct( private Access_Writer $writer ) {
	}

	/**
	 * The column, its cells, the quick edit box, and the save.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		foreach ( $this->list_types() as $type ) {
			$loader->admin_filter( 'manage_' . $type . '_posts_columns', array( $this, 'column' ) );
			$loader->admin_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'render_column' ), 2 );
		}

		$loader->admin_action( 'quick_edit_custom_box', array( $this, 'render_quick_edit' ), 2 );
		$loader->action( 'save_post', array( $this, 'save' ) );
	}

	/**
	 * The types whose list tables carry the column: restrictable, minus attachments, whose media list has no quick edit.
	 *
	 * @return array<int, string>
	 */
	public function list_types(): array {
		return array_values( array_diff( Access_Taxonomy::object_types(), array( 'attachment' ) ) );
	}

	/**
	 * Adds the Access column.
	 *
	 * @param array<string, string> $columns The list's columns.
	 * @return array<string, string>
	 */
	public function column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Access', 'gated-media-access' );

		return $columns;
	}

	/**
	 * How many people hold the row's item directly.
	 *
	 * @param string $column  Which column.
	 * @param int    $post_id The row.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$count = $this->holder_count( $post_id );

		echo esc_html(
			0 === $count
				? __( '-', 'gated-media-access' )
				/* translators: %d: how many users hold access. */
				: sprintf( _n( '%d holder', '%d holders', $count, 'gated-media-access' ), $count )
		);
	}

	/**
	 * The grant fields inside quick edit, for those who may give access.
	 *
	 * @param string $column    The column the box belongs to.
	 * @param string $post_type The list's type.
	 */
	public function render_quick_edit( string $column, string $post_type ): void {
		if ( self::COLUMN !== $column || ! in_array( $post_type, $this->list_types(), true ) || ! current_user_can( Capabilities::give_access() ) ) {
			return;
		}

		wp_nonce_field( self::NONCE, self::NONCE, false );

		View::render(
			'admin/quick-edit-grant',
			array( 'picker' => new User_Picker( 'gatedmedia_qe_user', 'gatedmedia_qe_user' ) )
		);
	}

	/**
	 * Grants from an inline save that carries our fields.
	 *
	 * Runs on save_post but writes nothing itself. The record is the writer's, and only when the nonced quick edit actually picked a user.
	 *
	 * @param int $post_id The row saved.
	 */
	public function save( int $post_id ): void {
		if ( ! $this->carries_a_grant( $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- carries_a_grant() verified the nonce on the line above.
		$user_id  = isset( $_POST['gatedmedia_qe_user'] ) ? absint( $_POST['gatedmedia_qe_user'] ) : 0;
		$duration = isset( $_POST['gatedmedia_qe_duration'] ) ? absint( $_POST['gatedmedia_qe_duration'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 0 === $user_id ) {
			return;
		}

		$this->writer->grant(
			$user_id,
			'post',
			(string) $post_id,
			0 === $duration ? null : $duration,
			'admin',
			'',
			array(),
			get_current_user_id()
		);
	}

	/**
	 * Whether this is a nonced quick edit of a listed type, by someone who may give access, and a real save rather than a revision or autosave.
	 *
	 * @param int $post_id The row saved.
	 */
	private function carries_a_grant( int $post_id ): bool {
		$nonce = isset( $_POST[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ) : '';

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE ) ) {
			return false;
		}

		if ( ! current_user_can( Capabilities::give_access() ) || false !== wp_is_post_revision( $post_id ) || false !== wp_is_post_autosave( $post_id ) ) {
			return false;
		}

		return in_array( get_post_type( $post_id ), $this->list_types(), true );
	}

	/**
	 * How many holders one row has, looking up the whole screen at once.
	 *
	 * @param int $post_id The row being drawn.
	 */
	private function holder_count( int $post_id ): int {
		$rows = $this->rows_on_screen();

		// Only the table's own rows are cached, and only for the pass that draws them, so anything else is answered directly rather than from a memo a write could have made stale.
		if ( ! in_array( $post_id, $rows, true ) ) {
			return $this->look_up( array( $post_id ) )[ $post_id ] ?? 0;
		}

		if ( array() === $this->counts ) {
			$this->counts = $this->look_up( $rows );
		}

		return $this->counts[ $post_id ] ?? 0;
	}

	/**
	 * Counts every given row in one query, zero included.
	 *
	 * @param array<int, int> $rows The post ids to answer for.
	 * @return array<int, int>
	 */
	private function look_up( array $rows ): array {
		$counts = array();

		foreach ( $rows as $row_id ) {
			$counts[ $row_id ] = 0;
		}

		$records = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				// Named, never 'any', which excludes ours.
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One query for the whole screen; direct records only.
				'meta_query'     => array(
					array(
						'key'   => Access_Writer::META_ITEM_TYPE,
						'value' => 'post',
					),
					array(
						'key'     => Access_Writer::META_ITEM_ID,
						'value'   => array_map( 'strval', $rows ),
						'compare' => 'IN',
					),
				),
			)
		);

		update_postmeta_cache( array_map( 'intval', $records ) );

		foreach ( array_map( 'intval', $records ) as $record_id ) {
			$item_id = (int) get_post_meta( $record_id, Access_Writer::META_ITEM_ID, true );

			$counts[ $item_id ] = ( $counts[ $item_id ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * The post ids the list table is drawing.
	 *
	 * @return array<int, int>
	 */
	private function rows_on_screen(): array {
		$query = $GLOBALS['wp_query'] ?? null;
		$posts = $query instanceof \WP_Query ? $query->posts : array();

		return array_values(
			array_filter(
				array_map(
					static fn ( $post ): int => $post instanceof \WP_Post ? (int) $post->ID : (int) $post,
					is_array( $posts ) ? $posts : array()
				),
				static fn ( int $post_id ): bool => $post_id > 0
			)
		);
	}
}
