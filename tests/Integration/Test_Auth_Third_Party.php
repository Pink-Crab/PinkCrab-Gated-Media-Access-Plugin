<?php
/**
 * A third-party login plugin meeting this plugin's own auth pages.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Exception;
use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Auth\Auth_Action;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * A captcha or two-factor plugin knows nothing about this plugin. It hooks core: `login_form` and `register_form` to draw its field, `authenticate` and `registration_errors` to refuse without it.
 *
 * Fourteen of them were read to pick those four hooks. Eleven hook `authenticate`, ten `login_form`, seven `registration_errors`, six `register_form`.
 *
 * `authenticate` already reaches this plugin, because signing in goes through `wp_signon()`. That is the dangerous half: the check runs whether or not the field was ever drawn, so a form that does not draw it refuses everybody.
 *
 * @group integration
 */
class Test_Auth_Third_Party extends WP_UnitTestCase {

	/** The field the fake plugin draws and demands. */
	private const FIELD = 'fake_2fa_token';

	/** The only value it accepts. */
	private const TOKEN = 'correct-horse';

	/** Where the last redirect was aimed. */
	private string $captured = '';

	public function set_up(): void {
		parent::set_up();

		$this->captured = '';

		// The handlers exit after redirecting, which would take the runner with them, so throw from the filter first.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->captured = (string) $location;

				throw new Exception( 'redirected' );
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		remove_all_actions( 'login_form' );
		remove_all_actions( 'register_form' );
		remove_all_filters( 'authenticate' );
		remove_all_filters( 'registration_errors' );
		remove_all_filters( 'gatedmedia_auth_fields' );
		remove_all_filters( 'gatedmedia_auth_signup_errors' );
		delete_option( Settings::OPTION );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * The fake plugin: draws a hidden field into core's forms, refuses without it.
	 */
	private function fake_two_factor(): void {
		$field = static function (): void {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( self::FIELD ) );
		};

		add_action( 'login_form', $field );
		add_action( 'register_form', $field );

		// Priority 21, as every one of the real ones does, so it runs after core has found the user.
		add_filter(
			'authenticate',
			static function ( $user ) {
				$sent = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

				return self::TOKEN === $sent ? $user : new WP_Error( 'fake_2fa', 'Two factor check failed.' );
			},
			21
		);

		add_filter(
			'registration_errors',
			static function ( $errors ) {
				$sent = isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';

				if ( self::TOKEN !== $sent && $errors instanceof WP_Error ) {
					$errors->add( 'fake_2fa', 'Two factor check failed.' );
				}

				return $errors;
			}
		);
	}

	/**
	 * The site's own glue: re-publishes core's two rendering hooks inside our form, and asks core's registration hook about a sign-up.
	 *
	 * Nothing here is specific to the plugin above, which is the point. `tests/e2e/fixtures/auth-bridge` is the same thing as a real plugin.
	 */
	private function bridge(): void {
		add_filter(
			'gatedmedia_auth_fields',
			static function ( string $fields, string $state ): string {
				ob_start();
				do_action( Auth_Url::STATE_SIGNUP === $state ? 'register_form' : 'login_form' );

				return $fields . (string) ob_get_clean();
			},
			10,
			2
		);

		add_filter(
			'gatedmedia_auth_signup_errors',
			static function ( $errors, string $email ) {
				$theirs = apply_filters( 'registration_errors', new WP_Error(), $email, $email );

				return $theirs instanceof WP_Error && $theirs->has_errors() ? $theirs : $errors;
			},
			10,
			2
		);
	}

	/**
	 * Runs something that redirects and hands back where it aimed.
	 *
	 * @param callable $run The thing that redirects.
	 */
	private function capture( callable $run ): string {
		try {
			$run();
		} catch ( Exception $e ) {
			return $this->captured;
		}

		$this->fail( 'Expected a redirect and got none.' );
	}

	/**
	 * An account to sign in as.
	 *
	 * @return array{0: string, 1: string} Email and password.
	 */
	private function account(): array {
		$password = 'a-long-enough-password';

		self::factory()->user->create(
			array(
				'user_login' => 'jane@example.com',
				'user_email' => 'jane@example.com',
				'user_pass'  => $password,
			)
		);

		return array( 'jane@example.com', $password );
	}

	/**
	 * @testdox A two-factor plugin refuses a sign-in whose field was never drawn.
	 *
	 * This is the state of things today, and the reason the rest of this file exists: the check runs on `authenticate` whatever the form looked like.
	 */
	public function test_a_signin_without_the_field_is_refused(): void {
		[ $email, $password ] = $this->account();

		$this->fake_two_factor();

		$_POST['email']              = $email;
		$_POST['password']           = $password;
		$_POST[ Auth_Action::INTENT_FIELD ] = Auth_Url::STATE_SIGNIN;
		$_POST['_wpnonce']                  = wp_create_nonce( Auth_Action::ACTION );
		// check_admin_referer() reads $_REQUEST, which setting $_POST does not fill.
		$_REQUEST['_wpnonce']               = $_POST['_wpnonce'];

		$url = $this->capture( array( new Auth_Action( new Settings() ), 'handle' ) );

		$this->assertStringContainsString( Auth_Action::ARG_ERROR, $url );
		$this->assertSame( 0, get_current_user_id() );
	}

