<?php
/**
 * The settings readers and the screen's sanitize.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * Exactly one place reads the credentials (architecture §7): the mode picks
 * the key set, filters have the last word, and the screen's sanitize never
 * loses a secret to an empty resubmit.
 *
 * @group integration
 */
class Test_Settings extends WP_UnitTestCase {

	private Settings $settings;

	private Settings_Page $page;

	public function set_up(): void {
		parent::set_up();

		$this->settings = new Settings();
		$this->page     = new Settings_Page( $this->settings );
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_stripe_mode' );
		remove_all_filters( 'gatedmedia_stripe_secret' );
		delete_option( Settings::OPTION );

		$GLOBALS['menu']             = array();
		$GLOBALS['submenu']          = array();
		$GLOBALS['admin_page_hooks'] = array();

		parent::tear_down();
	}

	/** @testdox The shop currency defaults to GBP, honours a real stored code, and refuses a fake one. */
	public function test_currency(): void {
		$this->assertSame( 'GBP', $this->settings->currency() );

		update_option( Settings::OPTION, array( 'currency' => 'sek' ) );
		$this->assertSame( 'SEK', $this->settings->currency() );

		update_option( Settings::OPTION, array( 'currency' => 'XYZ' ) );
		$this->assertSame( 'GBP', $this->settings->currency(), 'a code ISO does not know must not survive' );

		$clean = $this->page->sanitize( array( 'currency' => 'jpy' ) );
		$this->assertSame( 'JPY', $clean['currency'] );

		$fake = $this->page->sanitize( array( 'currency' => 'FAKE' ) );
		$this->assertSame( 'GBP', $fake['currency'] );
	}

	/** @testdox The mode defaults to test, honours the stored value, and clamps anything else. */
	public function test_stripe_mode(): void {
		$this->assertSame( 'test', $this->settings->stripe_mode() );

		update_option( Settings::OPTION, array( 'stripe_mode' => 'live' ) );
		$this->assertSame( 'live', $this->settings->stripe_mode() );

		update_option( Settings::OPTION, array( 'stripe_mode' => 'sideways' ) );
		$this->assertSame( 'test', $this->settings->stripe_mode() );
	}

	/** @testdox The credential readers answer with the current mode's keys. */
	public function test_credentials_follow_the_mode(): void {
		update_option(
			Settings::OPTION,
			array(
				'stripe_mode'                => 'test',
				'stripe_test_key'            => 'pk_test_1',
				'stripe_test_secret'         => 'sk_test_1',
				'stripe_test_webhook_secret' => 'whsec_test_1',
				'stripe_live_key'            => 'pk_live_1',
				'stripe_live_secret'         => 'sk_live_1',
				'stripe_live_webhook_secret' => 'whsec_live_1',
			)
		);

		$this->assertSame( 'pk_test_1', $this->settings->stripe_key() );
		$this->assertSame( 'sk_test_1', $this->settings->stripe_secret() );
		$this->assertSame( 'whsec_test_1', $this->settings->stripe_webhook_secret() );

		add_filter( 'gatedmedia_stripe_mode', static fn (): string => 'live' );

		$this->assertSame( 'pk_live_1', $this->settings->stripe_key() );
		$this->assertSame( 'sk_live_1', $this->settings->stripe_secret() );
		$this->assertSame( 'whsec_live_1', $this->settings->stripe_webhook_secret() );
	}

	/** @testdox A credential filter overrides the stored value — wp-config can own the keys. */
	public function test_credential_filter_wins(): void {
		update_option( Settings::OPTION, array( 'stripe_test_secret' => 'sk_stored' ) );

		add_filter( 'gatedmedia_stripe_secret', static fn (): string => 'sk_constant' );

		$this->assertSame( 'sk_constant', $this->settings->stripe_secret() );
	}

	/** @testdox Sanitize clamps mode and behaviour, and an empty secret keeps what is stored. */
	public function test_sanitize(): void {
		update_option(
			Settings::OPTION,
			array(
				'stripe_test_secret' => 'sk_keep_me',
				'revoke_behaviour'   => 'expire',
			)
		);

		$clean = $this->page->sanitize(
			array(
				'stripe_mode'      => 'sideways',
				'revoke_behaviour' => 'delete',
				'stripe_test_key'  => ' pk_new ',
			)
		);

		$this->assertSame( 'test', $clean['stripe_mode'] );
		$this->assertSame( 'delete', $clean['revoke_behaviour'] );
		$this->assertSame( 'pk_new', $clean['stripe_test_key'] );
		$this->assertSame( 'sk_keep_me', $clean['stripe_test_secret'], 'an empty secret submit must keep the stored one' );

		$replaced = $this->page->sanitize( array( 'stripe_test_secret' => 'sk_new' ) );

		$this->assertSame( 'sk_new', $replaced['stripe_test_secret'] );
	}

	/** @testdox The Settings entry registers under the plugin menu behind manage_settings. */
	public function test_settings_submenu_registered(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$GLOBALS['menu']             = array();
		$GLOBALS['submenu']          = array();
		$GLOBALS['admin_page_hooks'] = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->page->register_menu();

		$entries = $GLOBALS['submenu'][ Settings_Page::MENU_SLUG ] ?? array();
		$ours    = array_values( array_filter( $entries, static fn ( array $entry ): bool => Settings_Page::SETTINGS_SLUG === $entry[2] ) );

		$this->assertCount( 1, $ours, 'the settings page was not registered' );
		$this->assertSame( Capabilities::MANAGE_SETTINGS, $ours[0][1] );
	}

	/** @testdox The option page's capability is the filtered manage-settings one. */
	public function test_option_capability(): void {
		$this->assertSame( Capabilities::MANAGE_SETTINGS, $this->page->option_capability() );
	}
}
