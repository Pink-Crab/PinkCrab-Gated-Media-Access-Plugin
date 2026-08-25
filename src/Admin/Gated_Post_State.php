<?php
/**
 * Showing which posts are gated, in the list they are listed in.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * A post state rather than a column of its own.
 *
 * `display_post_states` is where WordPress puts exactly this information —
 * Draft, Private, Sticky, Password protected all appear beside the title, and
 * an editor already reads that spot to find out what a row is. A column would
 * add a header to every post type on the site to say "no" on almost every row.
 *
 * The status also earns its own filter link with a count at the top of the
 * list, which comes from `show_in_admin_status_list` on the registration and
 * needs nothing here.
 */
class Gated_Post_State implements Hookable {

	/**
	 * The one filter.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_filter( 'display_post_states', array( $this, 'add_state' ), 2 );
	}

	/**
	 * Names a gated post in the list.
	 *
	 * Core already prints the status label for a post whose status is not the
	 * one being filtered on, so this only adds the label when it would
	 * otherwise be missing — belt and braces against the row reading as an
	 * ordinary published post.
	 *
	 * @param array<string, string> $states The states core is showing.
	 * @param mixed                 $post   The post being listed.
	 * @return array<string, string>
	 */
	public function add_state( array $states, mixed $post ): array {
		$post = $post instanceof WP_Post ? $post : get_post( $post );

		if ( ! $post instanceof WP_Post || Post_Types::STATUS_GATED !== $post->post_status ) {
			return $states;
		}

		$states[ Post_Types::STATUS_GATED ] = __( 'Gated access', 'gated-media-access' );

		return $states;
	}
}
