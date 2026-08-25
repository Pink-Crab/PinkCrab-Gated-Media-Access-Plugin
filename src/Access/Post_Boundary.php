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

/**
 * A restricted post with no access is a hard 404 with no clues, absent from
 * archives, search, REST and sitemaps — while the restricted posts a person
 * does hold stay visible everywhere (architecture.md §6).
 *
 * The exclusion is the architecture's "not restricted, or one of these IDs":
 * one term lookup for the marker's objects, the resolver's allowed items for
 * the holder's IDs, and the difference lands in `post__not_in` on every
 * front-of-site query. That covers listings, feeds and sitemaps in one move;
 * the 404 and the REST refusal guard the two ways of asking for a post by
 * name.
 *
 * Known edge, accepted: WP_Query ignores `post__not_in` when `p` or
 * `post__in` is set (class-wp-query.php's elseif chain). Singular requests
 * are re-caught at template_redirect and REST items at rest_prepare, so an
 * explicit-ID listing is the one surface that can still list a blocked post.
 */
class Post_Boundary implements Hookable {

	/**
	 * True while the blocked set is being computed.
	 *
	 * The resolver's own record query runs through WP_Query, so pre_get_posts
	 * re-enters this class mid-build; without the guard that recursion never
	 * bottoms out. (The resolver memoises only once a build completes.)
	 *
	 * @var bool
	 */
	private bool $building = false;

	/**
	 * Access decisions come from the one resolver; the marker term from the
	 * restriction wiring.
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
		// Before redirect_canonical (priority 10), or a guessed ?p=ID would
		// redirect to the pretty slug — a clue — before the 404 lands.
		$loader->action( 'template_redirect', array( $this, 'refuse_singular' ), 1, 0 );
		// After the taxonomy registers (init 10): its object types name the
		// rest_prepare_{type} hooks to guard.
		$loader->action( 'init', array( $this, 'attach_rest_refusals' ), 1, 20 );
	}

	/**
	 * Keeps blocked posts out of every front-of-site listing query.
	 *
	 * Singular queries pass through — the template_redirect 404 owns those,
	 * and `post__not_in` would be ignored against `p` anyway.
	 *
	 * @param WP_Query $query The query being prepared.
	 */
	public function exclude_from_queries( WP_Query $query ): void {
		if ( is_admin() || $query->is_singular() ) {
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
	 * A blocked post asked for by name is a hard 404 — no clues.
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
	 * Answers for a blocked post exactly as REST answers for a post that
	 * does not exist.
	 *
	 * @param mixed $response The prepared response.
	 * @param mixed $post     The post being prepared.
	 * @return mixed The response, or core's own invalid-ID error.
	 */
	public function refuse_rest_item( $response, $post ) {
		// Somebody who may edit the post is not a visitor being refused entry
		// — they are the person who restricted it. Without this exemption the
		// block editor, which loads and saves every post over REST, answers 404
		// for restricted content and an administrator locks themselves out of
		// their own post the moment they gate it. The front end is untouched:
		// `refuse_singular()` still refuses everyone without a record, so an
		// editor visiting the public URL sees what a visitor sees.
		if ( $post instanceof WP_Post && current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $response;
		}

		if ( $post instanceof WP_Post && $this->is_blocked( (int) $post->ID ) ) {
			// Converted, not returned raw: the posts controller calls
			// link_header() on whatever this filter hands back.
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
	 * Computed per call, not memoised: core caches the term lookup and the
	 * resolver memoises the allowed items, so a repeat costs no queries — and
	 * a stale instance cache here would outlive test transactions.
	 *
	 * @return array<int>
	 */
	private function blocked_ids(): array {
		// Queries made while the allowed items build stand unfiltered — they
		// are the resolver's own, never front-of-site content.
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
