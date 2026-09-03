<?php
/**
 * Allow-list invites.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Payments\Checkout;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Stripe_Gateway;
use PinkCrab\Gated_Access\Products\Invites;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * A new allow-list address is invited by who they are and what the product
 * costs; an existing user on a free product holds access before the email
 * lands; removal forgets, re-adding re-sends; the switches gate everything.
 *
 * @group integration
 */
class Test_Invites extends WP_UnitTestCase {

	private Access_Lookup $lookup;

	private Invites $invites;

	private int $post_item;

	/**
	 * What `wp_mail()` was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $outbox = array();

	public function set_up(): void {
		parent::set_up();

		$writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$writer->register_meta();
		( new Product_Meta( new Settings(), new Access_Taxonomy() ) )->register_meta();

		$this->lookup  = new Access_Lookup();
		$this->invites = new Invites(
			new Checkout( new Payment_Store(), $writer, new Stripe_Gateway( new Settings() ), new Resolver( new Access_Taxonomy() ) ),
			new Notification_Sender( new Settings() )
		);
		$this->invites->register_meta();

		$this->post_item = self::factory()->post->create( array( 'post_title' => 'The Content' ) );

		$this->outbox = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ) );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * Short-circuits `wp_mail()`, keeping what it was given.
	 *
	 * @param bool|null            $short Null to send normally.
	 * @param array<string, mixed> $atts  The mail as compiled.
	 */
	public function capture_mail( ?bool $short, array $atts ): bool {
		$this->outbox[] = $atts;

		return true;
	}

