<?php
/**
 * The post boundary.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use WP_Post;
use WP_Query;
use WP_Error;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * A restricted post with no access is a hard 404 with no clues, absent from archives, search, REST and sitemaps, while the restricted posts a person does hold stay visible everywhere.
 *
 * The exclusion is "not restricted, or one of these IDs": one term lookup for the marker's objects, the resolver's allowed items for the holder's IDs, and the difference lands in `post__not_in` on every front-of-site query. The 404 and the REST refusal guard the two ways of asking for a post by name.
 *
 * Known edge, accepted: WP_Query ignores `post__not_in` when `p` or `post__in` is set. Singular requests are re-caught on `wp` and REST items at rest_prepare, so an explicit-ID listing is the one surface that can still list a blocked post.
 */
class Post_Boundary implements Hookable {

	/**
	 * True while the blocked set is being computed.
	 *
	 * The resolver's own record query runs through WP_Query, so pre_get_posts re-enters this class mid-build and without the guard that recursion never bottoms out.
	 *
	 * @var bool
	 */
	private bool $building = false;

	/**
	 * Access decisions come from the resolver, the marker term from `Restriction`.
	 *
	 * @param Resolver    $resolver    The last word on who sees what.
	 * @param Restriction $restriction Owns the marker term.
	 */
	public function __construct( private Resolver $resolver, private Restriction $restriction ) {
	}

	/**
	 * Listings, the singular 404, and the REST refusals.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'pre_get_posts', array( $this, 'exclude_from_queries' ) );
		// On `wp`, not template_redirect: that one is skipped where the theme layer is off, and template-loader.php serves feeds either way. Still ahead of redirect_canonical.
		$loader->action( 'wp', array( $this, 'refuse_singular' ), 1, 0 );
		// After the taxonomy registers, since its object types name the hooks to guard.
		$loader->action( 'init', array( $this, 'attach_rest_refusals' ), 1, 20 );
	}

	/**
	 * Keeps blocked posts out of every front-of-site listing query.
	 *
	 * Singular queries pass through, because the template_redirect 404 owns those and `post__not_in` is ignored against `p` anyway.
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public function exclude_from_queries( WP_Query $query ): void {
		if ( $query->is_singular() || $this->is_own_admin_request() ) {
			return;
		}

		$blocked = $this->blocked_ids();

		if ( array() === $blocked ) {
			return;
		}

		$existing = $query->get( 'post__not_in' );
		$existing = is_array( $existing ) ? array_map( 'intval', $existing ) : array();

		$query->set( 'post__not_in', array_values( array_unique( array_merge( $existing, $blocked ) ) ) );
	}

	/**
	 * Our own admin screens. `is_admin()` alone is not that question, because it is true for admin-ajax too, `wp_ajax_nopriv_*` included.
	 */
	private function is_own_admin_request(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		return ! wp_doing_ajax() || current_user_can( Capabilities::give_access() );
	}

	/**
	 * A blocked post asked for by name is a hard 404, with no clues.
	 */
	public function refuse_singular(): void {
		if ( ! is_singular() ) {
			return;
		}

		$queried = get_queried_object();

		if ( ! $queried instanceof WP_Post || ! $this->is_blocked( (int) $queried->ID ) ) {
			return;
		}

		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		// The flags are not the refusal: set_404() keeps is_feed, and template-loader.php runs do_feed() before it looks at is_404, so the fetched post has to leave the loop as well.
		$wp_query->posts        = array();
		$wp_query->post_count   = 0;
		$wp_query->found_posts  = 0;
		$wp_query->current_post = -1;
		$wp_query->post         = null;

		// Or redirect_canonical 301s the 404 to the pretty slug, which is a clue.
		add_filter( 'redirect_canonical', '__return_false' );
	}

	/**
	 * Guards single-item REST responses for every restrictable type.
	 */
	public function attach_rest_refusals(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		if ( false === $taxonomy ) {
			return;
		}

		foreach ( $taxonomy->object_type as $object_type ) {
			add_filter( 'rest_prepare_' . $object_type, array( $this, 'refuse_rest_item' ), 10, 2 );
		}
	}

	/**
	 * Answers for a blocked post exactly as REST answers for a post that does not exist.
	 *
	 * @param mixed $response The prepared response.
	 * @param mixed $post     The post being prepared.
	 * @return mixed The response, or core's own invalid-ID error.
	 */
	public function refuse_rest_item( $response, $post ) {
		// Somebody who may edit the post is the person who restricted it, and without this the block editor 404s on its own content.
		if ( $post instanceof WP_Post && current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $response;
		}

		if ( $post instanceof WP_Post && $this->is_blocked( (int) $post->ID ) ) {
			// Converted, not raw: the controller calls link_header() on this.
			return rest_convert_error_to_response(
				new WP_Error(
					'rest_post_invalid_id',
					__( 'Invalid post ID.', 'gated-media-access' ),
					array( 'status' => 404 )
				)
			);
		}

		return $response;
	}

	/**
	 * Restricted, and not among what this user holds.
	 *
	 * @param int $post_id The post being asked about.
	 */
	private function is_blocked( int $post_id ): bool {
		return in_array( $post_id, $this->blocked_ids(), true );
	}

	/**
	 * The marker's objects minus the current user's allowed items.
	 *
	 * Computed per call, not memoised. Core caches the term lookup and the resolver memoises the allowed items, so a repeat costs no queries, and a stale cache here would outlive test transactions.
	 *
	 * @return array<int>
	 */
	private function blocked_ids(): array {
		// The resolver's own build queries stand unfiltered, never being content.
		if ( $this->building ) {
			return array();
		}

		$marker = $this->restriction->marker();

		if ( null === $marker ) {
			return array();
		}

		$objects = get_objects_in_term( (int) $marker->term_id, Access_Taxonomy::TAXONOMY );

		if ( ! is_array( $objects ) ) {
			return array();
		}

		$this->building = true;

		try {
			$items = $this->resolver->allowed_for( get_current_user_id() );
		} finally {
			$this->building = false;
		}

		$blocked = array();

		foreach ( array_map( 'intval', $objects ) as $object_id ) {
			if ( ! $items->has_post( $object_id ) && ! $items->has_file( $object_id ) ) {
				$blocked[] = $object_id;
			}
		}

		return $blocked;
	}
}
