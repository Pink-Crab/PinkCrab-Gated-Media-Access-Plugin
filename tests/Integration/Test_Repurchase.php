<?php
/**
 * Whether a product can be bought again.
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
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Checkout_Action;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Products\Repurchase;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Item_Label;
use PinkCrab\Gated_Access\Support\Labels;
use PinkCrab\Gated_Access\Support\Money;

/**
 * Once it runs out (the default), any time, or never: on the product page, and again at checkout.
 *
 * @group integration
 */
class Test_Repurchase extends WP_UnitTestCase {

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

	private Access_Writer $writer;

	private Payment_Store $store;

	private int $user_id;

	private int $post_id;

	private int $product_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		// The framework's tear_down unregisters every meta key.
		( new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() ) )->register_meta();
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->store   = new Payment_Store();
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post_id = self::factory()->post->create( array( 'post_title' => 'The gilts note' ) );

		$this->product_id = $this->product( 2500, array( "post:{$this->post_id}" ) );

		wp_set_current_user( $this->user_id );
	}

	public function tear_down(): void {
		unset( $GLOBALS['post'] );
		unset( $_GET[ Checkout_Action::ERROR_FLAG ] );

		parent::tear_down();
	}

	/** @testdox A product with no setting can be bought again once it runs out. */
	public function test_mode_defaults_to_lapsed(): void {
		$this->assertSame( Product_Meta::REPURCHASE_LAPSED, Repurchase::mode( $this->product_id ) );
	}

	/** @testdox The setting reads any time and never as set. */
	public function test_mode_reads_the_setting(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->assertSame( Product_Meta::REPURCHASE_ALWAYS, Repurchase::mode( $this->product_id ) );

		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->assertSame( Product_Meta::REPURCHASE_NEVER, Repurchase::mode( $this->product_id ) );
	}

	/** @testdox An unknown setting, written past the meta's sanitiser, reads as the default. */
	public function test_mode_unknown_is_lapsed(): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $this->product_id,
				'meta_key'   => Product_Meta::META_REPURCHASE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'sometimes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $this->product_id, 'post_meta' );

		$this->assertSame( Product_Meta::REPURCHASE_LAPSED, Repurchase::mode( $this->product_id ) );
	}

	/** @testdox Signed out there is nobody to refuse. */
	public function test_signed_out_is_never_refused(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );

		$this->assertSame( '', $this->repurchase()->refusal( $this->product_id, 0 ) );
	}

	/** @testdox By default, holding it refuses buying it again. */
	public function test_default_held(): void {
		$this->hold();

		$this->assertSame( Product_Offer::STATE_HELD, $this->refusal() );
	}

	/** @testdox By default, once it has run out it may be bought again. */
	public function test_default_lapsed(): void {
		$this->lapse();

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox By default, never having had it refuses nothing. */
	public function test_default_never_had(): void {
		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox Any time: holding it refuses nothing. */
	public function test_always_held(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->hold();

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox Any time: having had it refuses nothing. */
	public function test_always_lapsed(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->lapse();

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox Never: holding it refuses as once only. */
	public function test_never_held(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$this->assertSame( Product_Offer::STATE_ONCE, $this->refusal() );
	}

	/** @testdox Never: having had it, even after it ran out, refuses as once only. */
	public function test_never_lapsed(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->lapse();

		$this->assertSame( Product_Offer::STATE_ONCE, $this->refusal() );
	}

	/** @testdox Never: someone who has never had it may buy it. */
	public function test_never_never_had(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox Never: holding one of two items, and never having had the other, refuses nothing. */
	public function test_never_partly_held(): void {
		$other = self::factory()->post->create();
		add_post_meta( $this->product_id, Product_Meta::META_ITEMS, "post:{$other}" );
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox A product with no items is never held, so is never refused as held. */
	public function test_no_items(): void {
		delete_post_meta( $this->product_id, Product_Meta::META_ITEMS );

		$this->assertSame( '', $this->refusal() );
	}

	/** @testdox The page: held by default reads as held. */
	public function test_page_default_held(): void {
		$this->hold();

		$this->assertSame( Product_Offer::STATE_HELD, $this->state() );
	}

	/** @testdox The page: any time and held is for sale again. */
	public function test_page_always_held(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->hold();

		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
	}

	/** @testdox The page: any time and held, free, is joined again. */
	public function test_page_always_held_free(): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->hold();

		$this->assertSame( Product_Offer::STATE_FREE, $this->state() );
	}

	/** @testdox The page: any time and held now, with an older record that ran out, is not called lapsed. */
	public function test_page_always_held_with_an_old_record(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->lapse();
		$this->hold();

		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
	}

	/** @testdox The page: any time and run out still says it ran out. */
	public function test_page_always_lapsed(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->lapse();

		$this->assertSame( Product_Offer::STATE_LAPSED, $this->state() );
	}

	/** @testdox The page: never and held reads as once only. */
	public function test_page_never_held(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$this->assertSame( Product_Offer::STATE_ONCE, $this->state() );
	}

	/** @testdox The page: never and run out reads as once only, not lapsed. */
	public function test_page_never_lapsed(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->lapse();

		$this->assertSame( Product_Offer::STATE_ONCE, $this->state() );
	}

	/** @testdox The page: never, and never had, is for sale. */
	public function test_page_never_never_had(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );

		$this->assertSame( Product_Offer::STATE_PAID, $this->state() );
	}

	/** @testdox Checkout refuses a priced product the buyer still holds, by default. */
	public function test_checkout_default_held_priced(): void {
		$this->hold();

		$this->assertRefused( $this->buy() );
	}

	/** @testdox Checkout lets a held free product through, as a double press always was, granting nothing new. */
	public function test_checkout_default_held_free(): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );
		$this->hold();

		$this->assertIsArray( $this->buy() );
	}

	/** @testdox Checkout sells a priced product that has run out, by default. */
	public function test_checkout_default_lapsed_priced(): void {
		$this->lapse();

		$this->assertSame( array( 'redirect' => 'https://stripe.example/session' ), $this->buy() );
	}

	/** @testdox Checkout sells a held product set to any time. */
	public function test_checkout_always_held(): void {
		$this->set_mode( Product_Meta::REPURCHASE_ALWAYS );
		$this->hold();

		$this->assertSame( array( 'redirect' => 'https://stripe.example/session' ), $this->buy() );
	}

	/** @testdox Checkout refuses a once-only product that has run out. */
	public function test_checkout_never_lapsed(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->lapse();

		$this->assertRefused( $this->buy() );
	}

	/** @testdox Checkout refuses a once-only free product, held or run out. */
	public function test_checkout_never_free(): void {
		update_post_meta( $this->product_id, Product_Meta::META_PRICE, 0 );
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->lapse();

		$this->assertRefused( $this->buy() );

		$this->hold();

		$this->assertRefused( $this->buy() );
	}

	/** @testdox Checkout sells a once-only product to someone who has never had it. */
	public function test_checkout_never_never_had(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );

		$this->assertSame( array( 'redirect' => 'https://stripe.example/session' ), $this->buy() );
	}

	/** @testdox A refused purchase starts no payment. */
	public function test_checkout_refusal_writes_nothing(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$this->buy();

		$this->assertSame( array(), $this->store->paged( 1, 10 ) );
	}

	/** @testdox The once-only page draws a disabled Access granted button and the way to their access, and nothing that buys. */
	public function test_page_draws_a_disabled_button(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$html = $this->page();

		$this->assertMatchesRegularExpression( '#<button[^>]*class="gatedmedia-button gatedmedia-button--primary gatedmedia-button--full"[^>]*disabled[^>]*>\s*<span>' . preg_quote( Labels::text( 'product.once.button' ), '#' ) . '</span>#s', $html );
		$this->assertStringContainsString( esc_html( Labels::text( 'product.held.button' ) ), $html );
		$this->assertStringNotContainsString( 'type="submit"', $html );
	}

	/** @testdox The once-only page shows the price as not applicable, as a held one does. */
	public function test_page_price_not_applicable(): void {
		$this->set_mode( Product_Meta::REPURCHASE_NEVER );
		$this->hold();

		$this->assertMatchesRegularExpression( '#gatedmedia-price-block__amount">' . preg_quote( esc_html( Money::not_applicable() ), '#' ) . '<#', $this->page() );
	}

	/** @testdox The checkout refusal has its own text on the product page. */
	public function test_refusal_text(): void {
		$_GET[ Checkout_Action::ERROR_FLAG ] = 'gatedmedia_already_had';

		$this->assertSame( Labels::text( 'product.error.already_had' ), $this->offer()->product( self::DEFAULTS, $this->product_id )['error'] );
	}

	/** @testdox A disabled button block is a disabled button, never a link, even given an href. */
	public function test_button_block_disabled(): void {
		$html = Block::render(
			'gated-media-access/button',
			array(
				'label'    => 'Done',
				'href'     => 'https://example.org/somewhere/',
				'disabled' => true,
			)
		);

		$this->assertMatchesRegularExpression( '#<button[^>]*disabled#s', $html );
		$this->assertStringNotContainsString( '<a ', $html );
		$this->assertStringNotContainsString( 'example.org/somewhere', $html );
	}

	/** @testdox A button block that is not disabled draws no disabled attribute, and a link stays a link. */
	public function test_button_block_enabled(): void {
		$button = Block::render( 'gated-media-access/button', array( 'label' => 'Go' ) );
		$link   = Block::render(
			'gated-media-access/button',
			array(
				'label' => 'Go',
				'href'  => 'https://example.org/somewhere/',
			)
		);

		$this->assertDoesNotMatchRegularExpression( '#<button[^>]*disabled#s', $button );
		$this->assertStringContainsString( '<a ', $link );
	}

	/** @testdox The setting is registered in REST, defaults to once it runs out, and only product managers may write it. */
	public function test_meta_is_registered(): void {
		$registered = get_registered_meta_keys( 'post', Post_Types::PRODUCT );
		$meta       = new Product_Meta( new Settings(), new Access_Taxonomy() );

		$this->assertTrue( (bool) $registered[ Product_Meta::META_REPURCHASE ]['show_in_rest'] );
		$this->assertSame( Product_Meta::REPURCHASE_LAPSED, $registered[ Product_Meta::META_REPURCHASE ]['default'] );
		$this->assertTrue( $meta->protect_meta( false, Product_Meta::META_REPURCHASE, 'post' ) );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_REPURCHASE ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->product_id, Product_Meta::META_REPURCHASE ) );
	}

	/** @testdox The setting stores only its three values, anything else as the default. */
	public function test_meta_is_sanitised(): void {
		foreach ( array( Product_Meta::REPURCHASE_LAPSED, Product_Meta::REPURCHASE_ALWAYS, Product_Meta::REPURCHASE_NEVER ) as $mode ) {
			$this->assertSame( $mode, sanitize_meta( Product_Meta::META_REPURCHASE, $mode, 'post', Post_Types::PRODUCT ) );
		}

		$this->assertSame( Product_Meta::REPURCHASE_LAPSED, sanitize_meta( Product_Meta::META_REPURCHASE, 'sometimes', 'post', Post_Types::PRODUCT ) );
	}

	/**
	 * A published product.
	 *
	 * @param int                $price Minor units.
	 * @param array<int, string> $items type:id rows.
	 */
	private function product( int $price, array $items ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_title'  => 'Q3 bundle',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		update_post_meta( $product_id, Product_Meta::META_CURRENCY, 'GBP' );

		foreach ( $items as $item ) {
			add_post_meta( $product_id, Product_Meta::META_ITEMS, $item );
		}

		return $product_id;
	}

	/**
	 * Sets the product's buy-again setting.
	 *
	 * @param string $mode One of the REPURCHASE_* values.
	 */
	private function set_mode( string $mode ): void {
		update_post_meta( $this->product_id, Product_Meta::META_REPURCHASE, $mode );
	}

	/**
	 * Gives the buyer live access to the product's post.
	 */
	private function hold(): void {
		$this->writer->grant( $this->user_id, 'post', (string) $this->post_id, null, 'admin' );
	}

	/**
	 * Gives the buyer access to the product's post that has run out.
	 */
	private function lapse(): void {
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $this->post_id, 30, 'admin' );
		$this->writer->expire( $access_id );
	}

	/**
	 * The buyer's refusal for the product.
	 */
	private function refusal(): string {
		return $this->repurchase()->refusal( $this->product_id, $this->user_id );
	}

	/**
	 * The rule, as the plugin builds it.
	 */
	private function repurchase(): Repurchase {
		return new Repurchase( new Resolver( new Access_Taxonomy() ), new Access_Lookup() );
	}

	/**
	 * The page state the buyer sees.
	 */
	private function state(): string {
		return $this->offer()->product( self::DEFAULTS, $this->product_id )['state'];
	}

	/**
	 * The page data, as the plugin builds it.
	 */
	private function offer(): Product_Offer {
		$taxonomy = new Access_Taxonomy();

		return new Product_Offer(
			$this->checkout(),
			new Resolver( $taxonomy ),
			new Access_Lookup(),
			new Item_Label( $taxonomy ),
			new Settings()
		);
	}

	/**
	 * The buyer buying the product.
	 *
	 * @return array{redirect: string}|WP_Error
	 */
	private function buy(): array|WP_Error {
		return $this->checkout()->purchase( $this->product_id, $this->user_id );
	}

	/**
	 * Refused as already had.
	 *
	 * @param mixed $outcome What purchase() returned.
	 */
	private function assertRefused( $outcome ): void {
		$this->assertInstanceOf( WP_Error::class, $outcome );
		$this->assertSame( 'gatedmedia_already_had', $outcome->get_error_code() );
	}

	/**
	 * The product page as the buyer sees it.
	 */
	private function page(): string {
		$GLOBALS['post'] = get_post( $this->product_id );

		return Block::render( 'gated-media-access/product-details', array() );
	}

	/**
	 * Checkout over a gateway answering a canned session.
	 */
	private function checkout(): Checkout {
		$gateway = new class( new Settings() ) extends Stripe_Gateway {
			/**
			 * Answers a canned session.
			 *
			 * @param Payment $payment      Ignored.
			 * @param string  $product_name Ignored.
			 * @param string  $success_url  Ignored.
			 * @param string  $cancel_url   Ignored.
			 * @param string  $email        Ignored.
			 * @return array{id: string, url: string}
			 */
			public function create_checkout_session( Payment $payment, string $product_name, string $success_url, string $cancel_url, string $email ): array|WP_Error {
				return array(
					'id'  => 'cs_fake_1',
					'url' => 'https://stripe.example/session',
				);
			}
		};

		return new Checkout( $this->store, $this->writer, $gateway, new Resolver( new Access_Taxonomy() ), new Settings() );
	}
}
