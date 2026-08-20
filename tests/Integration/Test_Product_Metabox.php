<?php
/**
 * The product metabox.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Product_Metabox;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * The keys are registered by the class that writes them, protected from
 * user-editable surfaces, and saved from the editor's own submit — items
 * validated to `type:id`, the allow-list to real addresses, the price to
 * minor units of the named currency.
 *
 * @group integration
 */
class Test_Product_Metabox extends WP_UnitTestCase {

	private Product_Metabox $metabox;

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		// The framework's tear_down unregisters every meta key.
		$this->metabox = new Product_Metabox( new Access_Taxonomy() );
		$this->metabox->register_meta();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->product_id = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
	}

	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/** @testdox Every key is registered against the product type and protected. */
	public function test_meta_registered_and_protected(): void {
		foreach ( array(
			Product_Metabox::META_PRICE,
			Product_Metabox::META_CURRENCY,
			Product_Metabox::META_DURATION,
			Product_Metabox::META_VISIBILITY,
			Product_Metabox::META_ITEMS,
			Product_Metabox::META_EMAILS,
		) as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, Post_Types::PRODUCT ), "{$key} is not registered" );
			$this->assertTrue( $this->metabox->protect_meta( false, $key, 'post' ), "{$key} is not protected" );
		}
	}

	/** @testdox A typed price stores as minor units of the named currency. */
	public function test_save_pricing(): void {
		$this->submit(
			array(
				'gatedmedia_price'      => '12.50',
				'gatedmedia_currency'   => 'gbp',
				'gatedmedia_duration'   => '30',
				'gatedmedia_visibility' => 'unlisted',
			)
		);

		$this->assertSame( '1250', get_post_meta( $this->product_id, Product_Metabox::META_PRICE, true ) );
		$this->assertSame( 'GBP', get_post_meta( $this->product_id, Product_Metabox::META_CURRENCY, true ) );
		$this->assertSame( '30', get_post_meta( $this->product_id, Product_Metabox::META_DURATION, true ) );
		$this->assertSame( 'unlisted', get_post_meta( $this->product_id, Product_Metabox::META_VISIBILITY, true ) );
	}

	/** @testdox An empty duration is lifetime, a garbage currency lands on GBP, visibility defaults listed. */
	public function test_save_defaults(): void {
		$this->submit(
			array(
				'gatedmedia_price'    => '',
				'gatedmedia_currency' => 'nonsense',
			)
		);

		$this->assertSame( '0', get_post_meta( $this->product_id, Product_Metabox::META_PRICE, true ) );
		$this->assertSame( 'GBP', get_post_meta( $this->product_id, Product_Metabox::META_CURRENCY, true ) );
		$this->assertSame( '', get_post_meta( $this->product_id, Product_Metabox::META_DURATION, true ) );
		$this->assertSame( 'listed', get_post_meta( $this->product_id, Product_Metabox::META_VISIBILITY, true ) );
	}

	/** @testdox Items keep only well-formed type:id rows of the three grantable types. */
	public function test_save_items_validates(): void {
		$this->submit(
			array(
				'gatedmedia_items' => array( 'file:42', 'group:9f3c', 'post:7', 'movie:9', 'file', 'file:' ),
			)
		);

		$this->assertSame(
			array( 'file:42', 'group:9f3c', 'post:7' ),
			get_post_meta( $this->product_id, Product_Metabox::META_ITEMS, false )
		);
	}

	/** @testdox A resubmit replaces the item rows rather than stacking them. */
	public function test_save_items_replaces(): void {
		add_post_meta( $this->product_id, Product_Metabox::META_ITEMS, 'file:1' );

		$this->submit( array( 'gatedmedia_items' => array( 'post:2' ) ) );

		$this->assertSame( array( 'post:2' ), get_post_meta( $this->product_id, Product_Metabox::META_ITEMS, false ) );
	}

	/** @testdox The allow-list keeps one row per valid address and drops the rest. */
	public function test_save_emails(): void {
		$this->submit(
			array(
				'gatedmedia_allowed_emails' => "one@example.com\nnot-an-email\n\ntwo@example.com",
			)
		);

		$this->assertSame(
			array( 'one@example.com', 'two@example.com' ),
			get_post_meta( $this->product_id, Product_Metabox::META_EMAILS, false )
		);
	}

	/** @testdox Without a valid nonce nothing is written. */
	public function test_save_requires_the_nonce(): void {
		$_POST = array(
			'gatedmedia_price'    => '99.00',
			'gatedmedia_currency' => 'GBP',
		);

		$this->metabox->save( $this->product_id );

		$this->assertSame( '', get_post_meta( $this->product_id, Product_Metabox::META_PRICE, true ) );
	}

	/** @testdox A user without gatedmedia_manage_products writes nothing. */
	public function test_save_requires_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->submit( array( 'gatedmedia_price' => '99.00' ) );

		$this->assertSame( '', get_post_meta( $this->product_id, Product_Metabox::META_PRICE, true ) );
	}

	/**
	 * Submits the box as the editor's save would.
	 *
	 * @param array<string, string|array<int, string>> $fields The posted fields.
	 */
	private function submit( array $fields ): void {
		$_POST = array_merge(
			array( Product_Metabox::NONCE_FIELD => wp_create_nonce( Product_Metabox::NONCE_FIELD ) ),
			$fields
		);

		$this->metabox->save( $this->product_id );
	}
}
