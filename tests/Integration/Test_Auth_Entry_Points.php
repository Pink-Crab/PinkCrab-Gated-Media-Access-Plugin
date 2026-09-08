<?php
/**
 * Every place the plugin sends someone to sign in.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Exception;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Account_Route;
use PinkCrab\Gated_Access\Account\Profile_Writer;
use PinkCrab\Gated_Access\Auth\Auth_Action;
use PinkCrab\Gated_Access\Auth\Auth_Route;
use PinkCrab\Gated_Access\Auth\Auth_State;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * Four places called `wp_login_url()` and all four have moved.
 *
 * The bug this closes was two of them being the *same* destination: on a site whose `users_can_register` was `0`, the product page's "Create an account to continue" and "Already have an account? Sign in" both went to wp-login.php.
 *
 * wp-login.php itself is untouched: these assert where our own pages point.
 *
 * @group integration
 */
class Test_Auth_Entry_Points extends WP_UnitTestCase {

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
		remove_all_filters( 'gatedmedia_account_creation' );
		remove_all_filters( 'gatedmedia_account_route' );
		remove_all_filters( 'gatedmedia_account_url' );
		remove_all_filters( 'gatedmedia_profile_prompt' );
		delete_option( Settings::OPTION );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * `Profile_Writer::referer()` falls back to the profile section with no referer on the request, so with the route off it fell back to a page that no longer answers.
	 *
	 * @testdox With the account route off, a saved profile does not return to a page that no longer answers.
	 */
	public function test_the_profile_save_returns_somewhere_that_answers(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );

		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$_POST['_wpnonce'] = wp_create_nonce( Profile_Writer::ACTION );

		$url = $this->capture( array( new Profile_Writer(), 'handle' ) );

