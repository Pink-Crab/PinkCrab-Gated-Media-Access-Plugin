<?php
/**
 * The plugin's own account route.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Post;
use WP_Query;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * Puts the account area on a URL of its own.
 *
 * This is the second of the two ways the account area reaches a site — the
 * first being an administrator placing the blocks on their own pages. Both
 * render the same blocks, which is what stops them drifting apart.
 *
 * **A virtual page, not a takeover.** The route answers with a page the theme
 * then renders: its header, its navigation, its footer, its width. This is the
 * WooCommerce My Account model, and it is the only one that behaves for a
 * plugin shipped to sites whose themes we have never seen. Replacing the
 * document would strand someone on a page with no way back to the site.
 *
 * **One rewrite rule, not one per section.** A rule per section would mean a
 * third party adding one has no URL until rewrite rules are flushed, and
 * flush-on-demand is a trap: it is expensive, it is easy to call on every
 * request by accident, and forgetting it produces a 404 nobody can explain. So
 * the rule captures any segment and the section list decides at runtime what is
 * valid. Adding a section needs no flush, ever.
 *
 * Two segments are captured. The second is what makes `/account/orders/{id}`
 * work, and a third-party section gets the same for free.
 */
class Account_Route implements Hookable {

	/** Marks a request as ours. */
	public const QUERY_FLAG = 'gatedmedia_account';

	/** Which section — the first URL segment. */
	public const QUERY_SECTION = 'gatedmedia_account_section';

	/** The optional second segment: an order id, or whatever a section wants. */
	public const QUERY_DETAIL = 'gatedmedia_account_detail';

	/**
	 * Bumped whenever the plugin's rewrite rules change — the rules below, or
	 * a registered post type's — which triggers exactly one flush.
	 *
	 * Adding a section does not change the rules, so this does not move when
	 * one is added — that is the point of the catch-all.
	 * '2': the gatedmedia_product post type added its rules.
	 */
	private const REWRITE_VERSION = '2';

	private const REWRITE_OPTION = 'gatedmedia_rewrite_version';

	/**
	 * The section being viewed, once resolved.
	 *
	 * Held so `the_content` can render without resolving a second time.
	 *
	 * @var Account_Section|null
	 */
	private ?Account_Section $current = null;

	/**
	 * The sections this user may see.
	 *
	 * @var Section_Collection|null
	 */
	private ?Section_Collection $visible = null;

	/**
	 * Everything the route needs, resolved by the container.
	 *
	 * @param Section_Registry $registry The section list.
	 * @param Account_Renderer $renderer Draws the shell.
	 * @param Asset_Loader     $assets   Supplies the front bundle.
	 * @param Sprite           $sprite   Prints the icon symbols.
	 * @param Settings         $settings Says whether this route runs at all.
	 */
	public function __construct(
		private Section_Registry $registry,
		private Account_Renderer $renderer,
		private Asset_Loader $assets,
		private Sprite $sprite,
		private Settings $settings,
	) {
	}

	/**
	 * Registers the rewrites, the query vars and the virtual page.
	 *
	 * Nothing is attached when the `account_route` setting is off: the brief
	 * gives an administrator two ways to have an account area, this route or
	 * their own pages holding the same blocks, and a site that has chosen the
	 * second should not also answer on `/account/`.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		if ( ! $this->settings->account_route() ) {
			return;
		}

		$loader->action( 'init', array( $this, 'register_rewrites' ) );
		$loader->filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$loader->filter( 'the_posts', array( $this, 'supply_virtual_page' ), 2, 10 );
		$loader->action( 'template_redirect', array( $this, 'require_login' ) );
		$loader->action( 'template_redirect', array( $this, 'send_not_found_status' ) );
		$loader->filter( 'the_content', array( $this, 'render_content' ) );
	}

	/**
	 * The account area's URL segment.
	 *
	 * A filter rather than a setting, per the brief — this is the sort of thing
	 * a site changes in code, and making it a setting invites someone to change
	 * it in a way that breaks their own links. `Account_Url` resolves it; this
	 * stays as the route's own way of asking.
	 */
	public function slug(): string {
		return Account_Url::slug();
	}

	/**
	 * Three rules: the bare route, one segment, two segments.
	 *
	 * Ordered longest-first because WordPress takes the first that matches.
	 */
	public function register_rewrites(): void {
		$slug = preg_quote( $this->slug(), '/' );

		add_rewrite_rule(
			'^' . $slug . '/([^/]+)/([^/]+)/?$',
			'index.php?' . self::QUERY_FLAG . '=1'
				. '&' . self::QUERY_SECTION . '=$matches[1]'
				. '&' . self::QUERY_DETAIL . '=$matches[2]',
			'top'
		);

		add_rewrite_rule(
			'^' . $slug . '/([^/]+)/?$',
			'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_SECTION . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^' . $slug . '/?$',
			'index.php?' . self::QUERY_FLAG . '=1',
			'top'
		);

		$this->flush_once();
	}

	/**
	 * Flushes only when the rules themselves changed.
	 *
	 * Flushing is expensive and doing it on every request is a well-known way
	 * to make a site crawl, so it is gated on a stored version rather than on
	 * "are our rules present".
	 */
	private function flush_once(): void {
		if ( get_option( self::REWRITE_OPTION ) === self::REWRITE_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION, true );
	}

