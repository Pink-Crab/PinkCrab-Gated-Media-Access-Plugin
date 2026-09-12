<?php
/**
 * The settings screen's capability.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Settings\Settings_Page;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * The menu is registered behind gatedmedia_manage_settings rather than manage_options, so an administrator sees it and a user holding only manage_options does not.
 *
 * Core hides a menu item from anyone failing user_can() on its registered capability, so which capability the menu carries and who passes it is what decides visibility.
 *
 * register_menu() is called directly because the admin_menu hook is attached through admin_action(), which never fires under PHPUnit where is_admin() is false.
 *
 * @group integration
 */
class Test_Settings_Page extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$GLOBALS['menu']             = array();
		$GLOBALS['admin_page_hooks'] = array();
	}

	public function tear_down(): void {
		remove_role( 'gatedmedia_test_options_only' );

		parent::tear_down();
	}

	/** @testdox The General tab draws the store, Stripe, access and uninstall fields from templates. */
	public function test_general_tab_renders(): void {
		$html = $this->render();

		$this->assertFileExists( GATEDMEDIA_DIR_PATH . 'views/admin/settings/index.php' );
		$this->assertFileExists( GATEDMEDIA_DIR_PATH . 'views/admin/settings/general.php' );
		$this->assertStringContainsString( 'class="gatedmedia-admin"', $html );
		$this->assertStringContainsString( 'gatedmedia_currency', $html );
		$this->assertStringContainsString( 'gatedmedia_product_path', $html );
		$this->assertStringContainsString( 'gatedmedia_stripe_mode', $html );
		$this->assertStringContainsString( 'gatedmedia_stripe_test_key', $html );
		$this->assertStringContainsString( 'gatedmedia_stripe_live_webhook_secret', $html );
		$this->assertStringContainsString( 'gatedmedia_revoke_behaviour', $html );
		$this->assertStringContainsString( 'gatedmedia_purge_on_uninstall', $html );
		// The accounts section is the same tab.
		$this->assertStringContainsString( 'gatedmedia_account_creation', $html );
	}

	/** @testdox A saved secret is never echoed back, only marked as saved. */
	public function test_a_saved_secret_is_not_echoed_back(): void {
		update_option( \PinkCrab\Gated_Access\Settings\Settings::OPTION, array( 'stripe_test_secret' => 'sk_test_donotleak' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'sk_test_donotleak', $html );
		$this->assertStringContainsString( 'saved, leave empty to keep', $html );
	}

	/** @testdox The Notifications tab draws the delivery fields and one panel per notification. */
	public function test_notifications_tab_renders(): void {
		$html = $this->render( 'notifications' );

		$this->assertFileExists( GATEDMEDIA_DIR_PATH . 'views/admin/settings/notifications.php' );
		$this->assertStringContainsString( 'gatedmedia_admin_copy', $html );
		$this->assertStringContainsString( 'gatedmedia_expiry_warning_days', $html );
		$this->assertStringContainsString( 'gatedmedia-admin-panel', $html );
		$this->assertStringContainsString( 'gatedmedia-admin-tokens', $html );
		// The General tab's fields are not on this one.
		$this->assertStringNotContainsString( 'gatedmedia_stripe_mode', $html );
	}

	/**
	 * The settings screen's markup, for whichever tab.
	 *
	 * @param string $section The `section` argument, '' for General.
	 */
	private function render( string $section = '' ): string {
		$settings = new \PinkCrab\Gated_Access\Settings\Settings();
		$page     = new Settings_Page(
			$settings,
			new \PinkCrab\Gated_Access\Settings\Notification_Fields( $settings ),
			new \PinkCrab\Gated_Access\Settings\Account_Fields( $settings )
		);

		$_GET['section'] = $section;

		ob_start();
		$page->render_settings();
		$html = (string) ob_get_clean();

		unset( $_GET['section'] );

		return $html;
	}

	/** @testdox The menu is registered behind gatedmedia_manage_settings. */
	public function test_menu_requires_the_settings_capability(): void {
		( new Settings_Page( new \PinkCrab\Gated_Access\Settings\Settings(), new \PinkCrab\Gated_Access\Settings\Notification_Fields( new \PinkCrab\Gated_Access\Settings\Settings() ), new \PinkCrab\Gated_Access\Settings\Account_Fields( new \PinkCrab\Gated_Access\Settings\Settings() ) ) )->register_menu();

		$entry = $this->menu_entry();

		$this->assertNotNull( $entry, 'the menu was not registered' );
		$this->assertSame( Capabilities::MANAGE_SETTINGS, $entry[1] );
	}

	/** @testdox An administrator passes the menu's capability, so they see it. */
	public function test_an_administrator_sees_the_menu(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE_SETTINGS ) );
	}

	/** @testdox A user with manage_options but not the capability fails it, so they do not. */
	public function test_manage_options_alone_is_not_enough(): void {
		add_role(
			'gatedmedia_test_options_only',
			'Options Only',
			array(
				'read'           => true,
				'manage_options' => true,
			)
		);

		$user = self::factory()->user->create( array( 'role' => 'gatedmedia_test_options_only' ) );

		$this->assertTrue( user_can( $user, 'manage_options' ) );
		$this->assertFalse( user_can( $user, Capabilities::MANAGE_SETTINGS ) );
	}

	/**
	 * Our entry in the $menu global, if registered.
	 *
	 * @return array<int, string>|null
	 */
	private function menu_entry(): ?array {
		foreach ( $GLOBALS['menu'] as $entry ) {
			if ( Settings_Page::MENU_SLUG === $entry[2] ) {
				return $entry;
			}
		}

		return null;
	}
}
