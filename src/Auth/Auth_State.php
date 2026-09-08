<?php
/**
 * Which of the four states the auth view is in, and what it says.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Auth;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * The `auth` block cannot reach the container, so it raises `gatedmedia_auth_data` and this answers it, the same arrangement `Product_Offer` uses.
 *
 * Everything here is read from the request: `Auth_Action` does the writing and comes back with a code in the URL, and this turns that code into a notice and a set of invalid fields.
 *
 * The state is a query argument rather than a route of its own, so this is also the only place that decides what an absent or unrecognised argument means, which is sign in, always.
 */
class Auth_State implements Hookable {

	/** Shortest password the sign-up form accepts. */
	public const PASSWORD_MINIMUM = 12;

	/**
	 * The settings decide whether signing up is offered at all.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Answers the block's filter.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_auth_data', array( $this, 'supply_data' ) );
	}

	/**
	 * Everything the view needs to draw itself.
	 *
	 * @param array<string, mixed> $data The block's defaults.
	 * @return array<string, mixed>
	 */
	public function supply_data( array $data ): array {
		$state = $this->current_state();
		$code  = $this->error_code();

		return array(
			'state'          => $state,
			'error'          => $code,
			'message'        => self::message_for( $code ),
			'invalid'        => self::invalid_for( $code ),
			'email'          => $this->submitted_email(),
			'redirect'       => $this->redirect(),
			'signup_offered' => $this->signup_offered(),
			'minimum'        => self::PASSWORD_MINIMUM,
			'action_url'     => admin_url( 'admin-post.php' ),
			'nonce'          => wp_create_nonce( Auth_Action::ACTION ),
		);
	}

	/**
	 * Whether this site lets people sign themselves up.
	 *
	 * Only `registration` does. Under `admin` and `purchase` the sign-up state is not drawn and nothing links to it, because a page must never offer a route that does not exist.
	 */
	public function signup_offered(): bool {
		return Settings::ACCOUNT_CREATION_REGISTRATION === $this->settings->account_creation();
	}

	/**
	 * Which state the URL asks for, refusing sign-up when it is not offered.
	 *
	 * `sent` is reached by its own argument rather than a state value, because it is where the reset form lands and a reload of it must not re-send.
	 */
	public function current_state(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display state only; nothing is written here.
		if ( '1' === (string) ( $_GET[ Auth_Url::ARG_SENT ] ?? '' ) ) {
			return Auth_Url::STATE_SENT;
		}

		$state = sanitize_key( (string) ( $_GET[ Auth_Url::ARG_STATE ] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( Auth_Url::STATE_RESET === $state ) {
			return Auth_Url::STATE_RESET;
		}

		if ( Auth_Url::STATE_SIGNUP === $state && $this->signup_offered() ) {
			return Auth_Url::STATE_SIGNUP;
		}

		return Auth_Url::STATE_SIGNIN;
	}

	/**
	 * The failure code the handler redirected back with, '' for none.
	 */
	private function error_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only.
		return sanitize_key( (string) ( $_GET[ Auth_Action::ARG_ERROR ] ?? '' ) );
	}

	/**
	 * The address they typed, so a failed attempt does not make them type it again. The password is never carried back, since it would be in the URL.
	 */
	private function submitted_email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only.
		return sanitize_email( (string) ( $_GET[ Auth_Action::ARG_EMAIL ] ?? '' ) );
	}

	/**
	 * Where to go once they are in, validated against this site.
	 *
	 * `wp_validate_redirect()` with an empty fallback means an off-site value becomes no redirect at all rather than a redirect elsewhere.
	 */
	private function redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display state only.
		$raw = (string) ( $_GET[ Auth_Url::ARG_REDIRECT ] ?? '' );

		return '' === $raw ? '' : wp_validate_redirect( $raw, '' );
	}

	/**
	 * The notice text for a failure code.
	 *
	 * A failed sign-in never says which half was wrong, because that would confirm whether an address has an account.
	 *
	 * @param string $code The failure code, '' for none.
	 */
	public static function message_for( string $code ): string {
		$messages = array(
			'credentials'    => __( "That email and password don't match.", 'gated-media-access' ),
			'email_invalid'  => __( 'That does not look like an email address.', 'gated-media-access' ),
			'email_taken'    => __( 'That email already has an account. Sign in instead.', 'gated-media-access' ),
			'password_short' => sprintf(
				/* translators: %d: the minimum number of characters. */
				__( 'Passwords need at least %d characters.', 'gated-media-access' ),
				self::PASSWORD_MINIMUM
			),
			'signup_closed'  => __( 'Accounts are not created here. Ask the site owner for one.', 'gated-media-access' ),
			'expired'        => __( 'That link has expired. Ask for another.', 'gated-media-access' ),
		);

		return $messages[ $code ] ?? '';
	}

	/**
	 * Which fields wear the invalid treatment for a failure code.
	 *
	 * A failed sign-in marks **both** fields, because marking only one would say which half was wrong.
	 *
	 * @param string $code The failure code, '' for none.
	 * @return array<int, string>
	 */
	public static function invalid_for( string $code ): array {
		$fields = array(
			'credentials'    => array( 'email', 'password' ),
			'email_invalid'  => array( 'email' ),
			'email_taken'    => array( 'email' ),
			'password_short' => array( 'password' ),
		);

		return $fields[ $code ] ?? array();
	}
}
