<?php
/**
 * Deactivation and uninstall.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Sweep;
use PinkCrab\Gated_Access\Notifications\Expiry_Warning;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Lifecycle;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Deactivating takes the cron events; uninstalling takes the credentials, the
 * options and the capabilities. Business history survives unless the site has
 * asked for it to go (§32).
 *
 * @group integration
 */
class Test_Lifecycle extends WP_UnitTestCase {

	private const CAPABILITIES = array(
		Capabilities::GIVE_ACCESS,
		Capabilities::MANAGE_PRODUCTS,
		Capabilities::VIEW_PAYMENTS,
		Capabilities::MANAGE_SETTINGS,
	);

	public function set_up(): void {
		parent::set_up();

		( new Post_Types() )->register();
		( new Access_Taxonomy() )->register();
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Sweep::HOOK );
		wp_clear_scheduled_hook( Expiry_Warning::HOOK );

		delete_option( Settings::OPTION );

		// The rollback restores the roles option but not the in-memory
		// WP_Roles cache, so a capability this class removed would stay
		// missing for every later test (as Test_Capabilities notes).
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			foreach ( self::CAPABILITIES as $capability ) {
				$role->add_cap( $capability );
			}
		}

		$this->restore_payments_table();

		parent::tear_down();
	}

	/**
	 * The suite runs inside a transaction and rewrites CREATE/DROP TABLE to
	 * their TEMPORARY forms, so a dropped real table has to be put back by
	 * hand or every later payments test loses it.
	 */
	private function restore_payments_table(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/** @testdox Deactivating unschedules both daily events. */
	public function test_deactivate_clears_the_cron_events(): void {
		wp_schedule_event( time(), 'daily', Sweep::HOOK );
		wp_schedule_event( time(), 'daily', Expiry_Warning::HOOK );

		$this->assertNotFalse( wp_next_scheduled( Sweep::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Expiry_Warning::HOOK ) );

		Lifecycle::deactivate();

		$this->assertFalse( wp_next_scheduled( Sweep::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Expiry_Warning::HOOK ) );
	}

	/** @testdox Uninstalling deletes every option, the Stripe secrets among them. */
	public function test_uninstall_deletes_the_options(): void {
		update_option( Settings::OPTION, array( 'stripe_live_secret' => 'sk_live_secret' ) );
		update_option( Payments_Schema::OPTION_DB_VERSION, '2' );
		update_option( 'gatedmedia_caps_version', '1' );
		update_option( 'gatedmedia_auth_rewrites', '1' );
		update_option( 'gatedmedia_gated_rewrites', '1' );
		update_option( 'gatedmedia_product_rewrites', '1' );
		update_option( 'gatedmedia_rewrite_version', '1' );

		Lifecycle::uninstall();

		foreach ( array(
			Settings::OPTION,
			Payments_Schema::OPTION_DB_VERSION,
			'gatedmedia_caps_version',
			'gatedmedia_auth_rewrites',
			'gatedmedia_gated_rewrites',
			'gatedmedia_product_rewrites',
			'gatedmedia_rewrite_version',
		) as $option ) {
			$this->assertFalse( get_option( $option ), "{$option} survived the uninstall" );
		}
	}

	/** @testdox Uninstalling takes the four capabilities back off administrator. */
	public function test_uninstall_removes_the_capabilities(): void {
		// The bootstrap's init grant already ran, and the stored version stops
		// it running again, so the caps are simply there to be taken.
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::GIVE_ACCESS ) );

		Lifecycle::uninstall();

		$role = get_role( 'administrator' );

		foreach ( self::CAPABILITIES as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ), "{$capability} survived the uninstall" );
		}
	}

	/** @testdox By default the payments table and the records stay: it is business history. */
	public function test_uninstall_keeps_the_data_by_default(): void {
		global $wpdb;

		( new Payments_Schema() )->migrate();

		$record_id = self::factory()->post->create( array( 'post_type' => Post_Types::ACCESS ) );

		Lifecycle::uninstall();

		$table = Payments_Schema::table_name();

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $record_id ) );
	}

	/** @testdox With the purge box ticked the table, the records, the products and the coupons all go. */
	public function test_uninstall_purges_when_asked(): void {
		global $wpdb;

		// The harness rewrites both statements to their TEMPORARY forms, which
		// SHOW TABLES cannot see — so this one test works on the real table.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		update_option( Settings::OPTION, array( 'purge_on_uninstall' => '1' ) );

		$record_id  = self::factory()->post->create( array( 'post_type' => Post_Types::ACCESS ) );
		$product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		$coupon_id  = self::factory()->post->create( array( 'post_type' => Post_Types::COUPON ) );

		wp_insert_term( 'gm-1', Access_Taxonomy::TAXONOMY );

		Lifecycle::uninstall();

		$table = Payments_Schema::table_name();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertNull( get_post( $record_id ) );
		$this->assertNull( get_post( $product_id ) );
		$this->assertNull( get_post( $coupon_id ) );
		$this->assertSame( array(), get_terms( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'hide_empty' => false ) ) );
	}
}
