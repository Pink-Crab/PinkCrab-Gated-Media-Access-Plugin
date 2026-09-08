<?php
/**
 * The payment status route.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Payments\Payment_Status_Route;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;

/**
 * Owner only, status only, and everything that is not the owner's payment answers the same 404, confirming nothing.
 *
 * @group integration
 */
class Test_Payment_Status_Route extends WP_UnitTestCase {

	private Payment_Store $store;

	private int $owner_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->store    = new Payment_Store();
		$this->owner_id = self::factory()->user->create();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		// Core requires routes to register on rest_api_init.
		add_action( 'rest_api_init', array( new Payment_Status_Route( $this->store ), 'register_route' ) );
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/** @testdox The owner reads the status and nothing else. */
	public function test_owner_reads_status(): void {
		$payment = $this->store->create_pending( $this->owner_id, 1, 500, 'GBP', array() );
		wp_set_current_user( $this->owner_id );

		$response = $this->request( $payment->uuid );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'status' => 'pending' ), $response->get_data() );
	}

	/** @testdox Someone else's payment and a payment that does not exist answer the same 404. */
	public function test_not_yours_is_not_found(): void {
		$payment = $this->store->create_pending( $this->owner_id, 1, 500, 'GBP', array() );
		wp_set_current_user( self::factory()->user->create() );

		$this->assertSame( 404, $this->request( $payment->uuid )->get_status() );
		$this->assertSame( 404, $this->request( '00000000-0000-4000-8000-000000000000' )->get_status() );
	}

	/** @testdox Signed out, the route refuses before looking anything up. */
	public function test_signed_out_is_refused(): void {
		$payment = $this->store->create_pending( $this->owner_id, 1, 500, 'GBP', array() );
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( $payment->uuid )->get_status() );
	}

	/**
	 * One GET against the route.
	 *
	 * @param string $uuid The payment asked about.
	 */
	private function request( string $uuid ): \WP_REST_Response {
		return rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/' . Payment_Status_Route::ROUTE_NAMESPACE . '/payment/' . $uuid )
		);
	}
}
