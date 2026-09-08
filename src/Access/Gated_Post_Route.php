<?php
/**
 * Content reachable only at its UUID.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * The `gatedmedia_gated` status, and the addressing it implies.
 *
 * **A trigger, not a second access model.** Setting the status applies the marker term, so `Post_Boundary` refuses it, `Resolver` decides who holds it, and its absence from archives, search, REST and sitemaps all follow with no new code.
 *
 * What the status adds is **where the post lives**. An ordinary restricted post keeps its permalink and is merely refused. One carrying this status answers at `/{segment}/{uuid}` and nowhere else: its slug, `?p=` and any stale permalink all 404, for holders too.
 *
 * **404, never a redirect**, or the slug becomes an oracle for discovering the UUID URL.
 */
class Gated_Post_Route implements Hookable {

	/** The query var the rewrite fills with the UUID. */
	public const QUERY_VAR = 'gatedmedia_gated_uuid';

	/** The internal flag marking a query that arrived through the UUID. */
	public const VIA_FLAG = 'gatedmedia_gated_via_uuid';

	/** Bumped when the rule below changes shape. */
	private const REWRITE_VERSION = '1';

	/** Public so Lifecycle can delete it by name on uninstall. */
	public const REWRITE_OPTION = 'gatedmedia_gated_rewrites';

	/** Autoloaded, so a site with nothing gated answers from memory. */
	public const ANY_GATED_OPTION = 'gatedmedia_any_gated_post';

	/**
	 * Owns the marker term the status applies.
	 *
	 * @param Restriction $restriction The marker term.
	 */
	public function __construct( private Restriction $restriction ) {
	}

	/**
	 * The rule, its var, the routing, the permalink and the status trigger.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_rewrites' ) );
		$loader->filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$loader->filter( 'request', array( $this, 'route_request' ) );
		$loader->filter( 'post_link', array( $this, 'gated_link' ), 2 );
		$loader->filter( 'post_type_link', array( $this, 'gated_link' ), 2 );
		$loader->action( 'transition_post_status', array( $this, 'mark_on_transition' ), 3 );
	}

	/**
	 * The URL segment gated content sits under.
	 *
	 * A filter rather than a setting, like `Account_Url::slug()`: a site changes this in code, and a setting would invite someone to break every link they had already shared.
	 */
	public static function segment(): string {
		$filtered = apply_filters( 'gatedmedia_gated_path', 'gated' );
		$segment  = is_string( $filtered ) ? sanitize_title( $filtered ) : '';

		return '' === $segment ? 'gated' : $segment;
	}

	/**
	 * A gated post's only URL.
	 *
	 * @param int $post_id The post.
	 */
	public static function url( int $post_id ): string {
		return home_url( '/' . self::segment() . '/' . Uuid::ensure( 'post', $post_id ) . '/' );
	}

