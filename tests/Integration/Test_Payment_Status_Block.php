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
 * §7.8, drawn on the order rather than a page of its own.
 *
 * **Only `complete` had ever been rendered by anything.** The e2e fixture's
 * order is complete, so the browser suite walks that state and no other — yet
 * `pending` is the state the panel exists for, because Stripe returns the buyer
 * before its webhook has necessarily landed, and `failed` is the one that has
 * to explain a charge that did not happen. Both were written from reading the
 * code and never once drawn.
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
	 * `status` is declared with an enum and a `pending` default, so WordPress
	 * coerces anything outside the enum to that default before render.php is
	 * reached — the early return there is unreachable through the block API.
	 *
	 * Pinned because the fallback is the safe one and should stay that way: an
	 * unrecognised status reads as "still confirming", which claims nothing and
	 * grants nothing. Defaulting to `complete` would tell a buyer they were in
	 * on the strength of a value nobody recognised.
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
}
