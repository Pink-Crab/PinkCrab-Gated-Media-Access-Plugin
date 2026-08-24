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
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Offer;
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
class Test_Product_Offer extends WP_UnitTestCase {

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
		'coupon'     => array(),
		'page_url'   => '',
	);

	private Product_Offer $data;

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

		$this->data = new Product_Offer(
			$checkout,
			new Resolver( $taxonomy ),
			$lookup,
			new Item_Label( $taxonomy ),
			new Settings()
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

		// The framework's tear_down unregisters every meta key.
		( new Coupon_Metabox() )->register_meta();

		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		unset( $_GET[ Checkout_Action::COUPON_FIELD ], $_GET[ Checkout_Action::ERROR_FLAG ] );

		parent::tear_down();
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

		$this->assertSame( Product_Offer::STATE_SIGNED_OUT, $this->state() );
	}

	/** @testdox A priced product a signed-in stranger may buy is simply for sale. */
	public function test_paid(): void {
		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
	}

	/** @testdox A product costing nothing is joined, not bought. */
	public function test_free(): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );

		$this->assertSame( Product_Offer::STATE_FREE, $this->state() );
	}

	/** @testdox Holding everything it grants reads as held, not as a second sale. */
	public function test_held(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_Offer::STATE_HELD, $this->state() );
	}

	/** @testdox Holding only part of a bundle is not holding it. */
	public function test_partly_held_is_not_held(): void {
		$second = self::factory()->post->create();
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'post:' . $second );

		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
	}

	/** @testdox Access that ran out says so, rather than reading as a first purchase. */
	public function test_lapsed(): void {
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 30, 'admin' );
		$this->assertIsInt( $access_id );

		$this->writer->expire( $access_id );

		$this->assertSame( Product_Offer::STATE_LAPSED, $this->state() );
	}

	/** @testdox An address off the allow-list cannot buy, and is told why. */
	public function test_ineligible(): void {
		add_post_meta( $this->product_id, Product_Meta::META_EMAILS, 'someone-else@example.com' );

		$this->assertSame( Product_Offer::STATE_INELIGIBLE, $this->state() );
	}

	/** @testdox Already holding it outranks being off the allow-list. */
	public function test_held_beats_ineligible(): void {
		add_post_meta( $this->product_id, Product_Meta::META_EMAILS, 'someone-else@example.com' );
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );

		$this->assertSame( Product_Offer::STATE_HELD, $this->state() );
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

	/**
	 * `holds_everything()` is guarded on the product having items at all,
	 * because "every item is held" is vacuously true of a product granting
	 * nothing — and a product that granted nothing would otherwise read as
	 * already held by everyone, refusing the sale to every visitor.
	 *
	 * @testdox A product granting nothing is still for sale, not already held by everybody.
	 */
	public function test_a_product_with_no_items_is_not_held(): void {
		delete_post_meta( $this->product_id, Product_Meta::META_ITEMS );

		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
		$this->assertSame( array(), $this->data->product( self::DEFAULTS, $this->product_id )['items'] );
	}

	/** @testdox A duration stored as a plain zero is lifetime, not "access for 0 days". */
	public function test_a_zero_duration_is_lifetime(): void {
		update_post_meta( $this->product_id, Product_Meta::META_DURATION, '0' );

		$this->assertSame( 'Lifetime access', $this->data->product( self::DEFAULTS, $this->product_id )['term'] );
	}

	/** @testdox An item the product names but that no longer exists still accounts for itself. */
	public function test_a_deleted_item_keeps_its_line(): void {
		delete_post_meta( $this->product_id, Product_Meta::META_ITEMS );
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'file:999999' );

		$items = $this->data->product( self::DEFAULTS, $this->product_id )['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( 'A file', $items[0]['text'] );
	}

	/** @testdox A malformed item entry is dropped rather than drawn as an empty row. */
	public function test_a_malformed_item_is_dropped(): void {
		delete_post_meta( $this->product_id, Product_Meta::META_ITEMS );
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'post:' . $this->post_id );
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, 'nonsense' );

		$this->assertCount( 1, $this->data->product( self::DEFAULTS, $this->product_id )['items'] );
	}

	// -------------------------------------------------------------------------
	// §6.14 — the coupon, applied by a code in the query string.
	//
	// Pressing Apply used to submit the buy form, so it went to Stripe at full
	// price. It now reloads the product page with the code on it and this is
	// what prices that page. Nothing here spends a coupon or writes anything:
	// `Checkout` judges the code again when the purchase is actually made.
	// -------------------------------------------------------------------------

	/** @testdox With no code in the query there is no coupon and the price stands. */
	public function test_no_code_no_coupon(): void {
		wp_set_current_user( $this->user_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 4900 );

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertSame( '', $coupon['code'] );
		$this->assertFalse( $coupon['applied'] );
		$this->assertSame( 0, $coupon['discount'] );
		$this->assertSame( 4900, $coupon['total'] );
		$this->assertSame( '', $coupon['error'] );
	}

	/** @testdox A valid code in the query says what it takes off and what is left. */
	public function test_a_valid_code_prices_the_page(): void {
		wp_set_current_user( $this->user_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 4900 );
		$this->coupon( 'save20', 'percent', 20 );

		$_GET[ Checkout_Action::COUPON_FIELD ] = 'save20';

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertSame( 'save20', $coupon['code'] );
		$this->assertTrue( $coupon['applied'] );
		$this->assertSame( 980, $coupon['discount'] );
		$this->assertSame( 3920, $coupon['total'] );
		$this->assertSame( '', $coupon['error'] );
	}

	/** @testdox A code that is not a coupon says so and leaves the price alone. */
	public function test_a_rejected_code_keeps_the_full_price(): void {
		wp_set_current_user( $this->user_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 4900 );

		$_GET[ Checkout_Action::COUPON_FIELD ] = 'invented';

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertSame( 'invented', $coupon['code'] );
		$this->assertFalse( $coupon['applied'] );
		$this->assertSame( 4900, $coupon['total'] );
		$this->assertSame( 'That coupon cannot be used.', $coupon['error'] );
	}

	/**
	 * Per-user limits need a user, so a coupon cannot be judged for a visitor
	 * who is not signed in — and §7.6 offers them an account rather than a
	 * price. It answers "no coupon" rather than an error, because they have not
	 * done anything wrong.
	 *
	 * @testdox Signed out, a valid code is neither applied nor called invalid.
	 */
	public function test_signed_out_applies_nothing(): void {
		wp_set_current_user( 0 );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 4900 );
		$this->coupon( 'save20', 'percent', 20 );

		$_GET[ Checkout_Action::COUPON_FIELD ] = 'save20';

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertFalse( $coupon['applied'] );
		$this->assertSame( '', $coupon['error'] );
		$this->assertSame( 4900, $coupon['total'] );
	}

	/** @testdox A free product has nothing for a coupon to take off. */
	public function test_a_free_product_ignores_a_coupon(): void {
		wp_set_current_user( $this->user_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );
		$this->coupon( 'save20', 'percent', 20 );

		$_GET[ Checkout_Action::COUPON_FIELD ] = 'save20';

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertFalse( $coupon['applied'] );
		$this->assertSame( 0, $coupon['total'] );
		$this->assertSame( '', $coupon['error'] );
	}

	/** @testdox A discount larger than the price clamps to the price, never past zero. */
	public function test_a_discount_cannot_go_below_zero(): void {
		wp_set_current_user( $this->user_id );
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 1000 );
		$this->coupon( 'huge', 'fixed', 999999 );

		$_GET[ Checkout_Action::COUPON_FIELD ] = 'huge';

		$coupon = $this->data->product( self::DEFAULTS, $this->product_id )['coupon'];

		$this->assertTrue( $coupon['applied'] );
		$this->assertSame( 1000, $coupon['discount'] );
		$this->assertSame( 0, $coupon['total'] );
	}

	/** @testdox The page url is the product's own permalink, for Apply to come back to. */
	public function test_page_url_is_the_permalink(): void {
		wp_set_current_user( $this->user_id );

		$this->assertSame(
			get_permalink( $this->product_id ),
			$this->data->product( self::DEFAULTS, $this->product_id )['page_url']
		);
	}

	/**
	 * A published coupon with a code, a type and a value.
	 *
	 * @param string $code  The code (becomes post_name).
	 * @param string $type  percent or fixed.
	 * @param int    $value Whole percent, or minor units.
	 */
	private function coupon( string $code, string $type, int $value ): int {
		$coupon_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::COUPON,
				'post_title'  => $code,
				'post_name'   => $code,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $coupon_id, Coupon_Metabox::META_TYPE, $type );
		update_post_meta( $coupon_id, Coupon_Metabox::META_VALUE, $value );

		return $coupon_id;
	}
}
