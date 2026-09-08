<?php
/**
 * The account route.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Account_Route;
use PinkCrab\Gated_Access\Account\Account_Renderer;
use PinkCrab\Gated_Access\Account\Section_Registry;
use PinkCrab\Gated_Access\Assets\Asset_Loader;
use PinkCrab\Gated_Access\Blocks\Sprite;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * The routing half of the account area: the catch-all rule resolves every section without a per-section rule, the second segment survives, and a signed-out visitor is sent to log in.
 *
 * @group integration
 */
class Test_Account_Route extends WP_UnitTestCase {

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

		remove_all_filters( 'gatedmedia_account_slug' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();

		parent::tear_down();
	}

	/**
	 * @testdox The three query vars are added to the public list, so get_query_var can read them.
	 *
	 * Asserted through the filter, not `WP::$public_query_vars`: that property holds core's own list, and the filtered result is what `WP::parse_request()` consults.
	 */
	public function test_registers_its_query_vars(): void {
		$vars = apply_filters( 'query_vars', array() );

		$this->assertContains( Account_Route::QUERY_FLAG, $vars );
		$this->assertContains( Account_Route::QUERY_SECTION, $vars );
		$this->assertContains( Account_Route::QUERY_DETAIL, $vars );
	}

	/** @testdox The bare account URL is recognised, with no section named. */
	public function test_the_bare_route_is_recognised(): void {
		$this->go_to( home_url( '/account/' ) );

		$this->assertSame( '1', (string) get_query_var( Account_Route::QUERY_FLAG ) );
		$this->assertSame( '', (string) get_query_var( Account_Route::QUERY_SECTION ) );
	}

	/**
	 * @testdox Every section resolves from the one catch-all rule.
	 *
	 * @dataProvider section_slugs
	 *
	 * @param string $slug The section slug to request.
	 */
	public function test_each_section_resolves( string $slug ): void {
		$this->go_to( home_url( '/account/' . $slug . '/' ) );

		$this->assertSame( '1', (string) get_query_var( Account_Route::QUERY_FLAG ) );
		$this->assertSame( $slug, (string) get_query_var( Account_Route::QUERY_SECTION ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function section_slugs(): array {
		return array(
			'my access' => array( 'my-access' ),
			'files'     => array( 'files' ),
			'orders'    => array( 'orders' ),
			'profile'   => array( 'profile' ),
		);
	}

	/** @testdox A slug nobody registered still resolves, because the rule is a catch-all. */
	public function test_an_unknown_slug_still_matches_the_rule(): void {
		$this->go_to( home_url( '/account/subscriptions/' ) );

		$this->assertSame( 'subscriptions', (string) get_query_var( Account_Route::QUERY_SECTION ) );
	}

	/** @testdox The second segment is captured, which is what order detail needs. */
	public function test_captures_a_second_segment(): void {
		$this->go_to( home_url( '/account/orders/abc-123/' ) );

		$this->assertSame( 'orders', (string) get_query_var( Account_Route::QUERY_SECTION ) );
		$this->assertSame( 'abc-123', (string) get_query_var( Account_Route::QUERY_DETAIL ) );
	}

	/**
	 * @testdox The account slug is filterable.
	 *
	 * The rules are registered again, not merely flushed, because they were added on `init` with the old slug. On a real site the filter is in place before `init`, so this is test ordering only.
	 */
	public function test_the_slug_is_filterable(): void {
		add_filter( 'gatedmedia_account_slug', static fn(): string => 'my-stuff' );

		global $wp_rewrite;

		$wp_rewrite->rules = array();
		( new Account_Route(
			new Section_Registry(),
			new Account_Renderer(),
			new Asset_Loader(),
			new Sprite(),
			new Settings()
		) )->register_rewrites();
		$wp_rewrite->flush_rules();

		$this->go_to( home_url( '/my-stuff/files/' ) );

		$this->assertSame( '1', (string) get_query_var( Account_Route::QUERY_FLAG ) );
		$this->assertSame( 'files', (string) get_query_var( Account_Route::QUERY_SECTION ) );
	}

	/** @testdox Moving the slug flushes the rules by itself, with no permalinks save. */
	public function test_changing_the_slug_flushes_the_rules(): void {
		global $wp_rewrite;

		$wp_rewrite->rules = array();
		$this->account_route()->register_rewrites();

		add_filter( 'gatedmedia_account_slug', static fn(): string => 'members-area' );

		$wp_rewrite->rules = array();
		$this->account_route()->register_rewrites();

		$this->go_to( home_url( '/members-area/files/' ) );

		$this->assertSame( '1', (string) get_query_var( Account_Route::QUERY_FLAG ) );
		$this->assertSame( 'files', (string) get_query_var( Account_Route::QUERY_SECTION ) );
	}

	/**
	 * The route with its real collaborators.
	 */
	private function account_route(): Account_Route {
		return new Account_Route(
			new Section_Registry(),
			new Account_Renderer(),
			new Asset_Loader(),
			new Sprite(),
			new Settings()
		);
	}

	/** @testdox A signed-in user gets a real page rather than a 404. */
	public function test_a_signed_in_user_gets_a_page(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->go_to( home_url( '/account/files/' ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_page() );
	}

	/** @testdox The virtual page is titled with the section, so the theme renders that as the heading. */
	public function test_the_page_is_titled_with_the_section(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->go_to( home_url( '/account/orders/' ) );

		$this->assertSame( 'Orders', get_the_title() );
	}

	/** @testdox The content is the account shell, with the section's block rendered inside it. */
	public function test_the_content_is_the_shell(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->go_to( home_url( '/account/files/' ) );

		the_post();
		$content = apply_filters( 'the_content', get_the_content() );

		$this->assertStringContainsString( 'gatedmedia-account', $content );
		$this->assertStringContainsString( 'gatedmedia-account-nav', $content );
		// Rendered by the block, not by the shell.
		$this->assertStringContainsString( 'gatedmedia-view--files', $content );
	}

	/** @testdox A section this user may not see is a 404, so the nav and the router agree. */
	public function test_a_hidden_section_is_a_404(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->go_to( home_url( '/account/nothing-here/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox A signed-out visitor is not handed the account area. */
	public function test_a_signed_out_visitor_gets_no_page(): void {
		wp_set_current_user( 0 );

		$this->go_to( home_url( '/account/files/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox Nothing of ours touches a request that is not for the account area. */
	public function test_an_unrelated_request_is_untouched(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Ordinary' ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( '', (string) get_query_var( Account_Route::QUERY_FLAG ) );
		$this->assertTrue( is_single() );
		$this->assertFalse( is_404() );
	}
}
