<?php
/**
 * Where the account area's pages live.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

use PinkCrab\Gated_Access\Settings\Settings;

/**
 * The account segment is a filter rather than a setting, because a setting invites someone to break their own links with it.
 *
 * Which means every place that builds an account URL has to resolve that filter the same way, and this is that one way, so a site renaming its account area renames every link into it at once.
 */
class Account_Url {

	/**
	 * A section's own page, e.g. `/account/orders/`.
	 *
	 * @param string $section The section slug.
	 */
	public static function section( string $section ): string {
		return self::resolve( home_url( sprintf( '/%s/%s/', self::slug(), $section ) ), $section, '' );
	}

	/**
	 * One thing inside a section, e.g. `/account/orders/{uuid}/`.
	 *
	 * @param string $section    The section slug.
	 * @param string $identifier The second segment, always an opaque uuid.
	 */
	public static function detail( string $section, string $identifier ): string {
		$built = home_url( sprintf( '/%s/%s/', self::slug(), $section ) ) . rawurlencode( $identifier ) . '/';

		return self::resolve( $built, $section, $identifier );
	}

	/**
	 * The setting, then the filter, applied to every link alike.
	 *
	 * Switched off, `Account_Route` registers no rewrite rules, so the built URL is a 404 and the home page is a poor destination but an answering one.
	 *
	 * A site placing the blocks on its own pages says where they are through the filter, which is also how a section gains a page of its own while the route stays on.
	 *
	 * @param string $url        The `/account/` URL as built.
	 * @param string $section    The section slug.
	 * @param string $identifier The detail segment, '' for a section link.
	 */
	private static function resolve( string $url, string $section, string $identifier ): string {
		$url = ( new Settings() )->account_route() ? $url : home_url( '/' );

		/**
		 * Filters where a link into the account area points.
		 *
		 * @param string $url        Where it points so far.
		 * @param string $section    The section slug.
		 * @param string $identifier The detail segment, '' for a section link.
		 */
		$filtered = apply_filters( 'gatedmedia_account_url', $url, $section, $identifier );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $url;
	}

	/**
	 * The account area's own segment.
	 *
	 * Public because `Account_Route` needs the bare segment to build its rewrite rules, where there is no whole URL to return.
	 */
	public static function slug(): string {
		$slug = apply_filters( 'gatedmedia_account_slug', 'account' );

		return is_string( $slug ) && '' !== $slug ? $slug : 'account';
	}
}
