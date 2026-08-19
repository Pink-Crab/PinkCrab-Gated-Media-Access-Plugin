<?php
/**
 * The post type and status registrations.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * The containers exist with the shape the plan gives them: what is public,
 * what has a UI, and — just as deliberately — what has neither.
 *
 * @group integration
 */
class Test_Post_Types extends WP_UnitTestCase {

	/** @testdox All three post types are registered. */
	public function test_registers_the_post_types(): void {
		$this->assertTrue( post_type_exists( Post_Types::ACCESS ) );
		$this->assertTrue( post_type_exists( Post_Types::PRODUCT ) );
		$this->assertTrue( post_type_exists( Post_Types::COUPON ) );
	}

	/** @testdox Access is pure data: not public, no admin UI, no query var. */
	public function test_access_is_hidden_everywhere(): void {
		$access = get_post_type_object( Post_Types::ACCESS );

		$this->assertNotNull( $access );
		$this->assertFalse( $access->public );
		$this->assertFalse( $access->show_ui );
		$this->assertFalse( $access->query_var );
	}

	/**
	 * @testdox Access supports nothing at all.
	 *
	 * Guards the `supports => false` argument specifically: an empty array
	 * there would silently gain the title and editor defaults.
	 */
	public function test_access_supports_nothing(): void {
		$this->assertSame( array(), get_all_post_type_supports( Post_Types::ACCESS ) );
	}

	/** @testdox Product is public, with no archive. */
	public function test_product_is_public_without_archive(): void {
		$product = get_post_type_object( Post_Types::PRODUCT );

		$this->assertNotNull( $product );
		$this->assertTrue( $product->public );
		$this->assertFalse( $product->has_archive );
	}

	/**
	 * @testdox No post type of ours supports custom-fields.
	 *
	 * The Custom Fields metabox is gated on post_type_supports(), so this is
	 * the mechanism keeping our meta keys out of the editor.
	 */
	public function test_nothing_supports_custom_fields(): void {
		$this->assertFalse( post_type_supports( Post_Types::ACCESS, 'custom-fields' ) );
		$this->assertFalse( post_type_supports( Post_Types::PRODUCT, 'custom-fields' ) );
		$this->assertFalse( post_type_supports( Post_Types::COUPON, 'custom-fields' ) );
	}

	/** @testdox The three access statuses are registered, and none is public. */
	public function test_registers_the_statuses(): void {
		$stati = get_post_stati( array(), 'objects' );

		foreach ( array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ) as $status ) {
			$this->assertArrayHasKey( $status, $stati );
			$this->assertFalse( $stati[ $status ]->public );
		}
	}
}
