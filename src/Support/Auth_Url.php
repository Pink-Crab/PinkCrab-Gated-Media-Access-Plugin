<?php
/**
 * Where the site's own way in lives.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * §7.7 is one view in four states, and it is one URL to match: the state rides
 * as a query argument rather than earning a route of its own. Sign in is the
 * bare URL, so the state argument only ever appears when it is not the default.
 *
 * This is `Account_Url`'s counterpart and exists for the same reason — round 8
 * found four copies of the account slug and made that class the only resolver.
 * Every link into the auth view is built here so a site renaming the segment
 * renames all of them at once.
 *
 * wp-login.php is left alone. This does not replace it, filter `login_url`, or
 * redirect it; it runs alongside it, and core's reset link still lands there.
 */
class Auth_Url {

	/** Sign in — the bare URL, so this value is never written into one. */
	public const STATE_SIGNIN = 'signin';

	/** Create an account. */
	public const STATE_SIGNUP = 'signup';

	/** Ask for a reset link. */
	public const STATE_RESET = 'reset';

	/** The reset link has been sent, if the address had an account. */
	public const STATE_SENT = 'sent';

	/** The query argument carrying the state. */
	public const ARG_STATE = 'state';

	/** The query argument marking the sent state, whose value is always 1. */
	public const ARG_SENT = 'sent';

	/** Where to go once they are signed in — core's own argument name. */
	public const ARG_REDIRECT = 'redirect_to';

	/**
	 * Sign in, optionally returning somewhere afterwards.
	 *
	 * @param string $redirect Where to land once signed in. '' for nowhere.
	 */
	public static function signin( string $redirect = '' ): string {
		return self::state( self::STATE_SIGNIN, $redirect );
	}

	/**
	 * Create an account, optionally returning somewhere afterwards.
	 *
	 * @param string $redirect Where to land once signed up. '' for nowhere.
	 */
	public static function signup( string $redirect = '' ): string {
		return self::state( self::STATE_SIGNUP, $redirect );
	}

	/**
	 * Ask for a reset link.
	 *
	 * @param string $redirect Carried through so signing in afterwards still lands right.
	 */
	public static function reset( string $redirect = '' ): string {
		return self::state( self::STATE_RESET, $redirect );
	}

	/**
	 * The confirmation shown after asking for a reset link.
	 *
	 * Reached by redirect rather than linked to, so a reload does not re-send.
	 *
	 * @param string $redirect Carried through, as above.
	 */
	public static function sent( string $redirect = '' ): string {
		$url = add_query_arg( self::ARG_SENT, '1', self::state( self::STATE_RESET, $redirect ) );

		return $url;
	}

	/**
	 * One state's URL.
	 *
	 * @param string $state    One of the STATE_* values.
	 * @param string $redirect Where to land afterwards, '' for nowhere.
	 */
	public static function state( string $state, string $redirect = '' ): string {
		$url = home_url( '/' . self::slug() . '/' );

		if ( self::STATE_SIGNIN !== $state ) {
			$url = add_query_arg( self::ARG_STATE, $state, $url );
		}

		// Not encoded here: add_query_arg() encodes the value, and PHP decodes
		// it again filling $_GET. Encoding first would double it, and the
		// matching decode would then have to be got right in four places.
		if ( '' !== $redirect ) {
			$url = add_query_arg( self::ARG_REDIRECT, $redirect, $url );
		}

		return $url;
	}

	/**
	 * The auth view's own segment.
	 *
	 * Public because `Auth_Route` needs the bare segment for its rewrite rule,
	 * where there is no whole URL to return.
	 */
	public static function slug(): string {
		$filtered = apply_filters( 'gatedmedia_auth_slug', 'sign-in' );

		// A filter returning an array would raise a conversion warning on the
		// cast, and an empty one would give `//` — a protocol-relative URL to
		// another host rather than a page on this site.
		$slug = is_string( $filtered ) ? sanitize_title( $filtered ) : '';

		return '' === $slug ? 'sign-in' : $slug;
	}
}