	/**
	 * The UUID rule, flushed once per version-and-segment.
	 */
	public function register_rewrites(): void {
		add_rewrite_rule(
			'^' . self::segment() . '/([0-9a-f-]{36})/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);

		$this->flush_once();
	}

	/**
	 * Makes both vars readable through get_query_var().
	 *
	 * @param array<int, string> $vars Core's public query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::VIA_FLAG;

		return $vars;
	}

	/**
	 * Turns a UUID into that post's own single query, and refuses every other road to a gated post.
	 *
	 * @param array<string, mixed> $query_vars The main request's vars.
	 * @return array<string, mixed>
	 */
	public function route_request( array $query_vars ): array {
		// The request filter runs on admin queries too, and clobbering their vars would send the editor's list table elsewhere.
		if ( is_admin() ) {
			return $query_vars;
		}

		$uuid = (string) ( $query_vars[ self::QUERY_VAR ] ?? '' );

		if ( '' !== $uuid ) {
			$post_id = $this->find_gated_post( $uuid );

			if ( null === $post_id ) {
				return array( 'error' => '404' );
			}

			return array(
				'p'            => $post_id,
				'post_type'    => get_post_type( $post_id ),
				'post_status'  => Post_Types::STATUS_GATED,
				self::VIA_FLAG => 1,
			);
		}

		// Every other way to a gated post is refused, except the query built above.
		if ( '' === (string) ( $query_vars[ self::VIA_FLAG ] ?? '' ) && $this->names_gated_post( $query_vars ) ) {
			return array( 'error' => '404' );
		}

		return $query_vars;
	}

	/**
	 * A gated post's permalink is its UUID URL, so every link the site builds points the only way in.
	 *
	 * @param string $link The permalink core built.
	 * @param mixed  $post The post it is for.
	 */
	public function gated_link( string $link, mixed $post ): string {
		$post = $post instanceof WP_Post ? $post : get_post( $post );

		if ( ! $post instanceof WP_Post || Post_Types::STATUS_GATED !== $post->post_status ) {
			return $link;
		}

		return self::url( (int) $post->ID );
	}

	/**
	 * Setting the status marks the post restricted and mints its UUID.
	 *
	 * Removing the status leaves the marker alone, the same asymmetry `Restriction` documents. Unrestricting stays a manual act, or changing a dropdown would silently republish content.
	 *
	 * @param string  $new_status The status being moved to.
	 * @param string  $old_status The status being moved from.
	 * @param WP_Post $post       The post.
	 */
	public function mark_on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		// Either direction changes whether the site has any gated post.
		if ( Post_Types::STATUS_GATED === $new_status || Post_Types::STATUS_GATED === $old_status ) {
			delete_option( self::ANY_GATED_OPTION );
		}

		if ( Post_Types::STATUS_GATED !== $new_status || $new_status === $old_status ) {
			return;
		}

		// Its own identity, and the only address it will answer at.
		Uuid::ensure( 'post', (int) $post->ID );

		$marker = $this->restriction->ensure_marker();

		if ( 0 === $marker ) {
			return;
		}

		wp_set_object_terms( (int) $post->ID, array( $marker ), Access_Taxonomy::TAXONOMY, true );
	}

	/**
	 * Whether the site holds a gated post at all, remembered in an option.
	 */
	private function any_gated_post(): bool {
		$stored = get_option( self::ANY_GATED_OPTION, '' );

		if ( '' !== $stored ) {
			return '1' === $stored;
		}

		$found = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => Post_Types::STATUS_GATED,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$any = array() !== $found;

		update_option( self::ANY_GATED_OPTION, $any ? '1' : '0', true );

		return $any;
	}

	/**
	 * Whether these query vars would land on a gated post.
	 *
	 * @param array<string, mixed> $query_vars The main request's vars.
	 */
	private function names_gated_post( array $query_vars ): bool {
		// Every pretty permalink on the site reaches here, so a site with nothing gated must not pay a lookup to find that out again.
		if ( ! $this->any_gated_post() ) {
			return false;
		}

		$post_id = (int) ( $query_vars['p'] ?? $query_vars['page_id'] ?? 0 );

		if ( 0 === $post_id ) {
			// pagename is parent/child, which no post_name can ever match.
			$path = (string) ( $query_vars['pagename'] ?? '' );

			if ( '' !== $path ) {
				$page = get_page_by_path( $path, OBJECT, get_post_types( array( 'hierarchical' => true ) ) );

				return $page instanceof \WP_Post && Post_Types::STATUS_GATED === $page->post_status;
			}

			$name = (string) ( $query_vars['name'] ?? '' );

			if ( '' === $name ) {
				return false;
			}

			$found = get_posts(
				array(
					'name'           => $name,
					'post_type'      => 'any',
					'post_status'    => Post_Types::STATUS_GATED,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			return array() !== $found;
		}

		return Post_Types::STATUS_GATED === get_post_status( $post_id );
	}

	/**
	 * The gated post holding this UUID, if any.
	 *
	 * @param string $uuid The identity to look up.
	 */
	private function find_gated_post( string $uuid ): ?int {
		$found = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => Post_Types::STATUS_GATED,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Uuid::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row by unique value.
				'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		return array() === $found ? null : (int) $found[0];
	}

	/**
	 * Flushes only when the rule changed, by version or segment.
	 */
	private function flush_once(): void {
		$stamp = self::REWRITE_VERSION . ':' . self::segment();

		if ( get_option( self::REWRITE_OPTION ) === $stamp ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, $stamp, true );
	}
}
