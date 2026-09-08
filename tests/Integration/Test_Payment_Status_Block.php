<?php
/**
 * The payment status panel, in every state.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use WP_Block_Type_Registry;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Support\Block;

/**
 * The payment status panel, drawn on the order rather than a page of its own.
 *
 * `pending` is the state it exists for, because Stripe returns the buyer before its webhook has necessarily landed, and `failed` has to explain a charge that did not happen.
 *
 * @group integration
 */
class Test_Payment_Status_Block extends WP_UnitTestCase {

	private const BLOCK = 'gated-media-access/payment-status';

	/** @testdox The block is registered and renders on the server. */
	public function test_registered(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertNotNull( $type );
		$this->assertTrue( $type->is_dynamic() );
	}

	/** @testdox Waiting on Stripe says so, and shows the spinner rather than a result. */
	public function test_pending(): void {
		$html = Block::render( self::BLOCK, array( 'status' => Payment::STATUS_PENDING ) );

		$this->assertStringContainsString( 'gatedmedia-payment-status--pending', $html );
		$this->assertStringContainsString( 'gatedmedia-spinner', $html );
		$this->assertStringContainsString( 'Confirming your payment', $html );

		// It must not congratulate anybody yet.
		$this->assertStringNotContainsString( "You're in", $html );
	}

	/** @testdox A failed payment says nothing was charged. */
	public function test_failed(): void {
		$html = Block::render( self::BLOCK, array( 'status' => Payment::STATUS_FAILED ) );

		$this->assertStringContainsString( 'gatedmedia-payment-status--failed', $html );
		$this->assertStringContainsString( 'Payment not completed', $html );
		$this->assertStringContainsString( 'you have not been charged', $html );
		$this->assertStringNotContainsString( 'gatedmedia-spinner', $html );
	}

	/** @testdox A completed payment says so and offers the way on. */
	public function test_complete(): void {
		$html = Block::render(
			self::BLOCK,
			array(
				'status'      => Payment::STATUS_COMPLETE,
				'actionLabel' => 'Go to my access',
				'actionHref'  => 'https://example.test/account/my-access/',
			)
		);

		$this->assertStringContainsString( 'gatedmedia-payment-status--complete', $html );
		$this->assertStringContainsString( "You&#039;re in", $html );
		$this->assertStringContainsString( 'Go to my access', $html );
		$this->assertStringNotContainsString( 'gatedmedia-spinner', $html );
	}

	/** @testdox A refunded order says the access has gone, rather than falling through to nothing. */
	public function test_refunded(): void {
		$html = Block::render( self::BLOCK, array( 'status' => Payment::STATUS_REFUNDED ) );

		$this->assertStringContainsString( 'gatedmedia-payment-status--refunded', $html );
		$this->assertStringContainsString( 'refunded', $html );
	}

	/**
	 * `status` is declared with an enum and a `pending` default, so WordPress coerces anything outside the enum before render.php is reached and the early return there is unreachable through the block API.
	 *
	 * Pinned because the fallback should stay the safe one: "still confirming" claims nothing, where defaulting to `complete` would tell a buyer they were in on a value nobody recognised.
	 *
	 * @testdox A status the block does not know falls back to confirming, never to success.
	 */
	public function test_unknown_status_falls_back_to_pending(): void {
		$html = Block::render( self::BLOCK, array( 'status' => 'invented' ) );

		$this->assertStringContainsString( 'gatedmedia-payment-status--pending', $html );
		$this->assertStringNotContainsString( "You&#039;re in", $html );
	}

	/** @testdox The reference is shown when there is one, and the line is absent when there is not. */
	public function test_reference(): void {
		$with = Block::render(
			self::BLOCK,
			array(
				'status'    => Payment::STATUS_PENDING,
				'reference' => 'a2df0729-fdfa-4143-bf09-42b0a423e633',
			)
		);

		$this->assertStringContainsString( 'a2df0729-fdfa-4143-bf09-42b0a423e633', $with );
		$this->assertStringContainsString( 'Reference:', $with );

		$without = Block::render( self::BLOCK, array( 'status' => Payment::STATUS_PENDING ) );

		$this->assertStringNotContainsString( 'Reference:', $without );
	}

	/** @testdox Wording can be overtyped, and the state's own words are the default. */
	public function test_wording_override(): void {
		$html = Block::render(
			self::BLOCK,
			array(
				'status'  => Payment::STATUS_PENDING,
				'heading' => 'Hold on',
				'message' => 'This will not be long.',
			)
		);

		$this->assertStringContainsString( 'Hold on', $html );
		$this->assertStringContainsString( 'This will not be long.', $html );
		$this->assertStringNotContainsString( 'Confirming your payment', $html );
	}

