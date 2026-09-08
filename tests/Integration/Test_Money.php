<?php
/**
 * Money display and conversion.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Support\Money;

/**
 * The currency data comes from ICU, either the intl extension or symfony/intl's bundled copy, so the digits are right for every ISO currency and zero is always the word "Free".
 *
 * Display assertions stay locale-safe: exact strings are only asserted where every locale agrees.
 *
 * @group integration
 */
class Test_Money extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_format_price' );

		parent::tear_down();
	}

	/** @testdox Zero is the word Free, never a zero amount. */
	public function test_zero_is_free(): void {
		$this->assertSame( 'Free', Money::format( 0, 'GBP' ) );
		$this->assertSame( 'Free', Money::format( 0, 'JPY' ) );
	}

	/** @testdox A two-decimal currency divides by 100, a zero-decimal one not at all. */
	public function test_digits_drive_the_division(): void {
		$this->assertStringContainsString( '12.50', Money::format( 1250, 'GBP' ) );
		$this->assertStringContainsString( '£', Money::format( 1250, 'GBP' ) );
		$this->assertStringContainsString( '1,250', Money::format( 1250, 'JPY' ) );
		$this->assertStringNotContainsString( '.', Money::format( 1250, 'JPY' ) );
	}

	/** @testdox An unknown code degrades to code-prefix, not an error. */
	public function test_unknown_code_degrades(): void {
		$this->assertStringContainsString( 'XYZ', Money::format( 1250, 'XYZ' ) );
		$this->assertStringContainsString( '12.50', Money::format( 1250, 'XYZ' ) );
	}

	/** @testdox Typed decimals store as minor units, per the currency's digits. */
	public function test_to_minor(): void {
		$this->assertSame( 1250, Money::to_minor( '12.50', 'GBP' ) );
		$this->assertSame( 1250, Money::to_minor( '1250', 'JPY' ) );
		$this->assertSame( 12500, Money::to_minor( '12.500', 'BHD' ) );
		$this->assertSame( 0, Money::to_minor( '', 'GBP' ) );
	}

	/** @testdox Stored minor units round-trip back to the input's decimal. */
	public function test_to_decimal(): void {
		$this->assertSame( '12.50', Money::to_decimal( 1250, 'GBP' ) );
		$this->assertSame( '1250', Money::to_decimal( 1250, 'JPY' ) );
		$this->assertSame( '12.500', Money::to_decimal( 12500, 'BHD' ) );
	}

	/** @testdox One formatter is built per locale and currency, not one per call. */
	public function test_the_formatter_is_reused(): void {
		$this->assertSame( Money::formatter( 'GBP' ), Money::formatter( 'GBP' ) );
		$this->assertNotSame( Money::formatter( 'GBP' ), Money::formatter( 'JPY' ) );
	}

	/** @testdox The gatedmedia_format_price filter has the last word. */
	public function test_display_is_filterable(): void {
		add_filter( 'gatedmedia_format_price', static fn (): string => 'a tenner' );

		$this->assertSame( 'a tenner', Money::format( 1000, 'GBP' ) );
	}
}
