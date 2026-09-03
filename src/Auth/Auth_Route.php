<?php
/**
 * The site's own way in, on a URL of its own.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Auth;

use WP_Post;
use WP_Query;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * `Account_Route`'s mirror image, and deliberately so. That route exists for
 * people who are signed in and turns everyone else away; this one exists for
 * people who are not, and turns *them* away — which is why it could never have
 * been a section of the account area. `Account_Section`'s own contract says as
 * much: a section always renders inside the account shell, and §7.7 has no
 * shell at all.
 *
 * **One rule, not four.** §7.7 is one view in four states, so it is one URL:
 * the state rides as a query argument (`Auth_Url`), and the four states share
 * a rewrite, a virtual page and a block. Adding a state needs no flush.
 *
 * **A virtual page, not a takeover** — the same WooCommerce model the account
 * route uses. The theme renders its header, navigation and footer around our
 * content, so nobody is ever stranded on a page with no way back to the site.
 *
 * wp-login.php is left entirely alone: not filtered, not redirected, not
 * replaced. It keeps working, which is what makes it the recovery door if this
 * page ever breaks, and it is still where core's reset link lands.
 */
class Auth_Route implements Hookable {

	/** Marks a request as ours. */
	public const QUERY_FLAG = 'gatedmedia_auth';

	/** The block that draws all four states. */
	public const BLOCK = 'gated-media-access/auth';

	/** Bumped when the rule below changes shape. */
	private const REWRITE_VERSION = '1';

	private const REWRITE_OPTION = 'gatedmedia_auth_rewrites';

	/**
	 * Whether this request is ours, resolved once.
	 *
	 * @var bool
	 */
	private bool $is_ours = false;

	/**
	 * Everything the route needs, resolved by the container.
	 *
	 * @param Auth_State   $state    Which of the four states this request is.
	 * @param Asset_Loader $assets   Supplies the front bundle.
	 * @param Sprite       $sprite   Prints the icon symbols the notice uses.
	 */
	public function __construct(
		private Auth_State $state,
		private Asset_Loader $assets,
		private Sprite $sprite,
	) {
	}

	/**
	 * The heading for each state — §7.7's block 1.
	 *
	 * **This one is the block's, not the theme's**, which is the opposite of
	 * every other view and is why it is worth saying. `Account_Renderer` lets
	 * the theme print the h1 because the account area has a shell for it to be
	 * the heading *of*. §7.7 has no shell: a theme-printed title lands outside
	 * the card, left-aligned to the content column while the card is centred,
	 * and reads as a stray line above an unrelated box.
	 *
	 * So the virtual page is given no title — core's `post-title` block renders
	 * nothing at all for an empty one rather than an empty `h1`
	 * (`wp-includes/blocks/post-title.php`) — and the block draws the heading
	 * inside the card where §7.7 puts it. Still exactly one h1, which is what
	 * §2 conflict 4 was protecting.
	 *
	 * The document title is set from this too, so the browser tab still names
	 * the state.
	 *
	 * @param string $state One of `Auth_Url`'s STATE_* values.
	 */
	public static function title_for( string $state ): string {
		$titles = array(
			Auth_Url::STATE_SIGNUP => __( 'Create your account', 'gated-media-access' ),
			Auth_Url::STATE_RESET  => __( 'Reset your password', 'gated-media-access' ),
			Auth_Url::STATE_SENT   => __( 'Check your email', 'gated-media-access' ),
		);

		return $titles[ $state ] ?? __( 'Sign in', 'gated-media-access' );
	}

	/**
	 * The rewrite, the query var, the virtual page and the content.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_rewrites' ) );
		$loader->filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$loader->filter( 'the_posts', array( $this, 'supply_virtual_page' ), 2, 10 );
		$loader->action( 'template_redirect', array( $this, 'send_signed_in_away' ) );
		$loader->filter( 'the_content', array( $this, 'render_content' ) );
		$loader->filter( 'document_title_parts', array( $this, 'document_title' ) );
	}

	/**
	 * Names the state in the browser tab.
	 *
	 * The virtual page carries no title, so without this the tab would fall
	 * back to the site name alone and four different pages would look like one
	 * in a list of open tabs or in history.
	 *
	 * @param array<string, string> $parts The title's parts.
	 * @return array<string, string>
	 */
	public function document_title( array $parts ): array {
		if ( ! $this->is_ours ) {
			return $parts;
		}

		$parts['title'] = self::title_for( $this->state->current_state() );

		return $parts;
	}

