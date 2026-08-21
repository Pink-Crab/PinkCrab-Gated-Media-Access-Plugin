<?php
/**
 * Where the account area's pages live.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * The account segment is a filter rather than a setting — `Account_Route`'s
 * docblock says why: it is the sort of thing a site changes in code, and
 * making it a setting invites someone to break their own links with it.
 *
 * Which means every place that builds an account URL has to resolve that
 * filter the same way. This is that one way, so a site renaming its account
 * area renames every link into it at once.
 */
class Account_Url {

	/**
	 * A section's own page, e.g. `/account/orders/`.
	 *
	 * @param string $section The section slug.
	 */
	public static function section( string $section ): string {
		return home_url( sprintf( '/%s/%s/', self::slug(), $section ) );
	}

	/**
	 * One thing inside a section, e.g. `/account/orders/{uuid}/`.
	 *
	 * @param string $section    The section slug.
	 * @param string $identifier The second segment — always an opaque uuid.
	 */
	public static function detail( string $section, string $identifier ): string {
		return self::section( $section ) . rawurlencode( $identifier ) . '/';
	}

	/**
	 * The account area's own segment.
	 */
	private static function slug(): string {
		$slug = apply_filters( 'gatedmedia_account_slug', 'account' );

		return is_string( $slug ) && '' !== $slug ? $slug : 'account';
	}
}
