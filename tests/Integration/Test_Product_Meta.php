<?php
/**
 * The product's meta and its REST guard.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * The block editor is the writer now: every key is in REST behind
 * manage-products, rows are validated by their sanitizers, the server
 * stamps identity and currency on save, and the whole product REST surface
 * answers 404 to anyone without the capability.
 *
 * @group integration
 */
class Test_Product_Meta extends WP_UnitTestCase {

	private Product_Meta $meta;

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		// The framework's tear_down unregisters every meta key.
		$this->meta = new Product_Meta( new Settings(), new \PinkCrab\Gated_Access\Registration\Access_Taxonomy() );
		$this->meta->register_meta();

		$this->product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/** @testdox Every key is registered in REST, protected, and writable only by product managers. */
	public function test_meta_registered_for_the_block(): void {
		foreach ( array(
			Product_Meta::META_UUID,
			Product_Meta::META_PRICE,
			Product_Meta::META_CURRENCY,
			Product_Meta::META_DURATION,
			Product_Meta::META_VISIBILITY,
			Product_Meta::META_ITEMS,
			Product_Meta::META_EMAILS,
		) as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, Post_Types::PRODUCT ), "{$key} is not registered" );
			$this->assertTrue( $this->meta->protect_meta( false, $key, 'post' ), "{$key} is not protected" );
		}

		$registered = get_registered_meta_keys( 'post', Post_Types::PRODUCT );

		$this->assertTrue( (bool) $registered[ Product_Meta::META_PRICE ]['show_in_rest'], 'the block cannot save without REST meta' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_PRICE ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_PRICE ) );
	}

	/** @testdox Item rows are validated to type:id on write; garbage stores empty. */
	public function test_items_sanitized(): void {
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'file:42' );
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'movie:9' );

		$this->assertSame( array( 'file:42', '' ), get_post_meta( $this->product_id, Product_Meta::META_ITEMS, false ) );
	}

	/** @testdox Saving stamps the identity once and the shop currency every time. */
	public function test_stamp(): void {
		$this->meta->stamp( $this->product_id );

		$uuid = (string) get_post_meta( $this->product_id, Uuid::META, true );

		$this->assertSame( 36, strlen( $uuid ) );
		$this->assertSame( 'GBP', get_post_meta( $this->product_id, Product_Meta::META_CURRENCY, true ) );

		$this->meta->stamp( $this->product_id );

		$this->assertSame( $uuid, (string) get_post_meta( $this->product_id, Uuid::META, true ), 'a second save must not re-mint' );
	}

	/** @testdox The product REST surface answers 404 without manage-products, and works with it. */
	public function test_rest_guard(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		$route = new \PinkCrab\Gated_Access\Products\Product_Route( new Settings() );
		add_filter( 'rest_request_before_callbacks', array( $route, 'guard_product_rest' ), 10, 3 );
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new WP_REST_Request( 'GET', '/wp/v2/' . Post_Types::PRODUCT );

		wp_set_current_user( 0 );
		$this->assertSame( 404, rest_get_server()->dispatch( $request )->get_status(), 'the public must not enumerate products' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		remove_filter( 'rest_request_before_callbacks', array( $route, 'guard_product_rest' ) );
	}
}
