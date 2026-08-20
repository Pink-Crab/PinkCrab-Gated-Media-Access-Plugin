<?php
/**
 * The Access list screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use WP_Query;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Shapes the core list table for access records: who has what, its status,
 * when it expires, where it came from (architecture.md §9).
 *
 * The screen itself is core's — search, pagination, the status views all come
 * with the post type. This class only supplies the columns and strips the row
 * actions core would offer, because a record is pure data: nothing edits one
 * directly, everything goes through `Access_Writer`.
 */
class Access_List implements Hookable {

	/**
	 * Item names resolve groups through the taxonomy's UUID identity.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID into its term.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * Columns, sorting and row actions, admin side only.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_filter( 'manage_' . Post_Types::ACCESS . '_posts_columns', array( $this, 'columns' ) );
		$loader->admin_action( 'manage_' . Post_Types::ACCESS . '_posts_custom_column', array( $this, 'render_column' ), 2 );
		$loader->admin_filter( 'manage_edit-' . Post_Types::ACCESS . '_sortable_columns', array( $this, 'sortable_columns' ) );
		$loader->admin_action( 'pre_get_posts', array( $this, 'sort_by_expiry' ) );
		$loader->admin_filter( 'post_row_actions', array( $this, 'row_actions' ), 2 );
	}

	/**
	 * The list's columns, whole: nothing of core's default set applies.
	 *
	 * @param array<string, string> $columns Core's columns, discarded.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return array(
			'gatedmedia_holder' => __( 'Holder', 'gated-media-access' ),
			'gatedmedia_item'   => __( 'Item', 'gated-media-access' ),
			'gatedmedia_status' => __( 'Status', 'gated-media-access' ),
			'gatedmedia_expiry' => __( 'Expires', 'gated-media-access' ),
			'gatedmedia_source' => __( 'Source', 'gated-media-access' ),
		);
	}

	/**
	 * One cell.
	 *
	 * @param string $column  Which column.
	 * @param int    $post_id The access record.
	 */
	public function render_column( string $column, int $post_id ): void {
		echo wp_kses_post( $this->column_value( $column, $post_id ) );
	}

	/**
	 * Expiry sorts on its meta; holder rides core's author orderby.
	 *
	 * @param array<string, string> $columns The sortable set.
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['gatedmedia_holder'] = 'author';
		$columns['gatedmedia_expiry'] = 'gatedmedia_expiry';

		return $columns;
	}

	/**
	 * Turns the expiry column's orderby into a meta sort.
	 *
	 * Admin list query only — the front never orders by our meta. Every record
	 * carries the key ('' for lifetime), so the join drops no rows.
	 *
	 * @param WP_Query $query The list query.
	 */
	public function sort_by_expiry( WP_Query $query ): void {
		if ( ! $query->is_main_query() || Post_Types::ACCESS !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'gatedmedia_expiry' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Access_Writer::META_EXPIRES_AT );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Revoke is the one row action — a record is never edited, only put
	 * through the writer, and revoking a record already expired or revoked
	 * withdraws nothing.
	 *
	 * @param array<string, string> $actions Core's actions, discarded.
	 * @param WP_Post               $post    The row's record.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, WP_Post $post ): array {
		if ( Post_Types::ACCESS !== $post->post_type ) {
			return $actions;
		}

		if ( Post_Types::STATUS_ACTIVE !== $post->post_status || ! current_user_can( Capabilities::give_access() ) ) {
			return array();
		}

		return array(
			'gatedmedia_revoke' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( Revoke_Action::url_for( (int) $post->ID ) ),
				esc_html__( 'Revoke', 'gated-media-access' )
			),
		);
	}

	/**
	 * The value a column shows for one record.
	 *
	 * @param string $column  Which column.
	 * @param int    $post_id The access record.
	 */
	private function column_value( string $column, int $post_id ): string {
		return match ( $column ) {
			'gatedmedia_holder' => $this->holder( $post_id ),
			'gatedmedia_item'   => $this->item( $post_id ),
			'gatedmedia_status' => $this->status( $post_id ),
			'gatedmedia_expiry' => $this->expiry( $post_id ),
			'gatedmedia_source' => esc_html( (string) get_post_meta( $post_id, Access_Writer::META_SOURCE, true ) ),
			default             => '',
		};
	}

	/**
	 * The user holding the record, linked to their profile.
	 *
	 * @param int $post_id The access record.
	 */
	private function holder( int $post_id ): string {
		$record = get_post( $post_id );
		$user   = null === $record ? false : get_userdata( (int) $record->post_author );

		if ( false === $user ) {
			return esc_html__( 'Unknown user', 'gated-media-access' );
		}

		return sprintf(
			'<a href="%s">%s</a> <span class="description">%s</span>',
			esc_url( get_edit_user_link( $user->ID ) ),
			esc_html( $user->display_name ),
			esc_html( $user->user_email )
		);
	}

	/**
	 * What the record points at: its type, and the target's current name.
	 *
	 * A record outlives its target (requirements.md) — a missing target still
	 * renders, marked removed.
	 *
	 * @param int $post_id The access record.
	 */
	private function item( int $post_id ): string {
		$item_type = (string) get_post_meta( $post_id, Access_Writer::META_ITEM_TYPE, true );
		$item_id   = (string) get_post_meta( $post_id, Access_Writer::META_ITEM_ID, true );

		$type_label = match ( $item_type ) {
			'file'  => __( 'File', 'gated-media-access' ),
			'post'  => __( 'Post', 'gated-media-access' ),
			'group' => __( 'Group', 'gated-media-access' ),
			default => $item_type,
		};

		if ( 'group' === $item_type ) {
			$term = $this->taxonomy->find_group( $item_id );
			$name = null === $term ? null : $term->name;
		} else {
			$target = get_post( (int) $item_id );
			$name   = null === $target ? null : get_the_title( $target );
		}

		if ( null === $name ) {
			/* translators: %s: the item type — file, post or group. */
			return esc_html( sprintf( __( '%s (removed)', 'gated-media-access' ), $type_label ) );
		}

		return sprintf( '%s: %s', esc_html( $type_label ), esc_html( $name ) );
	}

	/**
	 * The registered status label, falling back to the raw status.
	 *
	 * @param int $post_id The access record.
	 */
	private function status( int $post_id ): string {
		$status = get_post_status( $post_id );
		$object = false === $status ? null : get_post_status_object( $status );

		return esc_html( null === $object ? (string) $status : $object->label );
	}

	/**
	 * The expiry as the site formats dates, or "Lifetime" for none.
	 *
	 * @param int $post_id The access record.
	 */
	private function expiry( int $post_id ): string {
		$expires = (string) get_post_meta( $post_id, Access_Writer::META_EXPIRES_AT, true );

		if ( '' === $expires ) {
			return esc_html__( 'Lifetime', 'gated-media-access' );
		}

		// Stored UTC; shown in the site's timezone and date format.
		return esc_html(
			(string) wp_date(
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
				(int) strtotime( $expires . ' +0000' )
			)
		);
	}
}
