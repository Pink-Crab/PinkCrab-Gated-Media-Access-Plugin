<?php
/**
 * Where the account area's pages live.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * Every link into the account area is built here, so `gatedmedia_account_slug` renames all of them at once: a link built with the default slug on a site using a filtered one is a 404.
 *
 * What a filter can return matters just as much: an empty string would give `//orders/`, a protocol-relative URL to a host named `orders`.
 *
 * @group integration
 */
class Test_Account_Url extends WP_UnitTestCase {

	/**
	 * The filter is global, so a test that adds one has to take it away again.
	 */
	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_account_slug' );
		remove_all_filters( 'gatedmedia_account_route' );
		remove_all_filters( 'gatedmedia_account_url' );

		parent::tear_down();
	}

	/**
	 * With the route off nothing answers at `/account/`, so the setting is read here rather than at each call site: reading it per site is how the emails and the checkout return URL kept pointing at a dead route.
	 *
	 * @testdox With the route off, links fall back to the home page rather than a page that does not answer.
	 */
	public function test_the_route_being_off_moves_every_link(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );

		$this->assertSame( home_url( '/' ), Account_Url::section( 'orders' ) );
		$this->assertSame( home_url( '/' ), Account_Url::detail( 'orders', 'abc' ) );
	}

	/** @testdox A site placing the blocks on its own pages points the links at them. */
	public function test_a_site_can_map_its_own_pages(): void {
		add_filter( 'gatedmedia_account_route', '__return_false' );
		add_filter(
			'gatedmedia_account_url',
			static fn( string $url, string $section ): string => home_url( "/my-{$section}/" ),
			10,
			2
		);

		$this->assertSame( home_url( '/my-orders/' ), Account_Url::section( 'orders' ) );
	}

	/** @testdox The filter is offered with the route on too, so a site can move one section without moving all of them. */
	public function test_the_filter_applies_with_the_route_on(): void {
		add_filter(
			'gatedmedia_account_url',
			static fn( string $url, string $section ): string => 'profile' === $section ? home_url( '/edit-me/' ) : $url,
			10,
			2
		);

		$this->assertSame( home_url( '/edit-me/' ), Account_Url::section( 'profile' ) );
		$this->assertSame( home_url( '/account/orders/' ), Account_Url::section( 'orders' ) );
	}

	/** @testdox A section links to its own page under the account segment, with a trailing slash. */
	public function test_section_url(): void {
		$this->assertSame( home_url( '/account/orders/' ), Account_Url::section( 'orders' ) );
	}

	/** @testdox One thing inside a section hangs off that section's page, also with a trailing slash. */
	public function test_detail_url(): void {
		$uuid = 'ab12cd34-5678-90ef-ab12-cd34567890ef';

		$this->assertSame(
			home_url( '/account/orders/' ) . $uuid . '/',
			Account_Url::detail( 'orders', $uuid )
		);
	}

	/** @testdox Renaming the account segment renames both the section link and the detail link with it. */
	public function test_the_slug_filter_moves_every_link(): void {
		add_filter( 'gatedmedia_account_slug', static fn(): string => 'members' );

		$this->assertSame( home_url( '/members/orders/' ), Account_Url::section( 'orders' ) );
		$this->assertSame( home_url( '/members/orders/abc/' ), Account_Url::detail( 'orders', 'abc' ) );
	}

	/** @testdox A filter that returns nothing at all leaves the links on the default segment rather than pointing them at another host. */
	public function test_an_empty_slug_falls_back(): void {
		add_filter( 'gatedmedia_account_slug', static fn(): string => '' );

		$this->assertSame( home_url( '/account/orders/' ), Account_Url::section( 'orders' ) );
		$this->assertSame( home_url( '/account/orders/abc/' ), Account_Url::detail( 'orders', 'abc' ) );
	}

	/** @testdox A filter that returns something that is not a string leaves the links on the default segment. */
	public function test_a_non_string_slug_falls_back(): void {
		add_filter( 'gatedmedia_account_slug', static fn(): array => array( 'members' ) );

		$this->assertSame( home_url( '/account/orders/' ), Account_Url::section( 'orders' ) );
		$this->assertSame( home_url( '/account/orders/abc/' ), Account_Url::detail( 'orders', 'abc' ) );
	}

	/** @testdox An identifier carrying a slash or a space stays inside its own path segment instead of inventing a deeper URL. */
	public function test_the_identifier_cannot_escape_its_segment(): void {
		$this->assertSame(
			home_url( '/account/orders/one%2Ftwo/' ),
			Account_Url::detail( 'orders', 'one/two' )
		);

		$this->assertSame(
			home_url( '/account/orders/one%20two/' ),
			Account_Url::detail( 'orders', 'one two' )
		);
	}
}
