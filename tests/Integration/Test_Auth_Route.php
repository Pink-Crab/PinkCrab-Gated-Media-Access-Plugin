<?php
/**
 * The auth route.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Auth\Auth_Route;
use PinkCrab\Gated_Access\Auth\Auth_State;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * `Account_Route`'s mirror image: this one answers when signed **out**, the exact case that route refuses, and an auth page under `/account/` would be bounced by the `require_login()` it exists to fix.
 *
 * One rule serves all four states because the state is a query argument, so the assertions are that the URL resolves, is not a 404, and still resolves with a state on it.
 *
 * @group integration
 */
class Test_Auth_Route extends WP_UnitTestCase {

	/**
	 * Pretty permalinks, so rewrite rules are consulted at all: the test install defaults to plain ones, where every rule is ignored.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_rewrite;

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();
	}

	/**
	 * Puts the permalink structure back, or later tests inherit it.
	 */
	public function tear_down(): void {
		global $wp_rewrite;

		remove_all_filters( 'gatedmedia_auth_slug' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();

		parent::tear_down();
	}

	/** @testdox The route's flag is added to the public query var list. */
	public function test_registers_its_query_var(): void {
		$this->assertContains( Auth_Route::QUERY_FLAG, apply_filters( 'query_vars', array() ) );
	}

	/** @testdox The auth URL resolves for a signed-out visitor, which is the whole point of it. */
	public function test_the_route_answers_signed_out(): void {
		wp_set_current_user( 0 );

		$this->go_to( Auth_Url::signin() );

		$this->assertSame( '1', (string) get_query_var( Auth_Route::QUERY_FLAG ) );
		$this->assertFalse( is_404() );
	}

	/**
	 * @testdox Every state resolves from the one rule, because the state is a query argument rather than a route.
	 *
	 * @dataProvider state_urls
	 *
	 * @param string $url The state's URL.
	 */
	public function test_every_state_resolves( string $url ): void {
		wp_set_current_user( 0 );

		$this->go_to( $url );

		$this->assertSame( '1', (string) get_query_var( Auth_Route::QUERY_FLAG ) );
		$this->assertFalse( is_404() );
	}

	/**
	 * The four auth states.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function state_urls(): array {
		return array(
			'sign in' => array( Auth_Url::signin() ),
			'sign up' => array( Auth_Url::signup() ),
			'reset'   => array( Auth_Url::reset() ),
			'sent'    => array( Auth_Url::sent() ),
		);
	}

	/** @testdox A destination on the URL does not stop the route resolving. */
	public function test_a_destination_does_not_break_the_route(): void {
		wp_set_current_user( 0 );

		$this->go_to( Auth_Url::signin( home_url( '/access/abc/' ) ) );

		$this->assertSame( '1', (string) get_query_var( Auth_Route::QUERY_FLAG ) );
	}

	/**
	 * @testdox Renaming the segment moves the route with it.
	 *
	 * The rule was added on `init` with the default segment and flushing only rebuilds what is registered, so the route registers itself again for the filtered segment to exist.
	 */
	public function test_the_slug_filter_moves_the_route(): void {
		add_filter( 'gatedmedia_auth_slug', static fn(): string => 'login' );

		global $wp_rewrite;

		$wp_rewrite->rules = array();

		( new Auth_Route(
			new Auth_State( new Settings() ),
			$this->createMock( Asset_Loader::class ),
			$this->createMock( Sprite::class )
		) )->register_rewrites();

		$wp_rewrite->flush_rules();

		$this->go_to( home_url( '/login/' ) );
		$this->assertSame( '1', (string) get_query_var( Auth_Route::QUERY_FLAG ) );
	}

	/** @testdox The page title changes with the state, because the block's h1 and the document title are both printed from it. */
	public function test_the_title_follows_the_state(): void {
		$this->assertSame( 'Sign in', Auth_Route::title_for( Auth_Url::STATE_SIGNIN ) );
		$this->assertSame( 'Create your account', Auth_Route::title_for( Auth_Url::STATE_SIGNUP ) );
		$this->assertSame( 'Reset your password', Auth_Route::title_for( Auth_Url::STATE_RESET ) );
		$this->assertSame( 'Check your email', Auth_Route::title_for( Auth_Url::STATE_SENT ) );
	}

	/** @testdox A state nobody recognises is titled as sign in rather than left blank. */
	public function test_an_unknown_state_still_has_a_title(): void {
		$this->assertSame( 'Sign in', Auth_Route::title_for( 'nonsense' ) );
	}
}