		$this->assertStringNotContainsString( '/account/', $url );
	}

	/**
	 * A site placing the blocks on its own pages must be obeyed here too, and this destination used to reach the home page without asking `Account_Url`.
	 *
	 * @testdox With the account route off, a signed-in visitor is sent to the site's own account page.
	 */
	public function test_the_signed_in_destination_follows_the_url_filter(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );
		add_filter( 'gatedmedia_account_url', static fn(): string => home_url( '/members-area/' ) );

		wp_set_current_user( self::factory()->user->create() );

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();

		$this->go_to( home_url( '/sign-in/' ) );
		set_query_var( Auth_Route::QUERY_FLAG, '1' );

		$url = $this->capture( array( $this->auth_route(), 'send_signed_in_away' ) );

		$this->assertSame( home_url( '/members-area/' ), $url );

		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
	}

	/** @testdox With the route off, a signed-in visitor's landing place follows the site's own pages. */
	public function test_the_signed_in_landing_follows_the_url_filter(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );
		add_filter( 'gatedmedia_account_url', static fn(): string => home_url( '/members-area/' ) );

		wp_set_current_user( self::factory()->user->create() );

		$url = $this->capture( array( new Auth_Action( new Settings() ), 'handle_signed_in' ) );

		$this->assertSame( home_url( '/members-area/' ), $url );
	}

	/**
	 * The profile prompt's destination never consulted the setting, so it pointed at the route whether or not the route existed.
	 *
	 * @testdox With the route off, the profile prompt does not send a new user to a page that no longer answers.
	 */
	public function test_the_profile_prompt_destination_follows_the_setting(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );
		add_filter( 'gatedmedia_profile_prompt', '__return_true' );

		wp_set_current_user( self::factory()->user->create() );

		$url = $this->capture( array( new Auth_Action( new Settings() ), 'handle_signed_in' ) );

		$this->assertStringNotContainsString( '/account/', $url );
	}

	/**
	 * The auth route, built the way the container builds it.
	 */
	private function auth_route(): Auth_Route {
		return new Auth_Route(
			new Auth_State( new Settings() ),
			$this->createMock( \PinkCrab\Gated_Access\Assets\Asset_Loader::class ),
			$this->createMock( \PinkCrab\Gated_Access\Blocks\Sprite::class )
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

	/** @testdox A signed-out buy submit goes to sign up, carrying the product so they land back on it. */
	public function test_the_buy_submit_goes_to_signup(): void {
		$product = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );

		$_POST['gatedmedia_product'] = (string) $product;

		$action = new Checkout_Action(
			$this->createMock( \PinkCrab\Gated_Access\Payments\Checkout::class ),
			new Settings()
		);

		$url = $this->capture( array( $action, 'require_login' ) );

		$this->assertStringContainsString( home_url( '/sign-in/' ), $url );
		$this->assertStringContainsString( 'state=signup', $url );
		$this->assertStringContainsString( 'redirect_to=', $url );
		$this->assertStringNotContainsString( 'wp-login', $url );
	}

	/** @testdox An applied coupon rides through with it, rather than being dropped on the way. */
	public function test_the_coupon_survives_the_round_trip(): void {
		$product = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );

		$_POST['gatedmedia_product']            = (string) $product;
		$_POST[ Checkout_Action::COUPON_FIELD ] = 'SAVE20';

		$action = new Checkout_Action(
			$this->createMock( \PinkCrab\Gated_Access\Payments\Checkout::class ),
			new Settings()
		);

		$url = $this->capture( array( $action, 'require_login' ) );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		$this->assertStringContainsString( 'SAVE20', (string) ( $args[ Auth_Url::ARG_REDIRECT ] ?? '' ) );
	}

	/** @testdox Where the site does not create accounts on the front end, the same submit goes to sign in rather than offering a sign-up that cannot happen. */
	public function test_the_buy_submit_goes_to_signin_when_signup_is_closed(): void {
		add_filter( 'gatedmedia_account_creation', static fn(): string => Settings::ACCOUNT_CREATION_ADMIN );

		$product = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );

		$_POST['gatedmedia_product'] = (string) $product;

		$action = new Checkout_Action(
			$this->createMock( \PinkCrab\Gated_Access\Payments\Checkout::class ),
			new Settings()
		);

		$url = $this->capture( array( $action, 'require_login' ) );

		$this->assertStringNotContainsString( 'state=signup', $url );
		$this->assertStringContainsString( home_url( '/sign-in/' ), $url );
	}

	/** @testdox A signed-out profile save goes to sign in, and comes back to the profile. */
	public function test_the_profile_save_goes_to_signin(): void {
		wp_set_current_user( 0 );

		$url = $this->capture( array( new Profile_Writer(), 'handle' ) );

		$this->assertStringContainsString( home_url( '/sign-in/' ), $url );
		$this->assertStringContainsString( 'redirect_to=', $url );
		$this->assertStringNotContainsString( 'wp-login', $url );
	}

	/** @testdox A signed-out visitor reaching the account area goes to sign in, and comes back to where they were headed. */
	public function test_the_account_area_goes_to_signin(): void {
		wp_set_current_user( 0 );

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();

		$this->go_to( home_url( '/account/orders/' ) );

		$route = $this->account_route();

		$url = $this->capture( array( $route, 'require_login' ) );

		$this->assertStringContainsString( home_url( '/sign-in/' ), $url );
		$this->assertStringNotContainsString( 'wp-login', $url );

		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
	}

	/**
	 * The account route, built the way the container builds it.
	 */
	private function account_route(): Account_Route {
		return new Account_Route(
			new \PinkCrab\Gated_Access\Account\Section_Registry(),
			$this->createMock( \PinkCrab\Gated_Access\Account\Account_Renderer::class ),
			$this->createMock( \PinkCrab\Gated_Access\Assets\Asset_Loader::class ),
			$this->createMock( \PinkCrab\Gated_Access\Blocks\Sprite::class ),
			new Settings()
		);
	}
}
