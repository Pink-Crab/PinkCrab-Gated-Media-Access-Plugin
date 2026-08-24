<?php
/**
 * Integration smoke tests — WordPress boots, the plugin loads, and the boot
 * loop wires a service up.
 *
 * restrict-media-file-access is a require-dev dependency and the bootstrap
 * loads it first, so these run with the dependency guard satisfied rather than
 * around it.
 *
 * @since   0.1.0
 * @package PinkCrab\Gated_Access\Tests\Integration
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Settings\Settings_Page;

/**
 * @group integration
 */
class Test_Smoke extends WP_UnitTestCase {

	/**
	 * Clears any admin screen a test set.
	 */
	public function tear_down(): void {
		unset( $GLOBALS['current_screen'] );
		parent::tear_down();
	}

	/**
	 * @testdox The integration suite is wired up and executing against WordPress
	 */
	public function test_suite_runs(): void {
		$this->assertSame( 1, 1 );
		$this->assertTrue( function_exists( 'add_action' ) );
	}

	/**
	 * @testdox The plugin file loaded and defined its constants
	 */
	public function test_plugin_constants_are_defined(): void {
		$this->assertTrue( defined( 'GATEDMEDIA_VERSION' ) );
		$this->assertTrue( defined( 'GATEDMEDIA_BASENAME' ) );
		$this->assertTrue( defined( 'GATEDMEDIA_DIR_PATH' ) );
		$this->assertTrue( defined( 'GATEDMEDIA_DIR_URL' ) );
	}

	/**
	 * @testdox The plugin registers its boot on plugins_loaded
	 */
	public function test_boot_is_hooked(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded' ) );
	}

	/**
	 * @testdox restrict-media-file-access is loaded, so the dependency guard passes
	 */
	public function test_file_dependency_is_present(): void {
		$this->assertTrue( defined( 'RESTRICT_MEDIA_FILE_ACCESS_BASENAME' ) );
		$this->assertTrue( function_exists( 'rmfa_set_file_as_protected' ) );
	}

	/**
	 * @testdox A hookable service registers its hooks through the loader
	 */
	public function test_hookable_service_registers_through_the_loader(): void {
		// The menu is an admin_action, and Hook_Manager::validate_context()
		// only attaches those when is_admin() is true.
		set_current_screen( 'dashboard' );

		$page   = new Settings_Page( new \PinkCrab\Gated_Access\Settings\Settings(), new \PinkCrab\Gated_Access\Settings\Notification_Fields( new \PinkCrab\Gated_Access\Settings\Settings() ), new \PinkCrab\Gated_Access\Settings\Account_Fields( new \PinkCrab\Gated_Access\Settings\Settings() ) );
		$loader = new Hook_Loader();

		$page->register_hooks( $loader );
		$loader->register_hooks();

		$this->assertNotFalse( has_action( 'admin_menu', array( $page, 'register_menu' ) ) );
	}
}
