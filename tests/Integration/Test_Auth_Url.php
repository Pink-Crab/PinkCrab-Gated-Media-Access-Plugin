<?php
/**
 * Where the site's own way in lives.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
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

		parent::tear_down();
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
