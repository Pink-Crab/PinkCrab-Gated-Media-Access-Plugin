<?php
/**
 * The pickers' search endpoints.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_User;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * What the search pickers type against: three admin-ajax endpoints — users,
 * posts, files — signed-in only, behind the give-access capability and a
 * nonce. Search, nothing else: no record is read or written here.
 */
class Picker_Search implements Hookable {

	/** The nonce action both endpoints check, shared with the JS. */
	public const NONCE = 'gatedmedia_picker';

	/** How many matches a picker shows. */
	private const LIMIT = 20;

	/**
	 * Both endpoints, private ajax only.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->ajax( 'gatedmedia_search_users', array( $this, 'search_users' ), false, true );
		$loader->ajax( 'gatedmedia_search_posts', array( $this, 'search_posts' ), false, true );
		$loader->ajax( 'gatedmedia_search_files', array( $this, 'search_files' ), false, true );
	}

	/**
	 * The users endpoint.
	 */
	public function search_users(): void {
		wp_send_json( $this->find_users( $this->guarded_term() ) );
	}

	/**
	 * The posts endpoint.
	 */
	public function search_posts(): void {
		wp_send_json( $this->find_posts( $this->guarded_term() ) );
	}

	/**
	 * The files endpoint.
	 */
	public function search_files(): void {
		wp_send_json( $this->find_files( $this->guarded_term() ) );
	}

	/**
	 * Users matching the term, by login, email or name.
	 *
	 * @param string $term What was typed.
	 * @return array<int, array{id: int, label: string}>
	 */
	public function find_users( string $term ): array {
		$users = ( new \WP_User_Query(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => self::LIMIT,
				'fields'         => 'all',
			)
		) )->get_results();

		return array_map(
			static fn ( WP_User $user ): array => array(
				'id'    => $user->ID,
				'label' => sprintf( '%s (%s)', $user->display_name, $user->user_email ),
			),
			$users
		);
	}

	/**
	 * Published posts matching the term — the restrictable types, files
	 * excluded (`gatedmedia_search_files` is theirs).
	 *
	 * @param string $term What was typed.
	 * @return array<int, array{id: int, label: string}>
	 */
	public function find_posts( string $term ): array {
		$found = get_posts(
			array(
				'post_type'      => array_values( array_diff( Access_Taxonomy::object_types(), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				's'              => $term,
				'posts_per_page' => self::LIMIT,
				'no_found_rows'  => true,
			)
		);

		return array_map(
			static fn ( \WP_Post $post ): array => array(
				'id'    => $post->ID,
				'label' => sprintf( '%s (%s)', get_the_title( $post ), $post->post_type ),
			),
			$found
		);
	}

	/**
	 * Attachments matching the term — an attachment is a post, so the file
	 * picker is this search rather than a media modal.
	 *
	 * @param string $term What was typed.
	 * @return array<int, array{id: int, label: string}>
	 */
	public function find_files( string $term ): array {
		$found = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				's'              => $term,
				'posts_per_page' => self::LIMIT,
				'no_found_rows'  => true,
			)
		);

		return array_map(
			static fn ( \WP_Post $file ): array => array(
				'id'    => $file->ID,
				'label' => sprintf( '%s (%s)', get_the_title( $file ), $file->post_mime_type ),
			),
			$found
		);
	}

	/**
	 * The search term, once the nonce and capability have been checked.
	 *
	 * `wp_die` on failure is admin-ajax's own refusal shape.
	 */
	private function guarded_term(): string {
		check_ajax_referer( self::NONCE );

		if ( ! current_user_can( Capabilities::give_access() ) ) {
			wp_send_json( array(), 403 );
		}

		return isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	}
}
