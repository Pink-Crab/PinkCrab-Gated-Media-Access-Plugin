<?php
/**
 * The one public way to a product.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * A product is reached at `/{segment}/{uuid}` and nowhere else — never its
 * slug, never an ID (Glynn's round 5 ruling). The segment is the
 * `product_path` setting, default `access`.
 *
 * The mechanics: a rewrite rule maps the UUID into its own query var; the
 * `request` filter turns that into the product's own single query and
 * stamps a via-flag; and any main query that reaches a product without the
 * flag — `?post_type=…&p=…`, a stale pretty URL, anything — is answered
 * with a 404, not a redirect: a block that redirects to the real URL would
 * be an oracle for guessing it.
 *
 * The permalink filter keeps everything honest for free: get_permalink()
 * answers the UUID URL, so checkout's cancel link, the free claim's
 * redirect and round 6's pages all point the only way in.
 */
class Product_Route implements Hookable {

	/** The query var the rewrite fills with the UUID. */
	public const QUERY_VAR = 'gatedmedia_product_uuid';

	/** The internal flag marking a query that arrived through the UUID. */
	public const VIA_FLAG = 'gatedmedia_product_via_uuid';

	/** Bumped when the rules below change shape. */
	private const REWRITE_VERSION = '1';

	private const REWRITE_OPTION = 'gatedmedia_product_rewrites';

	/**
	 * The segment comes from settings.
	 *
	 * @param Settings $settings The settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The rule, its vars, the routing and the permalink.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_rewrites' ) );
		$loader->filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$loader->filter( 'request', array( $this, 'route_request' ) );
		$loader->filter( 'post_type_link', array( $this, 'product_link' ), 2 );
	}

	/**
	 * The UUID rule, flushed once per version-and-segment.
	 */
	public function register_rewrites(): void {
		add_rewrite_rule(
			'^' . $this->settings->product_path() . '/([0-9a-f-]{36})/?$',
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
	 * Turns a UUID into the product's own single query, and refuses every
	 * other road to one.
	 *
	 * @param array<string, mixed> $query_vars The main request's vars.
	 * @return array<string, mixed>
	 */
	public function route_request( array $query_vars ): array {
		$uuid = (string) ( $query_vars[ self::QUERY_VAR ] ?? '' );

		if ( '' !== $uuid ) {
			$product_id = $this->find_product( $uuid );

			if ( null === $product_id ) {
				return array( 'error' => '404' );
			}

			return array(
				'post_type'    => Post_Types::PRODUCT,
				'p'            => $product_id,
				self::VIA_FLAG => 1,
			);
		}

		// Any other main query naming the product type — ?post_type=…&p=…,
		// a stale pretty permalink — is refused outright. The via-flag
		// exempts the query this filter itself mapped above.
		if ( Post_Types::PRODUCT === ( $query_vars['post_type'] ?? '' ) && '' === (string) ( $query_vars[ self::VIA_FLAG ] ?? '' ) ) {
			return array( 'error' => '404' );
		}

		return $query_vars;
	}

	/**
	 * The product's only URL, minting its identity on first ask.
	 *
	 * @param string  $link The permalink core built.
	 * @param WP_Post $post The post it is for.
	 */
	public function product_link( string $link, WP_Post $post ): string {
		if ( Post_Types::PRODUCT !== $post->post_type ) {
			return $link;
		}

		return home_url( '/' . $this->settings->product_path() . '/' . Uuid::ensure( 'post', $post->ID ) . '/' );
	}

	/**
	 * The published product holding this UUID, if any.
	 *
	 * @param string $uuid The identity to look up.
	 */
	private function find_product( string $uuid ): ?int {
		$found = get_posts(
			array(
				'post_type'      => Post_Types::PRODUCT,
				'post_status'    => 'publish',
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
	 * Flushes only when the rules themselves changed — version or segment.
	 */
	private function flush_once(): void {
		$stamp = self::REWRITE_VERSION . ':' . $this->settings->product_path();

		if ( get_option( self::REWRITE_OPTION ) === $stamp ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, $stamp, true );
	}
}
