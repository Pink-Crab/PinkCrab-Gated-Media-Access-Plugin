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
 * The menu is registered behind gatedmedia_manage_settings, not
 * manage_options — so an administrator sees it, and a user holding only
 * manage_options does not.
 *
 * Core hides a menu item from anyone failing user_can() on its registered
 * capability, so the pair proved here — which capability the menu carries,
 * and who passes it — is exactly what decides visibility.
 *
 * register_menu() is called directly: the admin_menu hook is attached through
 * admin_action(), which never fires under PHPUnit because is_admin() is false.
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

	/** @testdox The menu is registered behind gatedmedia_manage_settings. */
	public function test_menu_requires_the_settings_capability(): void {
		( new Settings_Page( new \PinkCrab\Gated_Access\Settings\Settings() ) )->register_menu();

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
