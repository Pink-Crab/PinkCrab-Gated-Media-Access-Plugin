<?php
/**
 * What the auth view's form posts to.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Auth;

use WP_Error;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * One admin-post action for all three submits, told apart by an intent field, because the auth view is one view and one form.
 *
 * Thin on purpose, like `Checkout_Action`: read the form, do the one thing, redirect.
 *
 * Every failure comes back to the view as a code in the URL, which `Auth_State` turns into a notice, and nothing is rendered from here.
 *
 * **wp-login.php is untouched.** This does not replace it, filter `login_url` or redirect it, and the reset is core's `retrieve_password()` unchanged.
 *
 * So its emailed link still lands on wp-login.php and core still draws the screen that sets the new password, which is why there are four states and no fifth one for choosing a password.
 */
class Auth_Action implements Hookable {

	/** The admin-post action name, and the nonce it demands. */
	public const ACTION = 'gatedmedia_auth';

	/** Which of the three submits this is. */
	public const INTENT_FIELD = 'gatedmedia_auth_intent';

	/** The query flag a failed submit returns under. */
	public const ARG_ERROR = 'gatedmedia_auth_error';

	/** The address they typed, carried back so they need not retype it. */
	public const ARG_EMAIL = 'gatedmedia_auth_email';

	/**
	 * Settings decide whether signing up is allowed and whether a thin profile is prompted once they are in.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Signed out is the ordinary case, so the nopriv mirror matters most, but both are registered so an already signed-in poster is sent on rather than shown an admin-post error.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle_signed_in' ) );
	}

	/**
	 * Already signed in: nothing to do but send them where they were going.
	 */
	public function handle_signed_in(): void {
		$this->leave( $this->destination( get_current_user_id(), $this->redirect() ) );
	}

	/**
	 * One submit, routed by its intent field.
	 *
	 * The nonce is checked before anything is read, and an unrecognised intent is treated as a sign-in rather than fataling.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		$intent = isset( $_POST[ self::INTENT_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::INTENT_FIELD ] ) ) : '';

		if ( Auth_Url::STATE_SIGNUP === $intent ) {
			$this->sign_up();
			return;
		}

		if ( Auth_Url::STATE_RESET === $intent ) {
			$this->send_reset();
			return;
		}

		$this->sign_in();
	}

	/**
	 * Signs them in, or comes back saying only that it did not work.
	 */
	private function sign_in(): void {
		$email    = $this->posted_email();
		$password = $this->posted_password();
		$redirect = $this->redirect();

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( $user instanceof WP_Error ) {
			$this->refuse( Auth_Url::signin( $redirect ), 'credentials', $email );
			return;
		}

		$this->leave( $this->destination( $user->ID, $redirect ) );
	}

	/**
	 * Creates an account, when this site creates accounts that way.
	 */
	private function sign_up(): void {
		$email    = $this->posted_email();
		$password = $this->posted_password();
		$redirect = $this->redirect();
		$back     = Auth_Url::signup( $redirect );

		if ( Settings::ACCOUNT_CREATION_REGISTRATION !== $this->settings->account_creation() ) {
			$this->refuse( Auth_Url::signin( $redirect ), 'signup_closed', '' );
			return;
		}

		if ( false === is_email( $email ) ) {
			$this->refuse( $back, 'email_invalid', $email );
			return;
		}

		if ( false !== email_exists( $email ) ) {
			$this->refuse( $back, 'email_taken', $email );
			return;
		}

		if ( strlen( $password ) < Auth_State::PASSWORD_MINIMUM ) {
			$this->refuse( $back, 'password_short', $email );
			return;
		}

		/**
		 * Filters whether this sign-up may go ahead.
		 *
		 * Signing in is already covered, since `wp_signon()` fires core's `authenticate`. This is the sign-up counterpart, for a site re-publishing core's `registration_errors` here.
		 *
		 * @param WP_Error $errors Add to it to refuse.
		 * @param string   $email  The address being registered.
		 */
		$refusals = apply_filters( 'gatedmedia_auth_signup_errors', new WP_Error(), $email );

		if ( $refusals instanceof WP_Error && $refusals->has_errors() ) {
			$this->refuse( $back, 'signup_refused', $email );
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login' => $email,
				'user_email' => $email,
				'user_pass'  => $password,
				'role'       => get_option( 'default_role', 'subscriber' ),
			)
		);

