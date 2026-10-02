<?php
/**
 * Buying a product on sale, and what its page shows.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Admin\Coupon_Metabox;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Item_Label;
use PinkCrab\Gated_Access\Support\Money;

/**
 * Checkout charges the sale price, a coupon comes off the sale price, and the page strikes the full price through.
 *
 * @group integration
 */
class Test_Sale_Purchase extends WP_UnitTestCase {

	private const DEFAULTS = array(
		'product_id' => 0,
		'state'      => '',
		'items'      => array(),
		'price'      => 0,
		'full_price' => 0,
		'currency'   => 'GBP',
		'term'       => '',
		'nonce'      => '',
		'action_url' => '',
		'error'      => '',
		'coupon'     => array(),
		'page_url'   => '',
	);

	private Payment_Store $store;

	private Access_Writer $writer;

	private int $buyer_id;

	private int $post_item;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		// The framework's tear_down unregisters every meta key.
		( new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() ) )->register_meta();
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();
		( new Coupon_Metabox( new Settings() ) )->register_meta();

		$this->store     = new Payment_Store();
		$this->writer    = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->buyer_id  = self::factory()->user->create( array( 'user_email' => 'buyer@example.com' ) );
		$this->post_item = self::factory()->post->create();
	}

	public function tear_down(): void {
		unset( $_GET[ Checkout_Action::COUPON_FIELD ] );
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/** @testdox A product on sale is charged the sale price. */
	public function test_purchase_charges_the_sale(): void {
		$product = $this->product( 1500, 1200 );
		$gateway = $this->fake_gateway();

		$this->checkout( $gateway )->purchase( $product, $this->buyer_id );

		$this->assertSame( 1200, $this->store->paged( 1, 1 )[0]->amount_total );
		$this->assertSame( 1200, $gateway->asked->amount_total );
	}

	/** @testdox A product on a percentage sale is charged that percentage off. */
	public function test_purchase_charges_a_percentage_sale(): void {
		$product = $this->product( 1500, null );
		update_post_meta( $product, Product_Meta::META_SALE_TYPE, Product_Meta::SALE_PERCENT );
		update_post_meta( $product, Product_Meta::META_SALE_VALUE, 20 );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$this->assertSame( 1200, $this->store->paged( 1, 1 )[0]->amount_total );
	}

	/** @testdox A product with no sale is charged the full price. */
	public function test_purchase_without_a_sale(): void {
		$product = $this->product( 1500, null );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$this->assertSame( 1500, $this->store->paged( 1, 1 )[0]->amount_total );
	}

	/** @testdox An amount off as large as the price is ignored, and the full price is charged. */
	public function test_purchase_ignores_a_sale_to_nothing(): void {
		$product = $this->product( 1000, null );
		update_post_meta( $product, Product_Meta::META_SALE_TYPE, Product_Meta::SALE_AMOUNT );
		update_post_meta( $product, Product_Meta::META_SALE_VALUE, 1000 );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id );

		$this->assertSame( 1000, $this->store->paged( 1, 1 )[0]->amount_total );
	}

	/** @testdox A percentage coupon comes off the sale price. */
	public function test_percent_coupon_on_the_sale(): void {
		$product = $this->product( 1500, 1200 );
		$this->coupon( 'TENOFF', 'percent', 10 );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id, 'TENOFF' );

		$payment = $this->store->paged( 1, 1 )[0];

		$this->assertSame( 1080, $payment->amount_total );
		$this->assertSame( 120, $payment->discount_amount );
	}

	/** @testdox A fixed coupon comes off the sale price. */
	public function test_fixed_coupon_on_the_sale(): void {
		$product = $this->product( 1500, 1200 );
		$this->coupon( 'TWOOFF', 'fixed', 200 );

		$this->checkout( $this->fake_gateway() )->purchase( $product, $this->buyer_id, 'TWOOFF' );

		$this->assertSame( 1000, $this->store->paged( 1, 1 )[0]->amount_total );
	}

	/** @testdox The coupon preview prices from the sale. */
	public function test_preview_from_the_sale(): void {
		$product = $this->product( 1500, 1200 );
		$this->coupon( 'TENOFF', 'percent', 10 );

		$preview = $this->checkout( $this->fake_gateway() )->preview( $product, $this->buyer_id, 'TENOFF' );

		$this->assertTrue( $preview['applied'] );
		$this->assertSame( 120, $preview['discount'] );
		$this->assertSame( 1080, $preview['total'] );
	}

	/** @testdox With no coupon the preview total is the sale price. */
	public function test_preview_without_a_coupon(): void {
		$product = $this->product( 1500, 1200 );

		$this->assertSame( 1200, $this->checkout( $this->fake_gateway() )->preview( $product, $this->buyer_id, '' )['total'] );
	}

	/** @testdox The page data carries the sale as the price and the full price beside it. */
	public function test_offer_data_on_sale(): void {
		$data = $this->offer()->product( self::DEFAULTS, $this->product( 1500, 1200 ) );

		$this->assertSame( 1200, $data['price'] );
		$this->assertSame( 1500, $data['full_price'] );
		$this->assertSame( Product_Offer::STATE_PAID, $data['state'] );
	}

	/** @testdox With no sale, the price and the full price are the same. */
	public function test_offer_data_without_a_sale(): void {
		$data = $this->offer()->product( self::DEFAULTS, $this->product( 1500, null ) );

		$this->assertSame( 1500, $data['price'] );
		$this->assertSame( 1500, $data['full_price'] );
	}

	/** @testdox The product page strikes the full price through before the sale price. */
	public function test_page_strikes_the_full_price(): void {
		$html = $this->page( $this->product( 1500, 1200 ) );

		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__original">' . preg_quote( esc_html( Money::format( 1500, 'GBP' ) ), '#' ) . '<#', $html );
		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__amount">' . preg_quote( esc_html( Money::format( 1200, 'GBP' ) ), '#' ) . '<#', $html );
	}

	/** @testdox With no sale and no coupon, nothing is struck through. */
	public function test_page_without_a_sale(): void {
		$html = $this->page( $this->product( 1500, null ) );

		$this->assertStringNotContainsString( 'gatedmedia-price-block__original', $html );
		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__amount">' . preg_quote( esc_html( Money::format( 1500, 'GBP' ) ), '#' ) . '<#', $html );
	}

	/** @testdox On sale with a coupon, the full price is struck through and the amount is the coupon's total. */
	public function test_page_with_sale_and_coupon(): void {
		$product = $this->product( 1500, 1200 );
		$this->coupon( 'TENOFF', 'percent', 10 );
		$_GET[ Checkout_Action::COUPON_FIELD ] = 'TENOFF';

		$html = $this->page( $product );

		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__original">' . preg_quote( esc_html( Money::format( 1500, 'GBP' ) ), '#' ) . '<#', $html );
		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__amount">' . preg_quote( esc_html( Money::format( 1080, 'GBP' ) ), '#' ) . '<#', $html );
	}

	/** @testdox The pinned buy bar names the sale price. */
	public function test_pinned_bar_names_the_sale(): void {
		$html = $this->page( $this->product( 1500, 1200 ) );

		$this->assertStringContainsString( esc_html( Money::format( 1200, 'GBP' ) ), $html );
		$this->assertSame( 1, substr_count( $html, esc_html( Money::format( 1500, 'GBP' ) ) ), 'the full price shows once, struck through, and nowhere else' );
	}

	/**
	 * A published product at this price, with this sale, granting one post.
	 *
	 * @param int      $price Minor units.
	 * @param int|null $sale  Minor units, null for none.
	 */
	private function product( int $price, ?int $sale ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_title'  => 'The Bundle',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		update_post_meta( $product_id, Product_Meta::META_CURRENCY, 'GBP' );
		add_post_meta( $product_id, Product_Meta::META_ITEMS, "post:{$this->post_item}" );

		// Stored as the amount off that gives this sale price.
		if ( null !== $sale ) {
			update_post_meta( $product_id, Product_Meta::META_SALE_TYPE, Product_Meta::SALE_AMOUNT );
			update_post_meta( $product_id, Product_Meta::META_SALE_VALUE, $price - $sale );
		}

		return $product_id;
	}

	/**
	 * A published coupon.
	 *
	 * @param string $code  The code.
	 * @param string $type  percent or fixed.
	 * @param int    $value Whole percent, or minor units.
	 */
	private function coupon( string $code, string $type, int $value ): void {
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
	}

	/**
	 * The product page as the buyer sees it.
	 *
	 * @param int $product_id The product.
	 */
	private function page( int $product_id ): string {
		wp_set_current_user( $this->buyer_id );
		$GLOBALS['post'] = get_post( $product_id );

		return Block::render( 'gated-media-access/product-details', array() );
	}

	/**
	 * The page data, as the plugin builds it.
	 */
	private function offer(): Product_Offer {
		$taxonomy = new Access_Taxonomy();

		wp_set_current_user( $this->buyer_id );

		return new Product_Offer(
			$this->checkout( $this->fake_gateway() ),
			new Resolver( $taxonomy ),
			new Access_Lookup(),
			new Item_Label( $taxonomy ),
			new Settings()
		);
	}

	/**
	 * Checkout over the given gateway.
	 *
	 * @param Stripe_Gateway $gateway The fake.
	 */
	private function checkout( Stripe_Gateway $gateway ): Checkout {
		return new Checkout( $this->store, $this->writer, $gateway, new Resolver( new Access_Taxonomy() ), new Settings() );
	}

	/**
	 * A gateway answering a canned session and keeping the payment it was asked about.
	 */
	private function fake_gateway(): Stripe_Gateway {
		return new class( new Settings() ) extends Stripe_Gateway {
			/**
			 * The payment the session was created for.
			 *
			 * @var Payment|null
			 */
			public ?Payment $asked = null;

			/**
			 * Answers a canned session.
			 *
			 * @param Payment $payment      The pending row.
			 * @param string  $product_name Ignored.
			 * @param string  $success_url  Ignored.
			 * @param string  $cancel_url   Ignored.
			 * @param string  $email        Ignored.
			 * @return array{id: string, url: string}
			 */
			public function create_checkout_session( Payment $payment, string $product_name, string $success_url, string $cancel_url, string $email ): array|WP_Error {
				$this->asked = $payment;

				return array(
					'id'  => 'cs_fake_1',
					'url' => 'https://stripe.example/session',
				);
			}
		};
	}
}
