<?php
/**
 * The product URL.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Products\Product_Route;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * `/{segment}/{uuid}` is the only road in: the UUID resolves the single, a
 * slug or an ID answers 404 — never a redirect, which would be an oracle
 * for guessing the real URL — and permalinks answer the UUID form.
 *
 * @group integration
 */
class Test_Product_Route extends WP_UnitTestCase {

	private Product_Route $route;

	private int $product_id;

	private string $uuid;

	public function set_up(): void {
		parent::set_up();

		$this->route = new Product_Route( new Settings() );

		add_filter( 'query_vars', array( $this->route, 'register_query_vars' ) );
		add_filter( 'request', array( $this->route, 'route_request' ) );
		add_filter( 'post_type_link', array( $this->route, 'product_link' ), 10, 2 );

		$this->product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_status' => 'publish',
			)
		);
		$this->uuid       = Uuid::ensure( 'post', $this->product_id );
	}

	public function tear_down(): void {
		remove_filter( 'query_vars', array( $this->route, 'register_query_vars' ) );
		remove_filter( 'request', array( $this->route, 'route_request' ) );
		remove_filter( 'post_type_link', array( $this->route, 'product_link' ) );

		parent::tear_down();
	}

	/** @testdox The request filter maps a UUID to the product's own single query. */
	public function test_route_request_maps_the_uuid(): void {
		$mapped = $this->route->route_request( array( Product_Route::QUERY_VAR => $this->uuid ) );

		$this->assertSame( Post_Types::PRODUCT, $mapped['post_type'] ?? null );
		$this->assertSame( $this->product_id, $mapped['p'] ?? null );
	}

	/** @testdox The UUID resolves the product's single view. */
	public function test_uuid_resolves(): void {
		$this->go_to( '/?' . Product_Route::QUERY_VAR . '=' . $this->uuid );

		$this->assertTrue( is_singular( Post_Types::PRODUCT ) );
		$this->assertSame( $this->product_id, get_queried_object_id() );
	}

	/** @testdox An unknown UUID is a 404. */
	public function test_unknown_uuid_is_404(): void {
		$this->go_to( '/?' . Product_Route::QUERY_VAR . '=00000000-0000-4000-8000-000000000000' );

		$this->assertTrue( is_404() );
	}

	/** @testdox A draft's UUID is a 404 — drafts are invisible everywhere. */
	public function test_draft_uuid_is_404(): void {
		wp_update_post(
			array(
				'ID'          => $this->product_id,
				'post_status' => 'draft',
			)
		);

		$this->go_to( '/?' . Product_Route::QUERY_VAR . '=' . $this->uuid );

		$this->assertTrue( is_404() );
	}

	/** @testdox Reaching a product by post id is a 404, not a redirect. */
	public function test_id_access_is_404(): void {
		$this->go_to( '/?post_type=' . Post_Types::PRODUCT . '&p=' . $this->product_id );

		$this->assertTrue( is_404() );
	}

	/** @testdox The permalink answers the UUID URL, so every redirect points the only way in. */
	public function test_permalink_is_the_uuid_url(): void {
		$permalink = get_permalink( $this->product_id );

		$this->assertSame( home_url( '/access/' . $this->uuid . '/' ), $permalink );
	}

	/** @testdox A second ask answers the same identity — minting settles on first ask. */
	public function test_uuid_is_stable(): void {
		$this->assertSame( $this->uuid, Uuid::ensure( 'post', $this->product_id ) );
	}
}
