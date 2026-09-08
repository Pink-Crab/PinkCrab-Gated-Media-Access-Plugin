<?php
/**
 * The access webhook.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Payments\Access_Grant_Route;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * They paid elsewhere: behind the give-access capability, a payload names a person, a target, a duration and the sender's reference.
 *
 * A repeated delivery writes nothing, a product target expands to one record per item, and a person unknown by email is created and their profile filled.
 *
 * @group integration
 */
class Test_Access_Grant_Route extends WP_UnitTestCase {

	private Access_Lookup $lookup;

	private int $post_item;

	public function set_up(): void {
		parent::set_up();

		// The framework's tear_down unregisters every meta key.
		$writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$writer->register_meta();
		( new Product_Meta( new \PinkCrab\Gated_Access\Settings\Settings(), new Access_Taxonomy() ) )->register_meta();

		$this->lookup    = new Access_Lookup();
		$this->post_item = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		add_action( 'rest_api_init', array( new Access_Grant_Route( $writer ), 'register_route' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/** @testdox Without the give-access capability the route refuses. */
	public function test_requires_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->deliver( $this->payload() );

		$this->assertSame( 403, $response->get_status() );
	}

	/** @testdox A sound delivery grants, and its repeat writes nothing new. */
	public function test_grants_once(): void {
		$first = $this->deliver( $this->payload() );

		$this->assertSame( 200, $first->get_status() );
		$this->assertCount( 1, $first->get_data()['granted'] );

		$again = $this->deliver( $this->payload() );

		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $first->get_data(), $again->get_data(), 'the retry must answer the same record, not a new one' );
		$this->assertCount( 1, $this->lookup->records_for_reference( 'crm', 'order-77' ) );
	}

	/** @testdox An unknown email becomes a user, profile fields filled like every other route. */
	public function test_creates_the_person(): void {
		$this->assertFalse( get_user_by( 'email', 'new@example.com' ) );

		$this->deliver(
			$this->payload(
				array(
					'email'   => 'new@example.com',
					'name'    => 'Terry Buyer',
					'company' => 'PinkCrab',
				)
			)
		);

		$user = get_user_by( 'email', 'new@example.com' );

		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'Terry', $user->first_name );
		$this->assertSame( 'Buyer', $user->last_name );
		$this->assertSame( 'PinkCrab', get_user_meta( $user->ID, 'gatedmedia_company', true ) );
	}

	/** @testdox A product target expands to one record per item, all one reference. */
	public function test_product_target_expands(): void {
		$second_post = self::factory()->post->create();
		$product_id  = self::factory()->post->create( array( 'post_type' => Post_Types::PRODUCT ) );
		add_post_meta( $product_id, Product_Meta::META_ITEMS, "post:{$this->post_item}" );
		add_post_meta( $product_id, Product_Meta::META_ITEMS, "post:{$second_post}" );

		$response = $this->deliver(
			$this->payload(
				array(
					'target' => array(
						'type' => 'product',
						'id'   => (string) $product_id,
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data()['granted'] );
		$this->assertCount( 2, $this->lookup->records_for_reference( 'crm', 'order-77' ) );
	}

	/** @testdox A lifetime duration stores no expiry; days store one. */
	public function test_duration(): void {
		$lifetime = $this->deliver( $this->payload( array( 'duration' => 'lifetime' ) ) );
		$granted  = $lifetime->get_data()['granted'];

		$this->assertSame( '', get_post_meta( $granted[0], Access_Writer::META_EXPIRES_AT, true ) );

		$timed = $this->deliver(
			$this->payload(
				array(
					'duration'  => 30,
					'reference' => 'order-78',
				)
			)
		);

		$this->assertNotSame( '', get_post_meta( $timed->get_data()['granted'][0], Access_Writer::META_EXPIRES_AT, true ) );
	}

	/** @testdox A payload missing its shape is refused, and the refusal is announced. */
	public function test_invalid_payload_announced(): void {
		$announced = array();
		add_action(
			'gatedmedia_webhook_received',
			static function ( array $payload, string $source, bool $accepted, string $reason ) use ( &$announced ): void {
				$announced[] = array( $source, $accepted, $reason );
			},
			10,
			4
		);

		$response = $this->deliver( $this->payload( array( 'email' => 'not-an-email' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 1, $announced );
		$this->assertSame( 'crm', $announced[0][0] );
		$this->assertFalse( $announced[0][1] );
		$this->assertStringContainsString( 'email', $announced[0][2] );
	}

	/**
	 * A sound payload, overridable per test.
	 *
	 * @param array<string, mixed> $over Fields to replace.
	 * @return array<string, mixed>
	 */
	private function payload( array $over = array() ): array {
		return array_merge(
			array(
				'email'     => 'holder@example.com',
				'target'    => array(
					'type' => 'post',
					'id'   => (string) $this->post_item,
				),
				'duration'  => 'lifetime',
				'reference' => 'order-77',
				'source'    => 'crm',
			),
			$over
		);
	}

	/**
	 * One JSON delivery to the route.
	 *
	 * @param array<string, mixed> $payload The body.
	 */
	private function deliver( array $payload ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/gated-media-access/v1/access' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		return rest_get_server()->dispatch( $request );
	}
}
