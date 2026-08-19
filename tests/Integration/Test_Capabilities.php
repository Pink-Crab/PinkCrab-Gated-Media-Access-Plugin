<?php
/**
 * The capability grants.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * Administrators get the four capabilities once per version, nobody else gets
 * anything, and the give-access lookup is filterable.
 *
 * @group integration
 */
class Test_Capabilities extends WP_UnitTestCase {

	private const ALL = array(
		Capabilities::GIVE_ACCESS,
		Capabilities::MANAGE_PRODUCTS,
		Capabilities::VIEW_PAYMENTS,
		Capabilities::MANAGE_SETTINGS,
	);

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_give_access_capability' );

		parent::tear_down();
	}

	/** @testdox An administrator holds all four capabilities. */
	public function test_administrator_has_all_four(): void {
		$role = get_role( 'administrator' );

		$this->assertNotNull( $role );

		foreach ( self::ALL as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ), "administrator is missing {$capability}" );
		}
	}

	/** @testdox A subscriber holds none of them. */
	public function test_subscriber_has_none(): void {
		$role = get_role( 'subscriber' );

		$this->assertNotNull( $role );

		foreach ( self::ALL as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ), "subscriber unexpectedly holds {$capability}" );
		}
	}

	/** @testdox Rolling the stored version back re-grants a removed capability. */
	public function test_a_version_bump_regrants(): void {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );

		$role->remove_cap( Capabilities::GIVE_ACCESS );
		update_option( 'gatedmedia_caps_version', '0' );

		( new Capabilities() )->grant();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::GIVE_ACCESS ) );
		$this->assertSame( '1', get_option( 'gatedmedia_caps_version' ) );
	}

	/**
	 * @testdox Running the grant again at the current version changes nothing.
	 *
	 * Proved through the version check: a capability removed by hand stays
	 * removed, because the stored version says the grant already ran.
	 */
	public function test_running_twice_changes_nothing(): void {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );

		$role->remove_cap( Capabilities::VIEW_PAYMENTS );

		( new Capabilities() )->grant();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::VIEW_PAYMENTS ) );
	}

	/** @testdox The gatedmedia_give_access_capability filter overrides what give_access() returns. */
	public function test_give_access_is_filterable(): void {
		$this->assertSame( Capabilities::GIVE_ACCESS, Capabilities::give_access() );

		add_filter(
			'gatedmedia_give_access_capability',
			static fn (): string => 'manage_options'
		);

		$this->assertSame( 'manage_options', Capabilities::give_access() );
	}
}
