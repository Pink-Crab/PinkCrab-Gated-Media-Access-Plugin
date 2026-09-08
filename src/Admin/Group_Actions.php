<?php
/**
 * What the Groups screen's forms post to.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * The writes behind `Groups_Page`: create a group, rename one, and move an item in or out of one.
 *
 * Its own class rather than more methods on the screen, which was over phpmd's class-complexity ceiling with both halves in it. The screen draws, this writes.
 *
 * **Adding an item goes through `wp_set_object_terms`**, exactly as the item's own Access panel does from the other end.
 *
 * That is the whole point: a group filled from this side has to mean what a group filled from the item's side means, so `Restriction`'s marker applies either way and restriction is not special-cased here.
 */
class Group_Actions implements Hookable {

	/** Creating a group, and the nonce it demands. */
	public const CREATE_ACTION = 'gatedmedia_create_group';

	/** Saving one group's name and description. */
	public const SAVE_ACTION = 'gatedmedia_save_group';

	/** Adding an item to a group, or taking one out. */
	public const ITEM_ACTION = 'gatedmedia_group_item';

	/**
	 * Resolves a group from its UUID.
	 *
	 * @param Access_Taxonomy $taxonomy Owns the group lookup.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The three handlers.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle_create' ) );
		$loader->action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		$loader->action( 'admin_post_' . self::ITEM_ACTION, array( $this, 'handle_item' ) );
	}

	/**
	 * Creates a group and comes back to the list.
	 */
	public function handle_create(): void {
		check_admin_referer( self::CREATE_ACTION );
		$this->guard();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$name = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';

		if ( '' === $name ) {
			$this->leave_list( 'empty' );
			return;
		}

		$created = wp_insert_term( $name, Access_Taxonomy::TAXONOMY );

		$this->leave_list( is_wp_error( $created ) ? 'exists' : 'created' );
	}

	/**
	 * Renames a group, or rewrites its description.
	 */
	public function handle_save(): void {
		check_admin_referer( self::SAVE_ACTION );
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$uuid        = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		$name        = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
		$description = isset( $_POST['group_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['group_description'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$group = $this->taxonomy->find_group( $uuid );

		if ( ! $group instanceof WP_Term || '' === $name ) {
			$this->leave( $uuid, 'empty' );
			return;
		}

		$updated = wp_update_term(
			(int) $group->term_id,
			Access_Taxonomy::TAXONOMY,
			array(
				'name'        => $name,
				'description' => $description,
			)
		);

		$this->leave( $uuid, is_wp_error( $updated ) ? 'exists' : 'saved' );
	}

	/**
	 * Puts an item in a group, or takes it out.
	 */
	public function handle_item(): void {
		check_admin_referer( self::ITEM_ACTION );
		$this->guard();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran above.
		$uuid    = isset( $_POST['group'] ) ? sanitize_text_field( wp_unslash( $_POST['group'] ) ) : '';
		$item_id = isset( $_POST['item'] ) ? absint( wp_unslash( $_POST['item'] ) ) : 0;
		$remove  = isset( $_POST['op'] ) && 'remove' === sanitize_key( wp_unslash( $_POST['op'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$group = $this->taxonomy->find_group( $uuid );

		if ( ! $group instanceof WP_Term || 0 === $item_id || ! get_post( $item_id ) instanceof WP_Post ) {
			$this->leave( $uuid, 'no-item' );
			return;
		}

		if ( $remove ) {
			wp_remove_object_terms( $item_id, array( (int) $group->term_id ), Access_Taxonomy::TAXONOMY );
			$this->leave( $uuid, 'removed' );
			return;
		}

		wp_set_object_terms( $item_id, array( (int) $group->term_id ), Access_Taxonomy::TAXONOMY, true );

		$this->leave( $uuid, 'added' );
	}

	/**
	 * Refuses anybody who may not manage groups.
	 */
	private function guard(): void {
		if ( ! current_user_can( Capabilities::manage_settings() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage groups.', 'gated-media-access' ), '', 403 );
		}
	}

	/**
	 * Back to the group, saying what happened.
	 *
	 * @param string $uuid   The group.
	 * @param string $notice What to say.
	 */
	private function leave( string $uuid, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'gatedmedia_notice', $notice, Groups_Page::url_for( $uuid ) ) );
		exit;
	}

	/**
	 * Back to the list, saying what happened.
	 *
	 * @param string $notice What to say.
	 */
	private function leave_list( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'gatedmedia_notice', $notice, Groups_Page::url() ) );
		exit;
	}
}
