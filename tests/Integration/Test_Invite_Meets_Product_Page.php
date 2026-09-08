<?php
/**
 * Invites, seen by the product page.
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
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Invites;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * `Invites` grants a free product's items on the spot to an invited user with an account, and `Product_Offer` decides which of the six states that user then sees.
 *
 * The allow-list is the shared surface: `Invites` reads it to decide who to write to and `Checkout::eligible()` reads it to decide who may buy, so if either changes how addresses are stored one of these fails.
 *
 * @group integration
 */
class Test_Invite_Meets_Product_Page extends WP_UnitTestCase {

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

	private Invites $invites;

	private int $post_item;

	public function set_up(): void {
		parent::set_up();

		$taxonomy = new Access_Taxonomy();
		$lookup   = new Access_Lookup();
		$writer   = new Access_Writer( new Access_Validator( $taxonomy ), $lookup );

		$writer->register_meta();
		( new Product_Meta( new Settings(), $taxonomy ) )->register_meta();

		$checkout = new Checkout( new Payment_Store(), $writer, new Stripe_Gateway( new Settings() ), new Resolver( $taxonomy ) );

		$this->invites = new Invites( $checkout, new Notification_Sender( new Settings() ) );
		$this->invites->register_meta();

		$this->post_item = self::factory()->post->create( array( 'post_title' => 'The Content' ) );

		// Nothing here is about email; the sender is stubbed out at the boundary.
		add_filter( 'pre_wp_mail', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', '__return_true' );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * A published product granting one post.
	 *
	 * @param int                $price   Minor units, 0 for free.
	 * @param array<int, string> $allowed Addresses on the allow-list.
	 */
	private function product( int $price, array $allowed = array() ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => 'The Product',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		update_post_meta( $product_id, Product_Meta::META_SEND_INVITES, '1' );
		add_post_meta( $product_id, Product_Meta::META_ITEMS, 'post:' . $this->post_item );

		foreach ( $allowed as $address ) {
			add_post_meta( $product_id, Product_Meta::META_EMAILS, $address );
		}

		return $product_id;
	}

	/**
	 * What the product page would draw for the signed-in user, on a fresh request.
	 *
	 * **Rebuilt every call on purpose.** `Resolver::allowed_for()` memoises per instance, so reusing one would test the memo and report that an invite changed nothing.
	 *
	 * @param int $product_id The product.
	 */
	private function state( int $product_id ): string {
		$taxonomy = new Access_Taxonomy();

		$offer = new Product_Offer(
			new Checkout( new Payment_Store(), new Access_Writer( new Access_Validator( $taxonomy ), new Access_Lookup() ), new Stripe_Gateway( new Settings() ), new Resolver( $taxonomy ) ),
			new Resolver( $taxonomy ),
			new Access_Lookup(),
			new Item_Label( $taxonomy ),
			new Settings()
		);

		return $offer->product( self::DEFAULTS, $product_id )['state'];
	}

	/** @testdox An invited user on a free product is granted it, so the page says they hold it. */
	public function test_invited_user_holds_a_free_product(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$product = $this->product( 0, array( 'member@example.test' ) );

		wp_set_current_user( $user_id );
		$this->assertSame( Product_Offer::STATE_FREE, $this->state( $product ), 'before the invite it is theirs to claim' );

		$this->invites->process( $product );

		// Invites grants through the same `Checkout::grant_items()` a claim uses, so `Resolver` sees it and the page stops offering what they have.
		$this->assertSame( Product_Offer::STATE_HELD, $this->state( $product ) );
	}

	/** @testdox An invited user on a priced product is invited, not given it, and the page still sells. */
	public function test_invited_user_on_a_priced_product_still_buys(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$product = $this->product( 4900, array( 'member@example.test' ) );

		$this->invites->process( $product );

		wp_set_current_user( $user_id );

		// An invite to a priced product is a link, not a gift.
		$this->assertSame( Product_Offer::STATE_PAID, $this->state( $product ) );
	}

	/** @testdox Somebody off the allow-list is refused by the same list the invites are sent from. */
	public function test_the_allow_list_is_one_list(): void {
		$product  = $this->product( 4900, array( 'member@example.test' ) );
		$outsider = self::factory()->user->create( array( 'user_email' => 'nobody@example.test' ) );
		$insider  = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );

		wp_set_current_user( $outsider );
		$this->assertSame( Product_Offer::STATE_INELIGIBLE, $this->state( $product ) );

		wp_set_current_user( $insider );
		$this->assertSame( Product_Offer::STATE_PAID, $this->state( $product ) );
	}

	/** @testdox An invite grant is not an order, so it never appears as one. */
	public function test_an_invite_grant_is_not_an_order(): void {
		$user_id = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$product = $this->product( 0, array( 'member@example.test' ) );

		$this->invites->process( $product );

		// An order's "Access this created" reads stripe-sourced records only, and Invites writes its own source.
		$granted = ( new Access_Lookup() )->records_for_item( $user_id, 'post', (string) $this->post_item );

		$this->assertCount( 1, $granted );
		$this->assertSame(
			Invites::SOURCE_INVITE,
			get_post_meta( $granted[0], Access_Writer::META_SOURCE, true )
		);
		$this->assertSame( 0, ( new Payment_Store() )->total(), 'an invite writes no payment row' );
	}
}
