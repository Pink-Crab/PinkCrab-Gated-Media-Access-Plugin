<?php
/**
 * The coupon metabox.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * Type, value, limits and expiry, registered by the class that writes them, protected, and saved from the editor's own submit.
 *
 * A percent caps at 100, a fixed value stores in minor units, and limits store empty for unlimited.
 *
 * @group integration
 */
class Test_Coupon_Metabox extends WP_UnitTestCase {

	private Coupon_Metabox $metabox;

	private int $coupon_id;

	public function set_up(): void {
		parent::set_up();

		// The framework's tear_down unregisters every meta key.
		$this->metabox = new Coupon_Metabox( new Settings() );
		$this->metabox->register_meta();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->coupon_id = self::factory()->post->create(
			array(
				'post_type'  => Post_Types::COUPON,
				'post_title' => 'SAVE20',
			)
		);
	}

	public function tear_down(): void {
		$_POST = array();

		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/** @testdox Every key is registered against the coupon type and protected. */
	public function test_meta_registered_and_protected(): void {
		foreach ( array(
			Coupon_Metabox::META_TYPE,
			Coupon_Metabox::META_VALUE,
			Coupon_Metabox::META_USAGE_LIMIT,
			Coupon_Metabox::META_PER_USER_LIMIT,
			Coupon_Metabox::META_EXPIRES_AT,
		) as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, Post_Types::COUPON ), "{$key} is not registered" );
			$this->assertTrue( $this->metabox->protect_meta( false, $key, 'post' ), "{$key} is not protected" );
		}
	}

	/** @testdox A percent coupon stores its whole percent, capped at 100. */
	public function test_save_percent(): void {
		$this->submit(
			array(
				'gatedmedia_discount_type'  => 'percent',
				'gatedmedia_discount_value' => '250',
			)
		);

		$this->assertSame( 'percent', get_post_meta( $this->coupon_id, Coupon_Metabox::META_TYPE, true ) );
		$this->assertSame( '100', get_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, true ) );
	}

	/** @testdox A fixed coupon stores the typed amount in minor units. */
	public function test_save_fixed(): void {
		$this->submit(
			array(
				'gatedmedia_discount_type'  => 'fixed',
				'gatedmedia_discount_value' => '5.00',
			)
		);

		$this->assertSame( 'fixed', get_post_meta( $this->coupon_id, Coupon_Metabox::META_TYPE, true ) );
		$this->assertSame( '500', get_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, true ) );
	}

	/** @testdox A fixed coupon uses the shop currency's digits, not two, for a zero-decimal currency. */
	public function test_save_fixed_in_a_zero_decimal_currency(): void {
		update_option( Settings::OPTION, array( 'currency' => 'JPY' ) );

		$this->submit(
			array(
				'gatedmedia_discount_type'  => 'fixed',
				'gatedmedia_discount_value' => '500',
			)
		);

		$this->assertSame( '500', get_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, true ) );
	}

	/** @testdox A fixed coupon uses the shop currency's digits, not two, for a three-decimal currency. */
	public function test_save_fixed_in_a_three_decimal_currency(): void {
		update_option( Settings::OPTION, array( 'currency' => 'BHD' ) );

		$this->submit(
			array(
				'gatedmedia_discount_type'  => 'fixed',
				'gatedmedia_discount_value' => '12.500',
			)
		);

		$this->assertSame( '12500', get_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, true ) );
	}

	/** @testdox The box shows a stored fixed amount back in the shop currency's digits. */
	public function test_render_shows_the_shop_currency(): void {
		update_option( Settings::OPTION, array( 'currency' => 'JPY' ) );
		update_post_meta( $this->coupon_id, Coupon_Metabox::META_TYPE, 'fixed' );
		update_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, '500' );

		ob_start();
		$this->metabox->render( get_post( $this->coupon_id ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="gatedmedia_discount_value" value="500"', $html );
	}

	/** @testdox Limits store empty for unlimited, and the count when set. */
	public function test_save_limits(): void {
		$this->submit(
			array(
				'gatedmedia_usage_limit'    => '10',
				'gatedmedia_per_user_limit' => '',
			)
		);

		$this->assertSame( '10', get_post_meta( $this->coupon_id, Coupon_Metabox::META_USAGE_LIMIT, true ) );
		$this->assertSame( '', get_post_meta( $this->coupon_id, Coupon_Metabox::META_PER_USER_LIMIT, true ) );
	}

	/** @testdox A typed expiry day stores as the end of that day UTC; garbage stores never. */
	public function test_save_expiry(): void {
		$this->submit( array( 'gatedmedia_coupon_expires' => '2027-01-31' ) );
		$this->assertSame( '2027-01-31 23:59:59', get_post_meta( $this->coupon_id, Coupon_Metabox::META_EXPIRES_AT, true ) );

		$this->submit( array( 'gatedmedia_coupon_expires' => 'next tuesday' ) );
		$this->assertSame( '', get_post_meta( $this->coupon_id, Coupon_Metabox::META_EXPIRES_AT, true ) );
	}

	/** @testdox Without a valid nonce nothing is written. */
	public function test_save_requires_the_nonce(): void {
		$_POST = array( 'gatedmedia_discount_value' => '50' );

		$this->metabox->save( $this->coupon_id );

		$this->assertSame( '', get_post_meta( $this->coupon_id, Coupon_Metabox::META_VALUE, true ) );
	}

	/**
	 * Submits the box as the editor's save would.
	 *
	 * @param array<string, string> $fields The posted fields.
	 */
	private function submit( array $fields ): void {
		$_POST = array_merge(
			array( Coupon_Metabox::NONCE_FIELD => wp_create_nonce( Coupon_Metabox::NONCE_FIELD ) ),
			$fields
		);

		$this->metabox->save( $this->coupon_id );
	}
}
