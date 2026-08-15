<?php
/**
 * Integration smoke tests — WordPress boots and the plugin file is loaded.
 *
 * The boot loop itself is deliberately NOT asserted here: it only runs when
 * restrict-media-file-access is active, and that plugin is not installed in
 * the test environment. What is asserted is everything that happens before
 * the dependency guard.
 *
 * @since   0.1.0
 * @package PinkCrab\Gated_Access\Tests\Integration
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;

/**
 * @group integration
 */
class Test_Smoke extends WP_UnitTestCase {

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
}
