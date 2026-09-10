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
 * A product is reached at `/{segment}/{uuid}` and nowhere else, never its slug and never an ID. The segment is the `product_path` setting, default `access`.
 *
 * A rewrite rule maps the UUID into its own query var, the `request` filter turns that into the product's single query and stamps a via-flag, and any main query reaching a product without the flag is answered with a 404 rather than a redirect, since a redirect to the real URL would be an oracle for guessing it.
 *
 * The permalink filter keeps everything honest for free: `get_permalink()` answers the UUID URL, so checkout's cancel link, the free claim's redirect and every product link point the only way in.
 */
class Product_Route implements Hookable {

	/** The query var the rewrite fills with the UUID. */
	public const QUERY_VAR = 'gatedmedia_product_uuid';

	/** The internal flag marking a query that arrived through the UUID. */
	public const VIA_FLAG = 'gatedmedia_product_via_uuid';

	/** Bumped when the rules below change shape. */
	private const REWRITE_VERSION = '1';

	/** Public so Lifecycle can delete it by name on uninstall. */
	public const REWRITE_OPTION = 'gatedmedia_product_rewrites';

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
		$loader->filter( 'rest_request_before_callbacks', array( $this, 'guard_product_rest' ), 3 );
		$loader->filter( 'rest_post_search_query', array( $this, 'exclude_from_rest_search' ), 2 );
		$loader->filter( 'rest_prepare_' . Post_Types::PRODUCT, array( $this, 'ensure_block' ), 3 );
	}

	/**
	 * The whole product REST surface is managers-only, because without this `show_in_rest` would let anyone list published products at `/wp/v2/gatedmedia_product`, the enumeration the front rules refuse.
	 *
	 * A 404 rather than a 403, so the guard confirms nothing.
	 *
	 * @param mixed            $response The current response, usually null.
	 * @param array<mixed>     $handler  The matched handler.
	 * @param \WP_REST_Request $request  The request being dispatched.
	 * @return mixed
	 */
	public function guard_product_rest( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		if ( ! str_starts_with( $request->get_route(), '/wp/v2/' . Post_Types::PRODUCT ) ) {
			return $response;
		}

		if ( current_user_can( \PinkCrab\Gated_Access\Registration\Capabilities::manage_products() ) ) {
			return $response;
		}

		return new \WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'gated-media-access' ), array( 'status' => 404 ) );
	}

	/**
	 * Every product opens with its form. The post-type template only seeds brand-new posts, so when the editor fetches one whose content lacks the block the locked delimiter is prepended, persisting on the next save.
	 *
	 * Returns the response untouched otherwise, never a bare WP_Error.
	 *
	 * @param \WP_REST_Response $response The prepared product.
	 * @param WP_Post           $post     The product.
	 * @param \WP_REST_Request  $request  The request being answered.
	 * @return \WP_REST_Response
	 */
	public function ensure_block( \WP_REST_Response $response, WP_Post $post, \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'edit' !== $request->get_param( 'context' ) ) {
			return $response;
		}

		$raw = $response->data['content']['raw'] ?? null;

		if ( is_string( $raw ) && ! str_contains( $raw, 'wp:gated-media-access/product-details' ) ) {
			$response->data['content']['raw'] = '<!-- wp:gated-media-access/product-details {"lock":{"move":true,"remove":true}} /-->' . "\n" . $raw;
		}

		return $response;
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
	 * Turns a UUID into the product's own single query, and refuses every other road to one.
	 *
	 * @param array<string, mixed> $query_vars The main request's vars.
	 * @return array<string, mixed>
	 */
	public function route_request( array $query_vars ): array {
		// Front rules only: the admin list and editor query the type legitimately, and clobbering their vars sends core's list table back to a default posts query.
		if ( is_admin() ) {
			return $query_vars;
		}

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

		// Every other way to a product is refused, except the query mapped above.
		if ( Post_Types::PRODUCT === ( $query_vars['post_type'] ?? '' ) && '' === (string) ( $query_vars[ self::VIA_FLAG ] ?? '' ) ) {
			return array( 'error' => '404' );
		}

		// Dropping the id too, or redirect_canonical 301s the 404 to the UUID.
		$post_id = (int) ( $query_vars['p'] ?? $query_vars['page_id'] ?? 0 );

		if ( 0 !== $post_id && '' === (string) ( $query_vars[ self::VIA_FLAG ] ?? '' ) && Post_Types::PRODUCT === get_post_type( $post_id ) ) {
			return array( 'error' => '404' );
		}

		return $query_vars;
	}

	/**
	 * Keeps products out of core's `/wp/v2/search`, which answers with the UUID URL and which `exclude_from_search` does not reach.
	 *
	 * @param array<string, mixed> $args The search query core built.
	 * @return array<string, mixed>
	 */
	public function exclude_from_rest_search( array $args ): array {
		if ( current_user_can( \PinkCrab\Gated_Access\Registration\Capabilities::manage_products() ) ) {
			return $args;
		}

		$types = (array) ( $args['post_type'] ?? array() );
		$types = array_values( array_diff( array_map( 'strval', $types ), array( Post_Types::PRODUCT ) ) );

		$args['post_type'] = $types;

		// An empty post_type falls back to `post`, so say the emptiness.
		if ( array() === $types ) {
			$args['post__in'] = array( 0 );
		}

		return $args;
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
				// Unpublished too, since the UUID is a product's only address and a draft has to be previewable at it.
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Uuid::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row by unique value.
				'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		if ( array() === $found ) {
			return null;
		}

		$product_id = (int) $found[0];

		// Unpublished answers only to whoever may edit it.
		if ( 'publish' !== get_post_status( $product_id ) && ! current_user_can( 'edit_post', $product_id ) ) {
			return null;
		}

		return $product_id;
	}

	/**
	 * Flushes only when the rules changed, by version or segment.
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