		if ( $user_id instanceof WP_Error ) {
			$this->refuse( $back, 'email_taken', $email );
			return;
		}

		/**
		 * Fires after someone creates their own account from the front end.
		 *
		 * @param int $user_id The account just created.
		 */
		do_action( 'gatedmedia_account_created', (int) $user_id );

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, true, is_ssl() );

		$this->leave( $this->destination( (int) $user_id, $redirect ) );
	}

	/**
	 * Asks core to send a reset link, and says the same thing either way.
	 *
	 * `retrieve_password()` returns a `WP_Error` naming an address it does not recognise, and that answer is thrown away deliberately, because this must not confirm whether an address has an account, so the `sent` state is reached either way.
	 */
	private function send_reset(): void {
		$email = $this->posted_email();

		if ( false !== is_email( $email ) ) {
			retrieve_password( $email );
		}

		$this->leave( Auth_Url::sent( $this->redirect() ) );
	}

	/**
	 * Where someone lands once they are in.
	 *
	 * An explicit destination always wins, because interrupting a purchase to ask for a phone number would lose the sale. The profile prompt is for the plain arrival, and only when the profile is actually thin.
	 *
	 * @param int    $user_id  Who just signed in.
	 * @param string $redirect Their validated destination, '' for none.
	 */
	private function destination( int $user_id, string $redirect ): string {
		if ( '' !== $redirect ) {
			return $redirect;
		}

		if ( $this->settings->profile_prompt() && array() !== Profile_Writer::missing_for( $user_id ) ) {
			return add_query_arg( 'profile', 'complete', Account_Url::section( 'profile' ) );
		}

		return Account_Url::section( 'my-access' );
	}

	/**
	 * Back to the view with a code, and never with the password.
	 *
	 * @param string $url   The state to come back to.
	 * @param string $code  The failure code.
	 * @param string $email The address they typed, '' to carry nothing.
	 */
	private function refuse( string $url, string $code, string $email ): void {
		$url = add_query_arg( self::ARG_ERROR, $code, $url );

		if ( '' !== $email ) {
			$url = add_query_arg( self::ARG_EMAIL, rawurlencode( $email ), $url );
		}

		$this->leave( $url );
	}

	/**
	 * The redirect, and the `exit` that has to follow it.
	 *
	 * Required rather than tidy: a `wp_safe_redirect()` that does not halt emits a body after the Location header, and on admin-post.php the redirect may then not be honoured at all.
	 *
	 * @param string $url Where to send them.
	 */
	private function leave( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The posted address.
	 */
	private function posted_email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in handle().
		return isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	}

	/**
	 * The posted password, unsanitised on purpose, since `sanitize_text_field()` would silently change it.
	 */
	private function posted_password(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A password is hashed, never rendered; sanitising would alter it.
		return isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
	}

	/**
	 * The posted destination, validated against this site.
	 *
	 * `handle_signed_in()` reaches this with no nonce checked, deliberately: nonces are bound to a user, so someone who signed in on another tab would fail the check on the very submit this exists to smooth over.
	 *
	 * Nothing is written from that path, and an off-site value becomes no redirect at all rather than a redirect elsewhere.
	 */
	private function redirect(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nothing is written; wp_validate_redirect() below is the guard.
		$raw = isset( $_POST[ Auth_Url::ARG_REDIRECT ] ) ? (string) wp_unslash( $_POST[ Auth_Url::ARG_REDIRECT ] ) : '';

		return '' === $raw ? '' : wp_validate_redirect( $raw, '' );
	}
}