	/**
	 * Makes our three query vars readable through get_query_var().
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_FLAG;
		$vars[] = self::QUERY_SECTION;
		$vars[] = self::QUERY_DETAIL;

		return $vars;
	}

	/**
	 * Answers the account route with a page that does not exist in the database.
	 *
	 * The query found nothing, because there is nothing to find. Rather than
	 * let that become a 404, we hand back one post so the theme renders its
	 * ordinary singular template around our content.
	 *
	 * @param array<int, WP_Post> $posts The posts the query found.
	 * @param WP_Query            $query The query that found them.
	 * @return array<int, WP_Post>
	 */
	public function supply_virtual_page( array $posts, WP_Query $query ): array {
		if ( ! $this->is_account_query( $query ) ) {
			return $posts;
		}

		// Signed out is turned away on template_redirect, where a redirect is
		// safe to send. Until then it must be a 404 and not the blog listing.
		if ( ! is_user_logged_in() ) {
			return $this->refuse( $query );
		}

		$this->visible = $this->registry->visible_to( get_current_user_id() );

		$requested     = (string) $query->get( self::QUERY_SECTION );
		$this->current = '' === $requested
			? $this->visible->first()
			: $this->visible->get( $requested );

		// An unknown slug, or one this user may not see, is a 404 — the nav and
		// the router read the same list, so this only happens for a URL typed
		// by hand or left over from a section that has gone away.
		if ( null === $this->current ) {
			return $this->refuse( $query );
		}

		$query->is_404      = false;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_singular = true;
		$query->is_page     = true;

		$query->found_posts   = 1;
		$query->post_count    = 1;
		$query->max_num_pages = 1;

		// Our content is already markup. wpautop would insert paragraphs
		// between the shell's elements and break the layout.
		remove_filter( 'the_content', 'wpautop' );

		$this->assets->enqueue_front();
		// The shell draws its navigation before the loop, so nothing has passed
		// through render_block by the time the footer runs — the sprite has to
		// be asked for explicitly here.
		$this->sprite->require_sprite();

		return array( $this->virtual_post( $this->current ) );
	}

	/**
	 * Turns an account URL we cannot answer into a 404.
	 *
	 * Returning an empty list is not enough on its own. The rewrite resolves to
	 * `index.php` with only our own query vars on it, so nothing marks the
	 * request as singular and WordPress falls back to treating it as the blog
	 * home — which never 404s, and would answer `/account/nonsense/` with the
	 * post listing.
	 *
	 * @param WP_Query $query The main query.
	 * @return array<int, WP_Post> Always empty.
	 */
	private function refuse( WP_Query $query ): array {
		$query->set_404();
		$query->is_home = false;

		return array();
	}

	/**
	 * Sends a signed-out visitor to sign in, and back here afterwards.
	 *
	 * The account area is a person's own record, so there is no signed-out view
	 * of it to render.
	 *
	 * Round 9 moved this off `wp_login_url()` and onto the plugin's own view.
	 * wp-login.php still works and is still where core's reset link lands; it
	 * is simply not where our own pages send people any more.
	 */
	public function require_login(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_FLAG ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( Auth_Url::signin( $this->current_url() ) );
		exit;
	}

	/**
	 * Sends a real 404 status for an account URL we refused.
	 *
	 * `refuse()` marks the query as a 404, which is enough to get the theme's
	 * 404 template rendered — but not enough to set the status code. Core sends
	 * that from `WP::handle_404()`, which runs before this and declines to act
	 * on a 404 the query already carried. Left alone the result is a soft 404:
	 * a "page not found" screen served with 200, which search engines index and
	 * uptime monitors call healthy.
	 */
	public function send_not_found_status(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_FLAG ) ) {
			return;
		}

		if ( null !== $this->current ) {
			return;
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Replaces the virtual page's empty content with the account shell.
	 *
	 * Rendered here rather than when the post is built, so blocks render at the
	 * ordinary time with the query fully set up.
	 *
	 * @param string $content The post content.
	 */
	public function render_content( string $content ): string {
		if ( null === $this->current || null === $this->visible || ! in_the_loop() ) {
			return $content;
		}

		return $this->renderer->markup(
			$this->current,
			$this->visible,
			(string) get_query_var( self::QUERY_DETAIL )
		);
	}

	/**
	 * Whether this is the main front-end query for the account route.
	 *
	 * @param WP_Query $query The query to test.
	 */
	private function is_account_query( WP_Query $query ): bool {
		if ( is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		return '1' === (string) $query->get( self::QUERY_FLAG );
	}

	/**
	 * A page that exists only for this request.
	 *
	 * ID 0 keeps it from colliding with a real post: anything that reaches for
	 * post meta gets nothing rather than another post's values.
	 *
	 * @param Account_Section $section The section being viewed.
	 */
	private function virtual_post( Account_Section $section ): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'                    => 0,
				'post_author'           => (string) get_current_user_id(),
				'post_date'             => current_time( 'mysql' ),
				'post_date_gmt'         => current_time( 'mysql', true ),
				'post_content'          => '',
				'post_title'            => $section->title(),
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_name'             => $section->slug(),
				'post_parent'           => 0,
				'menu_order'            => 0,
				'post_type'             => 'page',
				'post_mime_type'        => '',
				'comment_count'         => '0',
				'filter'                => 'raw',
				'post_password'         => '',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
				'post_modified'         => current_time( 'mysql' ),
				'post_modified_gmt'     => current_time( 'mysql', true ),
				'guid'                  => home_url( sprintf( '/%s/%s/', $this->slug(), $section->slug() ) ),
			)
		);
	}

	/**
	 * The URL being requested, for the round trip through the auth view.
	 */
	private function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		return home_url( $path );
	}
}
