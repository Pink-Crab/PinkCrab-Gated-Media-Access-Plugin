<?php
/**
 * Which of the four states the auth view is in.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Auth\Auth_Action;
use PinkCrab\Gated_Access\Auth\Auth_State;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * The state comes from the URL, so this is where a hostile or nonsense URL is turned into something safe to draw.
 *
 * The two that matter: an unrecognised state is sign in rather than nothing, and `?state=signup` on a site that does not create accounts that way is sign in too.
 *
 * @group integration
 */
class Test_Auth_State extends WP_UnitTestCase {

	private Auth_State $state;

	public function set_up(): void {
		parent::set_up();

		$this->state = new Auth_State( new Settings() );
	}

	public function tear_down(): void {
		$_GET = array();

		remove_all_filters( 'gatedmedia_account_creation' );
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/** @testdox A URL with nothing on it is the sign-in state. */
	public function test_a_bare_url_is_sign_in(): void {
		$this->assertSame( Auth_Url::STATE_SIGNIN, $this->state->current_state() );
	}

	/** @testdox A state nobody recognises is sign in rather than a blank page. */
	public function test_an_unknown_state_is_sign_in(): void {
		$_GET[ Auth_Url::ARG_STATE ] = 'delete-my-account';

		$this->assertSame( Auth_Url::STATE_SIGNIN, $this->state->current_state() );
	}

	/** @testdox Sign up and reset are reached by naming themselves. */
	public function test_the_named_states(): void {
		$_GET[ Auth_Url::ARG_STATE ] = Auth_Url::STATE_SIGNUP;
		$this->assertSame( Auth_Url::STATE_SIGNUP, $this->state->current_state() );

		$_GET[ Auth_Url::ARG_STATE ] = Auth_Url::STATE_RESET;
		$this->assertSame( Auth_Url::STATE_RESET, $this->state->current_state() );
	}

	/** @testdox The sent flag wins over whatever state the URL also names, so the confirmation cannot be talked out of showing. */
	public function test_sent_wins(): void {
		$_GET[ Auth_Url::ARG_SENT ]  = '1';
		$_GET[ Auth_Url::ARG_STATE ] = Auth_Url::STATE_RESET;

		$this->assertSame( Auth_Url::STATE_SENT, $this->state->current_state() );
	}

	/** @testdox Asking for sign up on a site where an admin creates accounts lands on sign in instead. */
	public function test_signup_is_refused_when_it_is_not_offered(): void {
		add_filter( 'gatedmedia_account_creation', static fn(): string => Settings::ACCOUNT_CREATION_ADMIN );

		$_GET[ Auth_Url::ARG_STATE ] = Auth_Url::STATE_SIGNUP;

		$this->assertFalse( $this->state->signup_offered() );
		$this->assertSame( Auth_Url::STATE_SIGNIN, $this->state->current_state() );
	}

	/** @testdox Sign up is offered under registration and refused under both admin and purchase. */
	public function test_which_routes_offer_signup(): void {
		$offered = array(
			Settings::ACCOUNT_CREATION_REGISTRATION => true,
			Settings::ACCOUNT_CREATION_ADMIN        => false,
			Settings::ACCOUNT_CREATION_PURCHASE     => false,
		);

		foreach ( $offered as $route => $expected ) {
			remove_all_filters( 'gatedmedia_account_creation' );
			add_filter( 'gatedmedia_account_creation', static fn(): string => (string) $route );

			$this->assertSame( $expected, $this->state->signup_offered(), $route );
		}
	}

	/** @testdox A failed sign-in marks both fields invalid, because marking one would say which half was wrong. */
	public function test_a_failed_signin_marks_both_fields(): void {
		$this->assertSame( array( 'email', 'password' ), Auth_State::invalid_for( 'credentials' ) );
	}

	/** @testdox The failed sign-in message names neither the address nor the password as the wrong one. */
	public function test_the_signin_message_confirms_nothing(): void {
		$message = Auth_State::message_for( 'credentials' );

		$this->assertNotSame( '', $message );
		$this->assertStringNotContainsString( 'account', strtolower( $message ) );
		$this->assertStringNotContainsString( 'no such', strtolower( $message ) );
	}

	/** @testdox A code nobody recognises produces no notice and no invalid fields rather than an empty red box. */
	public function test_an_unknown_code_says_nothing(): void {
		$this->assertSame( '', Auth_State::message_for( 'wat' ) );
		$this->assertSame( array(), Auth_State::invalid_for( 'wat' ) );
	}

	/** @testdox The password minimum in the message is the same one the handler enforces. */
	public function test_the_stated_minimum_is_the_enforced_one(): void {
		$this->assertStringContainsString( (string) Auth_State::PASSWORD_MINIMUM, Auth_State::message_for( 'password_short' ) );
	}

	/**
	 * @testdox An off-site destination is dropped rather than carried, so the form cannot be turned into an open redirect.
	 *
	 * Not `example.org`, which is `WP_TESTS_DOMAIN` and would rightly be kept.
	 */
	public function test_an_offsite_destination_is_dropped(): void {
		$_GET[ Auth_Url::ARG_REDIRECT ] = 'https://somewhere-else.invalid/phish';

		$data = $this->state->supply_data( array() );

		$this->assertSame( '', $data['redirect'] );
	}

	/** @testdox A destination on this site is carried through. */
	public function test_an_onsite_destination_is_kept(): void {
		$product = home_url( '/access/abc/' );

		$_GET[ Auth_Url::ARG_REDIRECT ] = $product;

		$data = $this->state->supply_data( array() );

		$this->assertSame( $product, $data['redirect'] );
	}

	/** @testdox The address typed into a failed attempt comes back with it; the password never does. */
	public function test_the_address_comes_back_but_not_the_password(): void {
		$_GET[ Auth_Action::ARG_ERROR ] = 'credentials';
		$_GET[ Auth_Action::ARG_EMAIL ] = 'jane@example.com';

		$data = $this->state->supply_data( array() );

		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertArrayNotHasKey( 'password', $data );
	}
}