	/**
	 * The auth view's URL segment.
	 */
	public function slug(): string {
		return Auth_Url::slug();
	}

	/**
	 * One rule, flushed once per version-and-segment.
	 */
	public function register_rewrites(): void {
		add_rewrite_rule(
			'^' . preg_quote( $this->slug(), '/' ) . '/?$',
			'index.php?' . self::QUERY_FLAG . '=1',
			'top'
		);

		$this->flush_once();
	}

	/**
	 * Makes our flag readable through get_query_var().
	 *
	 * @param array<int, string> $vars Registered public query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_FLAG;

		return $vars;
	}

	/**
	 * Answers the auth route with a page that does not exist in the database.
	 *
	 * Signed in is sent away on `template_redirect`, where a redirect is safe
	 * to send. Until then the page is built either way — bailing here would
	 * leave the request as the blog listing rather than as a redirect.
	 *
	 * @param array<int, WP_Post> $posts The posts the query found.
	 * @param WP_Query            $query The query that found them.
	 * @return array<int, WP_Post>
	 */
	public function supply_virtual_page( array $posts, WP_Query $query ): array {
		if ( is_admin() || ! $query->is_main_query() || '1' !== (string) $query->get( self::QUERY_FLAG ) ) {
			return $posts;
		}

		$this->is_ours = true;

		$query->is_404      = false;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_singular = true;
		$query->is_page     = true;

		$query->found_posts   = 1;
		$query->post_count    = 1;
		$query->max_num_pages = 1;

		// Our content is already markup. wpautop would insert paragraphs
		// between the card's elements and break the layout.
		remove_filter( 'the_content', 'wpautop' );

		$this->assets->enqueue_front();
		$this->sprite->require_sprite();

		return array( $this->virtual_post() );
	}

	/**
	 * Someone already signed in has nothing to do here.
	 *
	 * They are sent where the form would have sent them — their destination if
	 * they carried one, their account otherwise — rather than shown a sign-in
	 * form they cannot use.
	 */
	public function send_signed_in_away(): void {
		if ( '1' !== (string) get_query_var( self::QUERY_FLAG ) || ! is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A destination only; wp_validate_redirect() is the guard.
		$raw      = (string) ( $_GET[ Auth_Url::ARG_REDIRECT ] ?? '' );
		$redirect = '' === $raw ? '' : wp_validate_redirect( $raw, '' );

		if ( '' === $redirect ) {
			$redirect = Account_Url::section( 'my-access' );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Replaces the virtual page's empty content with the auth block.
	 *
	 * Rendered here rather than when the post is built, so the block renders at
	 * the ordinary time with the query fully set up.
	 *
	 * @param string $content The post content.
	 */
	public function render_content( string $content ): string {
		if ( ! $this->is_ours || ! in_the_loop() ) {
			return $content;
		}

		return do_blocks( '<!-- wp:' . self::BLOCK . ' /-->' );
	}

	/**
	 * A page that exists only for this request.
	 *
	 * ID 0 keeps it from colliding with a real post: anything reaching for post
	 * meta gets nothing rather than another post's values.
	 */
	private function virtual_post(): WP_Post {
		return new WP_Post(
			(object) array(
				'ID'                    => 0,
				'post_author'           => '0',
				'post_date'             => current_time( 'mysql' ),
				'post_date_gmt'         => current_time( 'mysql', true ),
				'post_content'          => '',
				// Empty on purpose — see title_for(). The block draws the
				// heading; core renders nothing here rather than an empty h1.
				'post_title'            => '',
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_name'             => $this->slug(),
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
				'guid'                  => home_url( '/' . $this->slug() . '/' ),
			)
		);
	}

	/**
	 * Flushes only when the rule itself changed — version or segment.
	 */
	private function flush_once(): void {
		$stamp = self::REWRITE_VERSION . ':' . $this->slug();

		if ( get_option( self::REWRITE_OPTION ) === $stamp ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, $stamp, true );
	}
}