	/** @testdox With the field filled in, the same sign-in is allowed through. */
	public function test_a_signin_carrying_the_field_is_allowed(): void {
		[ $email, $password ] = $this->account();

		$this->fake_two_factor();

		$_POST['email']              = $email;
		$_POST['password']           = $password;
		$_POST[ Auth_Action::INTENT_FIELD ] = Auth_Url::STATE_SIGNIN;
		$_POST[ self::FIELD ]        = self::TOKEN;
		$_POST['_wpnonce']                  = wp_create_nonce( Auth_Action::ACTION );
		// check_admin_referer() reads $_REQUEST, which setting $_POST does not fill.
		$_REQUEST['_wpnonce']               = $_POST['_wpnonce'];

		$url = $this->capture( array( new Auth_Action( new Settings() ), 'handle' ) );

		$this->assertStringNotContainsString( Auth_Action::ARG_ERROR, $url );
	}

	/** @testdox The sign-in form draws whatever a third party puts on core's login form. */
	public function test_the_signin_form_draws_the_third_party_field(): void {
		$this->fake_two_factor();
		$this->bridge();

		$this->assertStringContainsString( self::FIELD, $this->rendered( Auth_Url::STATE_SIGNIN ) );
	}

	/** @testdox The sign-up form draws whatever a third party puts on core's registration form. */
	public function test_the_signup_form_draws_the_third_party_field(): void {
		$this->fake_two_factor();
		$this->bridge();

		$this->assertStringContainsString( self::FIELD, $this->rendered( Auth_Url::STATE_SIGNUP ) );
	}

	/** @testdox A sign-up missing the third party's field creates no account. */
	public function test_a_signup_without_the_field_creates_nothing(): void {
		$this->fake_two_factor();
		$this->bridge();

		$_POST['email']              = 'new@example.com';
		$_POST['password']           = 'a-long-enough-password';
		$_POST[ Auth_Action::INTENT_FIELD ] = Auth_Url::STATE_SIGNUP;
		$_POST['_wpnonce']                  = wp_create_nonce( Auth_Action::ACTION );
		// check_admin_referer() reads $_REQUEST, which setting $_POST does not fill.
		$_REQUEST['_wpnonce']               = $_POST['_wpnonce'];

		$url = $this->capture( array( new Auth_Action( new Settings() ), 'handle' ) );

		$this->assertStringContainsString( Auth_Action::ARG_ERROR, $url );
		$this->assertFalse( email_exists( 'new@example.com' ) );
	}

	/** @testdox A sign-up carrying the field creates the account. */
	public function test_a_signup_with_the_field_creates_the_account(): void {
		$this->fake_two_factor();
		$this->bridge();

		$_POST['email']              = 'new@example.com';
		$_POST['password']           = 'a-long-enough-password';
		$_POST[ Auth_Action::INTENT_FIELD ] = Auth_Url::STATE_SIGNUP;
		$_POST[ self::FIELD ]        = self::TOKEN;
		$_POST['_wpnonce']                  = wp_create_nonce( Auth_Action::ACTION );
		// check_admin_referer() reads $_REQUEST, which setting $_POST does not fill.
		$_REQUEST['_wpnonce']               = $_POST['_wpnonce'];

		$this->capture( array( new Auth_Action( new Settings() ), 'handle' ) );

		$this->assertIsInt( email_exists( 'new@example.com' ) );
	}

	/**
	 * @testdox In core mode none of this is our problem: every link goes to wp-login.php.
	 *
	 * The setting exists for sites that would rather have core's page and every plugin that hooks it, styling and all.
	 */
	public function test_core_mode_hands_the_whole_thing_to_wp_login(): void {
		update_option( Settings::OPTION, array( 'auth_pages' => Settings::AUTH_PAGES_CORE ) );

		$this->assertSame( wp_login_url(), Auth_Url::signin() );
		$this->assertSame( wp_registration_url(), Auth_Url::signup() );
	}

	/**
	 * The auth view's markup for one state.
	 *
	 * @param string $state One of the Auth_Url STATE_* values.
	 */
	private function rendered( string $state ): string {
		if ( Auth_Url::STATE_SIGNIN !== $state ) {
			$_GET[ Auth_Url::ARG_STATE ] = $state;
		}

		$markup = do_blocks( '<!-- wp:gated-media-access/auth /-->' );

		unset( $_GET[ Auth_Url::ARG_STATE ] );

		return $markup;
	}
}
