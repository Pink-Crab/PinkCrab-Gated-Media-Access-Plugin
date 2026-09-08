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
use PinkCrab\Gated_Access\Settings\Settings_Page;

/**
 * The three containers exist with the shape they are meant to have: what is public, what has a UI, and, just as deliberately, what has neither.
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

	/** @testdox Access is pure data with a list screen: not public, no query var, UI under the plugin menu. */
	public function test_access_is_hidden_from_the_front(): void {
		$access = get_post_type_object( Post_Types::ACCESS );

		$this->assertNotNull( $access );
		$this->assertFalse( $access->public );
		// The core list screen is the Access screen.
		$this->assertTrue( $access->show_ui );
		$this->assertSame( 'gated-media-access', $access->show_in_menu );
		$this->assertFalse( $access->query_var );
		$this->assertFalse( $access->show_in_rest );
	}

	/**
	 * @testdox Every screen this plugin owns lives under its own menu, not beside it.
	 *
	 * Products and Coupons each had a top-level entry of their own, so one plugin occupied three places in the sidebar, and a missing `show_in_menu` is invisible in a diff.
	 *
	 * @dataProvider owned_types
	 *
	 * @param string $type The post type.
	 */
	public function test_every_type_is_under_the_plugin_menu( string $type ): void {
		$object = get_post_type_object( $type );

		$this->assertNotNull( $object );
		$this->assertSame( Settings_Page::MENU_SLUG, $object->show_in_menu, $type );
	}

	/**
	 * The types with an admin screen.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function owned_types(): array {
		return array(
			'access'  => array( Post_Types::ACCESS ),
			'product' => array( Post_Types::PRODUCT ),
			'coupon'  => array( Post_Types::COUPON ),
		);
	}

	/** @testdox The Access screen is gated on the give-access capability, and core's write surfaces are shut. */
	public function test_access_caps_gate_the_screen_and_shut_core_writes(): void {
		$access = get_post_type_object( Post_Types::ACCESS );

		$this->assertNotNull( $access );
		$this->assertSame( 'gatedmedia_give_access', $access->cap->edit_posts );
		$this->assertSame( 'gatedmedia_give_access', $access->cap->edit_others_posts );
		$this->assertSame( 'do_not_allow', $access->cap->create_posts );
		$this->assertSame( 'do_not_allow', $access->cap->publish_posts );
		$this->assertSame( 'do_not_allow', $access->cap->delete_posts );

		$administrator = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertTrue( user_can( $administrator, $access->cap->edit_posts ) );
		$this->assertFalse( user_can( $subscriber, $access->cap->edit_posts ) );
	}

	/** @testdox Products and coupons are gated on manage-products, so an administrator sees their menus. */
	public function test_commerce_caps_gate_on_manage_products(): void {
		foreach ( array( Post_Types::PRODUCT, Post_Types::COUPON ) as $type ) {
			$object = get_post_type_object( $type );

			$this->assertNotNull( $object );
			$this->assertSame( 'gatedmedia_manage_products', $object->cap->edit_posts, "{$type} is not gated on manage-products" );
			$this->assertSame( 'gatedmedia_manage_products', $object->cap->create_posts, "{$type} cannot be created by managers" );
		}

		$administrator = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );

		$this->assertTrue( user_can( $administrator, 'gatedmedia_manage_products' ) );
		$this->assertFalse( user_can( $subscriber, 'gatedmedia_manage_products' ) );
	}

	/**
	 * @testdox Access supports nothing at all.
	 *
	 * Guards the `supports => false` argument: an empty array there would silently gain the title and editor defaults.
	 */
	public function test_access_supports_nothing(): void {
		$this->assertSame( array(), get_all_post_type_supports( Post_Types::ACCESS ) );
	}

	/** @testdox Product renders publicly but is undiscoverable: no archive, no search, no sitemap; REST only for the guarded block editor. */
	public function test_product_is_public_but_undiscoverable(): void {
		$product = get_post_type_object( Post_Types::PRODUCT );

		$this->assertNotNull( $product );
		$this->assertTrue( $product->public );
		$this->assertFalse( $product->has_archive );
		$this->assertTrue( $product->exclude_from_search );
		// In REST for the block editor, and Product_Meta's guard 404s the surface for anyone without manage-products.
		$this->assertTrue( $product->show_in_rest );

		$sitemap_types = ( new Post_Types() )->hide_products_from_sitemaps(
			array( Post_Types::PRODUCT => $product )
		);

		$this->assertArrayNotHasKey( Post_Types::PRODUCT, $sitemap_types );
	}

	/**
	 * @testdox Only the product supports custom-fields, the flag the block editor needs to save meta.
	 *
	 * The Custom Fields metabox is gated on post_type_supports(), so access and coupon keep the flag off, and the product needs it on or the block editor silently drops every meta save.
	 *
	 * Its keys stay out of the panel anyway, because every one of them is is_protected_meta.
	 */
	public function test_custom_fields_support(): void {
		$this->assertFalse( post_type_supports( Post_Types::ACCESS, 'custom-fields' ) );
		$this->assertTrue( post_type_supports( Post_Types::PRODUCT, 'custom-fields' ) );
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