	/**
	 * A published product granting one post, at a price.
	 *
	 * @param int $price Minor units, 0 for free.
	 */
	private function make_product( int $price ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PRODUCT,
				'post_status' => 'publish',
				'post_title'  => 'The Product',
			)
		);

		update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
		add_post_meta( $product_id, Product_Meta::META_ITEMS, 'post:' . $this->post_item );

		return $product_id;
	}

	/** @testdox An existing user joining a free product's list is granted access and told they have it. */
	public function test_user_on_free_product_granted_and_mailed(): void {
		$user_id    = self::factory()->user->create( array( 'user_email' => 'member@example.test' ) );
		$product_id = $this->make_product( 0 );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'member@example.test' );

		$this->invites->process( $product_id );

		$this->assertCount( 1, $this->outbox );
		$this->assertSame( array( 'member@example.test' ), $this->outbox[0]['to'] );
		$this->assertSame( 'You have been given access to The Product', $this->outbox[0]['subject'] );
		$this->assertNotNull( $this->lookup->find_by_reference( Invites::SOURCE_INVITE, "user:{$user_id}:product:{$product_id}" ) );
		$this->assertArrayHasKey( 'member@example.test', $this->invites->sent_map( $product_id ) );
	}

	/** @testdox An existing user joining a paid product's list is invited to buy — no access yet. */
	public function test_user_on_paid_product_mailed_only(): void {
		$user_id    = self::factory()->user->create( array( 'user_email' => 'buyer@example.test' ) );
		$product_id = $this->make_product( 500 );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'buyer@example.test' );

		$this->invites->process( $product_id );

		$this->assertCount( 1, $this->outbox );
		$this->assertSame( 'You are invited to The Product', $this->outbox[0]['subject'] );
		$this->assertStringContainsString( 'invited to purchase', (string) $this->outbox[0]['message'] );
		$this->assertNull( $this->lookup->find_by_reference( Invites::SOURCE_INVITE, "user:{$user_id}:product:{$product_id}" ) );
	}

	/** @testdox An address with no account is asked to create one — worded by the product's price. */
	public function test_guest_variants(): void {
		$free = $this->make_product( 0 );
		add_post_meta( $free, Product_Meta::META_EMAILS, 'newcomer@example.test' );
		$this->invites->process( $free );

		$paid = $this->make_product( 500 );
		add_post_meta( $paid, Product_Meta::META_EMAILS, 'stranger@example.test' );
		$this->invites->process( $paid );

		$this->assertCount( 2, $this->outbox );
		$this->assertSame( array( 'newcomer@example.test' ), $this->outbox[0]['to'] );
		$this->assertStringContainsString( 'Create an account to get access', (string) $this->outbox[0]['message'] );
		$this->assertStringContainsString( 'Create an account to purchase access', (string) $this->outbox[1]['message'] );
	}

	/** @testdox A second save with the same list sends nothing more. */
	public function test_no_resend_without_change(): void {
		$product_id = $this->make_product( 0 );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'once@example.test' );

		$this->invites->process( $product_id );
		$this->invites->process( $product_id );

		$this->assertCount( 1, $this->outbox );
	}

	/** @testdox Removing an address forgets its invite; re-adding sends a fresh one. */
	public function test_remove_and_readd_resends(): void {
		$product_id = $this->make_product( 0 );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'flighty@example.test' );

		$this->invites->process( $product_id );
		delete_post_meta( $product_id, Product_Meta::META_EMAILS, 'flighty@example.test' );
		$this->invites->process( $product_id );

		$this->assertSame( array(), $this->invites->sent_map( $product_id ) );

		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'flighty@example.test' );
		$this->invites->process( $product_id );

		$this->assertCount( 2, $this->outbox );
	}

	/** @testdox The per-product switch stops invites for that product alone. */
	public function test_product_switch_stops_sending(): void {
		$product_id = $this->make_product( 0 );
		update_post_meta( $product_id, Product_Meta::META_SEND_INVITES, '0' );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'quiet@example.test' );

		$this->invites->process( $product_id );

		$this->assertCount( 0, $this->outbox );
		$this->assertSame( array(), $this->invites->sent_map( $product_id ) );
	}

	/** @testdox A draft product invites nobody yet. */
	public function test_draft_product_sends_nothing(): void {
		$product_id = $this->make_product( 0 );
		wp_update_post(
			array(
				'ID'          => $product_id,
				'post_status' => 'draft',
			)
		);
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'early@example.test' );

		$this->invites->process( $product_id );

		$this->assertCount( 0, $this->outbox );
	}

	/**
	 * A block editor save, over the REST route the product form actually
	 * posts to — the post row is written first, the meta after it.
	 *
	 * @param int                $product_id The product being saved.
	 * @param array<int, string> $emails     The allow-list as the form sends it.
	 * @return int The response status.
	 */
	private function rest_save( int $product_id, array $emails ): int {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . Post_Types::PRODUCT . '/' . $product_id );
		$request->set_body_params( array( 'meta' => array( Product_Meta::META_EMAILS => $emails ) ) );

		return rest_get_server()->dispatch( $request )->get_status();
	}

	/** @testdox An address added in the block editor is invited by that same save, not the next one. */
	public function test_rest_save_invites_the_address_it_added(): void {
		$product_id = $this->make_product( 500 );

		$this->assertSame( 200, $this->rest_save( $product_id, array( 'late@example.test' ) ) );

		$this->assertSame( array( 'late@example.test' ), get_post_meta( $product_id, Product_Meta::META_EMAILS, false ) );
		$this->assertCount( 1, $this->outbox );
		$this->assertSame( array( 'late@example.test' ), $this->outbox[0]['to'] );
		$this->assertArrayHasKey( 'late@example.test', $this->invites->sent_map( $product_id ) );
	}

	/** @testdox An address removed in the block editor is forgotten by that same save. */
	public function test_rest_save_forgets_the_address_it_removed(): void {
		$product_id = $this->make_product( 500 );
		add_post_meta( $product_id, Product_Meta::META_EMAILS, 'leaver@example.test' );
		$this->invites->process( $product_id );

		$this->assertSame( 200, $this->rest_save( $product_id, array() ) );

		$this->assertSame( array(), $this->invites->sent_map( $product_id ) );
	}

	/** @testdox Saving anything that is not a product is left alone. */
	public function test_other_post_types_are_left_alone(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		add_post_meta( $post_id, Product_Meta::META_EMAILS, 'passerby@example.test' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Renamed',
			)
		);

		$this->assertCount( 0, $this->outbox );
		$this->assertSame( '', get_post_meta( $post_id, Invites::META_INVITES, true ) );
	}
}
