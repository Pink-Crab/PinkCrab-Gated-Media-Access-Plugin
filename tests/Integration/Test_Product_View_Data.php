<?php
/**
 * The product page's data.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_View_Data;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * §7.6 is six states, and choosing the wrong one is not a cosmetic mistake:
 * it either offers to sell something the person already owns, or hides the
 * buy control from someone entitled to press it.
 *
 * Ordering is asserted deliberately — "you already have this" has to beat
 * "you are not eligible", or a lapsed allow-list would tell an existing holder
 * they were never welcome.
 *
 * @group integration
 */
class Test_Product_View_Data extends WP_UnitTestCase {

	private const DEFAULTS = array(
		'product_id' => 0,
		'state'      => '',
		'items'      => array(),
		'price'      => 0,
		'currency'   => 'GBP',
		'term'       => '',
		'nonce'      => '',
		'action_url' => '',
		'error'      => '',
	);

	private Product_View_Data $data;

	private Access_Writer $writer;

	private int $user_id;

	private int $product_id;

	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy = new Access_Taxonomy();
		$lookup   = new Access_Lookup();

		$this->writer = new Access_Writer( new Access_Validator( $taxonomy ), $lookup );

		$checkout = new Checkout(
			new Payment_Store(),
			$this->writer,
			new Stripe_Gateway( new Settings() )
		);

		$this->data = new Product_View_Data(
			$checkout,
			new Resolver( $taxonomy ),
			$lookup,
			new Item_Label( $taxonomy )
		);

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'The gilts note' ) );

		$this->product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_title'  => 'Q3 bundle',
				'post_status' => 'publish',
			)
		);

		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'post:' . $this->post_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 2500 );

		wp_set_current_user( $this->user_id );
	}

	/**
	 * The state the page would draw.
	 */
	private function state(): string {
		return $this->data->product( self::DEFAULTS, $this->product_id )['state'];
	}

	/** @testdox An unknown product is left with the defaults, drawing nothing. */
	public function test_unknown_product_draws_nothing(): void {
		$this->assertSame( self::DEFAULTS, $this->data->product( self::DEFAULTS, 0 ) );
		$this->assertSame( self::DEFAULTS, $this->data->product( self::DEFAULTS, $this->post_id ) );
	}

	/** @testdox Signed out, the page offers an account rather than a price. */
	public function test_signed_out(): void {
		wp_set_current_user( 0 );

		$this->assertSame( Product_View_Data::STATE_SIGNED_OUT, $this->state() );
	}

	/** @testdox A priced product a signed-in stranger may buy is simply for sale. */
	public function test_paid(): void {
		$this->assertSame( Product_View_Data::STATE_PAID, $this->state() );
	}

	/** @testdox A product costing nothing is joined, not bought. */
	public function test_free(): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );

		$this->assertSame( Product_View_Data::STATE_FREE, $this->state() );
	}

	/** @testdox Holding everything it grants reads as held, not as a second sale. */
	public function test_held(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_View_Data::STATE_HELD, $this->state() );
	}

	/** @testdox Holding only part of a bundle is not holding it. */
	public function test_partly_held_is_not_held(): void {
		$second = self::factory()->post->create();
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'post:' . $second );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_View_Data::STATE_PAID, $this->state() );
	}

	/** @testdox Access that ran out says so, rather than reading as a first purchase. */
	public function test_lapsed(): void {
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 30, 'admin' );
		$this->assertIsInt( $access_id );

		$this->writer->expire( $access_id );

		$this->assertSame( Product_View_Data::STATE_LAPSED, $this->state() );
	}

	/** @testdox An address off the allow-list cannot buy, and is told why. */
	public function test_ineligible(): void {
		add_post_meta( $this->product_id, Product_Meta::META_EMAILS, 'someone-else@example.com' );

		$this->assertSame( Product_View_Data::STATE_INELIGIBLE, $this->state() );
	}

	/** @testdox Already holding it outranks being off the allow-list. */
	public function test_held_beats_ineligible(): void {
		add_post_meta( $this->product_id, Product_Meta::META_EMAILS, 'someone-else@example.com' );
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_View_Data::STATE_HELD, $this->state() );
	}

	/** @testdox The contents name the items, and the form carries what checkout demands. */
	public function test_shape(): void {
		$data = $this->data->product( self::DEFAULTS, $this->product_id );

		$this->assertSame( 'The gilts note', $data['items'][0]['text'] );
		$this->assertSame( 'i-article', $data['items'][0]['icon'] );
		$this->assertSame( 2500, $data['price'] );
		$this->assertNotSame( '', $data['nonce'] );
		$this->assertStringContainsString( 'admin-post.php', $data['action_url'] );
	}

	/** @testdox A duration is said in the largest unit it divides into. */
	public function test_term_wording(): void {
		update_post_meta( $this->product_id, Product_Meta::META_DURATION, 365 );
		$this->assertSame( 'Access for 1 year', $this->data->product( self::DEFAULTS, $this->product_id )['term'] );

		update_post_meta( $this->product_id, Product_Meta::META_DURATION, 90 );
		$this->assertSame( 'Access for 3 months', $this->data->product( self::DEFAULTS, $this->product_id )['term'] );

		update_post_meta( $this->product_id, Product_Meta::META_DURATION, 10 );
		$this->assertSame( 'Access for 10 days', $this->data->product( self::DEFAULTS, $this->product_id )['term'] );

		delete_post_meta( $this->product_id, Product_Meta::META_DURATION );
		$this->assertSame( 'Lifetime access', $this->data->product( self::DEFAULTS, $this->product_id )['term'] );
	}

	/** @testdox A refused checkout comes back with wording, including for a code we do not know. */
	public function test_error_wording(): void {
		$_GET[ Checkout_Action::ERROR_FLAG ] = 'gatedmedia_bad_coupon';
		$this->assertSame( 'That coupon cannot be used.', $this->data->product( self::DEFAULTS, $this->product_id )['error'] );

		$_GET[ Checkout_Action::ERROR_FLAG ] = 'something_we_never_wrote';
		$this->assertNotSame( '', $this->data->product( self::DEFAULTS, $this->product_id )['error'] );

		unset( $_GET[ Checkout_Action::ERROR_FLAG ] );
		$this->assertSame( '', $this->data->product( self::DEFAULTS, $this->product_id )['error'] );
	}
}
