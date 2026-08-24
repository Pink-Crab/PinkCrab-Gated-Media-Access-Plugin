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
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * Four places called `wp_login_url()` and all four have moved.
 *
 * The bug this closes was two of them being the *same* destination: on the
 * product page, "Create an account to continue" and "Already have an account?
 * Sign in" both went to wp-login.php, on a site whose `users_can_register` was
 * `0`. One control said something it could not do, and the other said the same
 * thing in different words.
 *
 * wp-login.php itself is untouched — these assert where our own pages point,
 * not that core's screen has gone anywhere.
 *
 * @group integration
 */
class Test_Auth_Entry_Points extends WP_UnitTestCase {

	/** Where the last redirect was aimed. */
	private string $captured = '';

	public function set_up(): void {
		parent::set_up();

		$this->captured = '';

		// The handlers exit after redirecting, which would take the test
		// runner with them. Throwing from the filter stops before that.
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
		delete_option( Settings::OPTION );
		$_POST = array();

		parent::tear_down();
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

	/** @testdox An applied coupon rides through with it, instead of being dropped on the way as it was before round 9. */
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