	/** @testdox Only a completed order offers an action; a failed one has nowhere to send anybody. */
	public function test_no_action_without_both_label_and_href(): void {
		$html = Block::render(
			self::BLOCK,
			array(
				'status'      => Payment::STATUS_FAILED,
				'actionLabel' => 'Go to my access',
				// No href: an action with nowhere to go is not an action.
			)
		);

		$this->assertStringNotContainsString( 'gatedmedia-payment-status__action', $html );
	}

	// The poll. PHP decides whether it happens, so every "must not poll" case is here.

	private const UUID = 'a2df0729-fdfa-4143-bf09-42b0a423e633';

	/** @testdox A pending payment carries everything the poll needs, and nothing it does not. */
	public function test_pending_carries_the_poll(): void {
		wp_set_current_user( self::factory()->user->create() );

		$html = Block::render(
			self::BLOCK,
			array(
				'status' => Payment::STATUS_PENDING,
				'uuid'   => self::UUID,
			)
		);

		$this->assertStringContainsString( 'data-gatedmedia-poll="' . self::UUID . '"', $html );
		$this->assertStringContainsString( 'data-gatedmedia-nonce="', $html );
		$this->assertStringContainsString( '/gated-media-access/v1/payment/' . self::UUID, $html );
		$this->assertStringContainsString( 'data-gatedmedia-interval="3000"', $html );
		$this->assertStringContainsString( 'data-gatedmedia-attempts="20"', $html );

		// The wording it stands down with travels with it, so the script invents no sentence and translation stays in PHP.
		$this->assertStringContainsString( 'data-gatedmedia-waiting="', $html );
		$this->assertStringContainsString( 'Your payment is safe', $html );

		// And the paragraph it swaps is findable.
		$this->assertStringContainsString( 'data-gatedmedia-message', $html );
	}

	/** @testdox A settled payment never polls, whatever it settled as. */
	public function test_settled_never_polls(): void {
		wp_set_current_user( self::factory()->user->create() );

		foreach ( array( Payment::STATUS_COMPLETE, Payment::STATUS_FAILED, Payment::STATUS_REFUNDED ) as $status ) {
			$html = Block::render(
				self::BLOCK,
				array(
					'status' => $status,
					'uuid'   => self::UUID,
				)
			);

			$this->assertStringNotContainsString( 'data-gatedmedia-poll', $html, $status . ' polled' );
		}
	}

	/**
	 * `Payment_Status_Route`'s permission callback is `is_user_logged_in`, so a signed-out poll could only be a 401, and printing a `wp_rest` nonce would hand out the one every signed-out user shares.
	 *
	 * @testdox Signed out, nothing polls and no nonce is printed.
	 */
	public function test_signed_out_never_polls(): void {
		wp_set_current_user( 0 );

		$html = Block::render(
			self::BLOCK,
			array(
				'status' => Payment::STATUS_PENDING,
				'uuid'   => self::UUID,
			)
		);

		$this->assertStringNotContainsString( 'data-gatedmedia-poll', $html );
		$this->assertStringNotContainsString( 'data-gatedmedia-nonce', $html );
	}

	/** @testdox Without a uuid there is nothing to ask about, so it does not ask. */
	public function test_no_uuid_no_poll(): void {
		wp_set_current_user( self::factory()->user->create() );

		$html = Block::render( self::BLOCK, array( 'status' => Payment::STATUS_PENDING ) );

		$this->assertStringNotContainsString( 'data-gatedmedia-poll', $html );
	}

	/** @testdox The timing is filterable, and a nonsense filter cannot make it hammer the route. */
	public function test_timing_is_filterable(): void {
		wp_set_current_user( self::factory()->user->create() );

		$filter = static fn (): array => array(
			'interval' => 250,
			'attempts' => 0,
		);

		add_filter( 'gatedmedia_payment_poll', $filter );

		$html = Block::render(
			self::BLOCK,
			array(
				'status' => Payment::STATUS_PENDING,
				'uuid'   => self::UUID,
			)
		);

		remove_filter( 'gatedmedia_payment_poll', $filter );

		// Clamped: a quarter-second interval and zero attempts are floored at one second and one try.
		$this->assertStringContainsString( 'data-gatedmedia-interval="1000"', $html );
		$this->assertStringContainsString( 'data-gatedmedia-attempts="1"', $html );
	}

	/** @testdox The filter is given the uuid it is being asked about. */
	public function test_filter_receives_the_uuid(): void {
		wp_set_current_user( self::factory()->user->create() );

		$seen = '';

		$filter = static function ( array $poll, string $uuid ) use ( &$seen ): array {
			$seen = $uuid;

			return $poll;
		};

		add_filter( 'gatedmedia_payment_poll', $filter, 10, 2 );

		Block::render(
			self::BLOCK,
			array(
				'status' => Payment::STATUS_PENDING,
				'uuid'   => self::UUID,
			)
		);

		remove_filter( 'gatedmedia_payment_poll', $filter, 10 );

		$this->assertSame( self::UUID, $seen );
	}
}
