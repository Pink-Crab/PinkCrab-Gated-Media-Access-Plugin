<?php
/**
 * Where the site's own way in lives.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * One view in four states, so one URL with the state on it: sign in is the bare URL, and the value `signin` must never appear in a link or that address has two spellings.
 *
 * The destination is the part with teeth: it is carried through sign-up so a buyer lands back on the product they wanted, and it arrives from a form, so it is encoded in and validated out.
 *
 * @group integration
 */
class Test_Auth_Url extends WP_UnitTestCase {

	/**
	 * The filter is global, so a test that adds one has to take it away again.
	 */
	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_auth_slug' );
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Puts the site into core mode.
	 */
	private function use_core_pages(): void {
		update_option( Settings::OPTION, array( 'auth_pages' => Settings::AUTH_PAGES_CORE ) );
	}

	/** @testdox Plugin pages are the default, so an untouched site keeps its own sign-in. */
	public function test_plugin_pages_are_the_default(): void {
		$this->assertSame( Settings::AUTH_PAGES_PLUGIN, ( new Settings() )->auth_pages() );
		$this->assertSame( home_url( '/sign-in/' ), Auth_Url::signin() );
	}

	/** @testdox In core mode sign in is wp-login.php. */
	public function test_core_mode_signin_is_wp_login(): void {
		$this->use_core_pages();

		$this->assertSame( wp_login_url(), Auth_Url::signin() );
	}

	/** @testdox In core mode sign up is core's registration URL. */
	public function test_core_mode_signup_is_the_core_registration_url(): void {
		$this->use_core_pages();

		$this->assertSame( wp_registration_url(), Auth_Url::signup() );
	}

	/** @testdox In core mode the reset state is core's lost password URL. */
	public function test_core_mode_reset_is_the_core_lost_password_url(): void {
		$this->use_core_pages();

		$this->assertStringContainsString( 'action=lostpassword', Auth_Url::reset() );
	}

	/** @testdox In core mode the destination is still carried, under core's own argument. */
	public function test_core_mode_carries_the_destination(): void {
		$this->use_core_pages();

		$destination = home_url( '/account/orders/' );

		// Raw, not encoded: core's own wp_login_url() adds it the same way, and so does the plugin's own state().
		$this->assertSame( wp_login_url( $destination ), Auth_Url::signin( $destination ) );
		$this->assertStringContainsString( $destination, Auth_Url::signup( $destination ) );
	}

	/** @testdox In core mode with no destination, nothing is added. */
	public function test_core_mode_without_a_destination_adds_nothing(): void {
		$this->use_core_pages();

		$this->assertStringNotContainsString( 'redirect_to', Auth_Url::signin() );
	}

	/** @testdox An unknown stored value falls back to the plugin's own pages rather than half a mode. */
	public function test_an_unknown_mode_falls_back(): void {
		update_option( Settings::OPTION, array( 'auth_pages' => 'nonsense' ) );

		$this->assertSame( Settings::AUTH_PAGES_PLUGIN, ( new Settings() )->auth_pages() );
		$this->assertSame( home_url( '/sign-in/' ), Auth_Url::signin() );
	}

	/** @testdox The slug filter is irrelevant in core mode, since none of the links are ours. */
	public function test_core_mode_ignores_the_slug_filter(): void {
		$this->use_core_pages();

		add_filter( 'gatedmedia_auth_slug', static fn (): string => 'members' );

		$this->assertStringNotContainsString( 'members', Auth_Url::signin() );
	}

	/** @testdox Sign in is the bare URL, carrying no state argument at all. */
	public function test_signin_is_the_bare_url(): void {
		$this->assertSame( home_url( '/sign-in/' ), Auth_Url::signin() );
		$this->assertStringNotContainsString( 'state=', Auth_Url::signin() );
	}

	/** @testdox Sign up and reset each name their state; the sent state is reached by its own argument rather than a state value. */
	public function test_the_other_states_name_themselves(): void {
		$this->assertStringContainsString( 'state=signup', Auth_Url::signup() );
		$this->assertStringContainsString( 'state=reset', Auth_Url::reset() );

		// Sent is the reset state plus its flag, so reloading it cannot re-send the email.
		$sent = Auth_Url::sent();
		$this->assertStringContainsString( 'state=reset', $sent );
		$this->assertStringContainsString( 'sent=1', $sent );
	}

	/** @testdox A destination rides along on every state, so an interrupted purchase survives the round trip. */
	public function test_the_destination_is_carried(): void {
		$product = home_url( '/access/ab12cd34-5678-90ef-ab12-cd34567890ef/' );

		foreach ( array( Auth_Url::signin( $product ), Auth_Url::signup( $product ), Auth_Url::reset( $product ) ) as $url ) {
			$this->assertStringContainsString( 'redirect_to=', $url );

			// Read back the way the page reads it: out of the query string, which is what `wp_validate_redirect()` is handed.
			$this->assertSame( $product, $this->redirect_in( $url ) );
		}
	}

	/** @testdox A destination carrying its own query string keeps it whole, which is how an applied coupon survives signing in. */
	public function test_a_destination_keeps_its_own_query_string(): void {
		$product = home_url( '/access/abc/?gatedmedia_coupon=SAVE20' );

		// The destination is one encoded value, so its own `?` cannot be read as an argument of the sign-in URL.
		$this->assertSame( $product, $this->redirect_in( Auth_Url::signin( $product ) ) );
	}

	/**
	 * The destination as the receiving page will see it.
	 *
	 * @param string $url A URL built by Auth_Url.
	 */
	private function redirect_in( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		return (string) ( $args[ Auth_Url::ARG_REDIRECT ] ?? '' );
	}

	/** @testdox An empty destination adds no argument rather than an empty one. */
	public function test_no_destination_adds_nothing(): void {
		$this->assertStringNotContainsString( 'redirect_to', Auth_Url::signin( '' ) );
		$this->assertStringNotContainsString( 'redirect_to', Auth_Url::signup( '' ) );
	}

	/** @testdox Renaming the segment moves every state's URL with it. */
	public function test_the_slug_filter_moves_every_link(): void {
		add_filter( 'gatedmedia_auth_slug', static fn(): string => 'login' );

		$this->assertSame( home_url( '/login/' ), Auth_Url::signin() );
		$this->assertStringContainsString( home_url( '/login/' ), Auth_Url::signup() );
		$this->assertStringContainsString( home_url( '/login/' ), Auth_Url::reset() );
	}

	/** @testdox A filter returning nothing leaves the links on the default segment rather than pointing them at another host. */
	public function test_an_empty_slug_falls_back(): void {
		add_filter( 'gatedmedia_auth_slug', static fn(): string => '' );

		$this->assertSame( home_url( '/sign-in/' ), Auth_Url::signin() );
	}

	/** @testdox A filter returning something that is not a string leaves the links on the default segment. */
	public function test_a_non_string_slug_falls_back(): void {
		add_filter( 'gatedmedia_auth_slug', static fn(): array => array( 'login' ) );

		$this->assertSame( home_url( '/sign-in/' ), Auth_Url::signin() );
	}

	/** @testdox A segment that would not survive sanitising is sanitised rather than used as typed. */
	public function test_the_slug_is_sanitised(): void {
		add_filter( 'gatedmedia_auth_slug', static fn(): string => 'Sign In Here' );

		$this->assertSame( home_url( '/sign-in-here/' ), Auth_Url::signin() );
	}
}
