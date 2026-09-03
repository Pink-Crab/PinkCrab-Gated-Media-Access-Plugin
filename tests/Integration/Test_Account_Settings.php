<?php
/**
 * The three account settings specification.md tabled and nothing read.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Settings\Account_Fields;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * `account_route`, `account_creation` and `profile_prompt` were tabled at
 * specification.md §8 and never read by anything. Round 9 reads all three.
 *
 * `account_creation` is the one with consequences: it decides whether a
 * stranger can make themselves an account, which decides whether §7.7 draws a
 * sign-up state and whether the product page offers one. Anything unrecognised
 * has to land on a known value rather than switch sign-up off by accident.
 *
 * Core's `users_can_register` is deliberately not consulted. wp-login.php keeps
 * whatever policy the site gave it; this setting governs the plugin's own way
 * in and nothing else.
 *
 * @group integration
 */
class Test_Account_Settings extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();

		$this->settings = new Settings();
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_account_route' );
		remove_all_filters( 'gatedmedia_account_creation' );
		remove_all_filters( 'gatedmedia_profile_prompt' );
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * The help text names the URL the route would answer at, so it has to go
	 * on saying `/account/` while the setting is off — that is the thing the
	 * administrator is being asked about.
	 *
	 * @testdox The Accounts help text names the route's own URL whichever way the setting is set.
	 */
	public function test_the_help_text_always_names_the_route_url(): void {
		$this->store( 'account_route', '0' );

		ob_start();
		( new Account_Fields( $this->settings ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( home_url( '/account/' ), $html );
	}

	/**
	 * Stores one key of the settings option.
	 *
	 * @param string $key   The setting.
	 * @param string $value What to store.
	 */
	private function store( string $key, string $value ): void {
		$settings = get_option( Settings::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$settings[ $key ] = $value;

		update_option( Settings::OPTION, $settings );
	}

	/** @testdox With nothing stored, the account route is on, accounts are created by registration, and the profile is not prompted. */
	public function test_the_defaults(): void {
		$this->assertTrue( $this->settings->account_route() );
		$this->assertSame( Settings::ACCOUNT_CREATION_REGISTRATION, $this->settings->account_creation() );
		$this->assertFalse( $this->settings->profile_prompt() );
	}

	/** @testdox Each of the three reads what is stored. */
	public function test_stored_values_are_read(): void {
		$this->store( 'account_route', '0' );
		$this->store( 'account_creation', Settings::ACCOUNT_CREATION_ADMIN );
		$this->store( 'profile_prompt', '1' );

		$this->assertFalse( $this->settings->account_route() );
		$this->assertSame( Settings::ACCOUNT_CREATION_ADMIN, $this->settings->account_creation() );
		$this->assertTrue( $this->settings->profile_prompt() );
	}

	/** @testdox All three creation routes are recognised. */
	public function test_every_creation_route_is_recognised(): void {
		foreach ( array( Settings::ACCOUNT_CREATION_REGISTRATION, Settings::ACCOUNT_CREATION_ADMIN, Settings::ACCOUNT_CREATION_PURCHASE ) as $route ) {
			$this->store( 'account_creation', $route );

			$this->assertSame( $route, $this->settings->account_creation() );
		}
	}

	/** @testdox A creation route nobody recognises falls back to registration rather than silently closing sign-up. */
	public function test_an_unknown_creation_route_falls_back(): void {
		$this->store( 'account_creation', 'invitation-only' );

		$this->assertSame( Settings::ACCOUNT_CREATION_REGISTRATION, $this->settings->account_creation() );
	}

	/** @testdox A filter has the last word over each stored value. */
	public function test_the_filters_win(): void {
		$this->store( 'account_route', '1' );
		$this->store( 'account_creation', Settings::ACCOUNT_CREATION_REGISTRATION );
		$this->store( 'profile_prompt', '0' );

		add_filter( 'gatedmedia_account_route', static fn(): bool => false );
		add_filter( 'gatedmedia_account_creation', static fn(): string => Settings::ACCOUNT_CREATION_PURCHASE );
		add_filter( 'gatedmedia_profile_prompt', static fn(): bool => true );

		$this->assertFalse( $this->settings->account_route() );
		$this->assertSame( Settings::ACCOUNT_CREATION_PURCHASE, $this->settings->account_creation() );
		$this->assertTrue( $this->settings->profile_prompt() );
	}

	/** @testdox A filter returning a creation route nobody recognises still falls back rather than being taken at its word. */
	public function test_a_filtered_unknown_route_falls_back(): void {
		add_filter( 'gatedmedia_account_creation', static fn(): string => 'nonsense' );

		$this->assertSame( Settings::ACCOUNT_CREATION_REGISTRATION, $this->settings->account_creation() );
	}

	/** @testdox Core's users_can_register is neither read nor written by any of the three. */
	public function test_core_registration_is_left_alone(): void {
		update_option( 'users_can_register', '0' );

		$this->store( 'account_creation', Settings::ACCOUNT_CREATION_REGISTRATION );

		// The plugin's own answer does not change with core's option ...
		$this->assertSame( Settings::ACCOUNT_CREATION_REGISTRATION, $this->settings->account_creation() );

		// ... and reading it has not written to core's, either.
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
	}
}
