<?php
/**
 * Unit smoke tests — the suite runs and the plugin's classes autoload.
 *
 * @since   0.1.0
 * @package PinkCrab\Gated_Access\Tests\Unit
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PinkCrab\Gated_Access\Plugin;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Settings\Settings_Page;

/**
 * @group unit
 */
class Test_Smoke extends TestCase {

	/**
	 * @testdox The unit suite is wired up and executing
	 */
	public function test_suite_runs(): void {
		$this->assertSame( 1, 1 );
	}

	/**
	 * @testdox Composer's PSR-4 map resolves the plugin namespace
	 */
	public function test_plugin_classes_autoload(): void {
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertTrue( interface_exists( Hookable::class ) );
		$this->assertTrue( class_exists( Settings_Page::class ) );
	}

	/**
	 * @testdox Settings_Page is Hookable, so the boot loop will register its hooks
	 */
	public function test_settings_page_is_hookable(): void {
		$this->assertInstanceOf( Hookable::class, new Settings_Page( new \PinkCrab\Gated_Access\Settings\Settings(), new \PinkCrab\Gated_Access\Settings\Notification_Fields( new \PinkCrab\Gated_Access\Settings\Settings() ) ) );
	}
}
