<?php
/**
 * How an expiry reads.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Support\Expiry;

/**
 * Every account view says when access runs out, and says it from here. Two of
 * the three states were covered only incidentally, through whichever caller
 * happened to produce them — and `soon`, the one that prints a counted-down
 * number of days to a customer, was not covered at all.
 *
 * @group integration
 */
class Test_Expiry extends WP_UnitTestCase {

	/** @testdox No expiry is lifetime, not a date. */
	public function test_lifetime(): void {
		$expiry = Expiry::describe( null );

		$this->assertSame( 'lifetime', $expiry['state'] );
		$this->assertSame( 'Lifetime', $expiry['label'] );
	}

	/** @testdox A date beyond the threshold is dated, and names the day. */
	public function test_dated(): void {
		$when   = time() + ( 30 * DAY_IN_SECONDS );
		$expiry = Expiry::describe( $when );

		$this->assertSame( 'dated', $expiry['state'] );
		$this->assertStringContainsString(
			wp_date( (string) get_option( 'date_format' ), $when ),
			$expiry['label']
		);
	}

	/** @testdox A date inside the threshold counts down in days instead. */
	public function test_soon(): void {
		$expiry = Expiry::describe( time() + ( 3 * DAY_IN_SECONDS ) );

		$this->assertSame( 'soon', $expiry['state'] );
		$this->assertSame( 'Expires in 3 days', $expiry['label'] );
	}

	/** @testdox One day is singular; the wording is counted, not glued to an "s". */
	public function test_soon_is_pluralised(): void {
		// A shade over a day, so ceil() lands on 1 rather than 0.
		$expiry = Expiry::describe( time() + DAY_IN_SECONDS - HOUR_IN_SECONDS );

		$this->assertSame( 'soon', $expiry['state'] );
		$this->assertSame( 'Expires in 1 day', $expiry['label'] );
	}

	/** @testdox Already past reads as soon rather than as a negative count. */
	public function test_already_past_never_counts_backwards(): void {
		$expiry = Expiry::describe( time() - ( 5 * DAY_IN_SECONDS ) );

		$this->assertSame( 'soon', $expiry['state'] );

		// max(1, …) exists so an overdue record never says "in -5 days".
		$this->assertSame( 'Expires in 1 day', $expiry['label'] );
	}

	/** @testdox The threshold is the documented seven days, at its boundary. */
	public function test_the_threshold_is_seven_days(): void {
		$this->assertSame( 'soon', Expiry::describe( time() + ( 7 * DAY_IN_SECONDS ) - MINUTE_IN_SECONDS )['state'] );
		$this->assertSame( 'dated', Expiry::describe( time() + ( 8 * DAY_IN_SECONDS ) )['state'] );
	}

	/** @testdox A site can move the threshold. */
	public function test_the_threshold_is_filterable(): void {
		add_filter( 'gatedmedia_expiry_soon_days', static fn (): int => 60 );

		// Thirty days is comfortably "dated" by default; under a sixty-day
		// threshold it is soon.
		$this->assertSame( 'soon', Expiry::describe( time() + ( 30 * DAY_IN_SECONDS ) )['state'] );
	}
}
