<?php
/**
 * Money on a host with no intl extension.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Support\Money;

/**
 * A product page used to fatal outright on any host without the intl
 * extension — most shared hosting, and WordPress Playground:
 *
 *     Uncaught Error: Class "Locale" not found
 *       in vendor/symfony/intl/ResourceBundle.php:58
 *       Currencies::exists('GBP') ← Money::digits() ← Money::format()
 *
 * `symfony/intl` was believed to be a polyfill for the extension. It is not:
 * it carries ICU's *data*, and every reader reaches `\Locale` on the way to
 * it. `symfony/polyfill-intl-icu` supplies that class, which is what makes the
 * data readable at all.
 *
 * These run on a host that *does* have the extension, so they cannot prove the
 * no-extension path end to end. What they can prove is the part that was
 * actually wrong: that the class is reachable either way, and that the
 * fraction digits — which drive the minor-unit division, so getting them wrong
 * misprices — come out right for the currencies where 2 is the wrong answer.
 *
 * @group integration
 */
class Test_Money_Without_Intl extends WP_UnitTestCase {

	/**
	 * @testdox The Locale class the ICU data readers need is present, extension or not.
	 *
	 * The single assertion that the fix turns on. Without it every
	 * `Currencies::` call in the plugin throws rather than answering.
	 */
	public function test_locale_is_always_available(): void {
		$this->assertTrue(
			class_exists( \Locale::class ),
			'symfony/intl cannot read its own data without \Locale — add symfony/polyfill-intl-icu.'
		);
	}

	/**
	 * @testdox The polyfill ships that class, so a host without the extension has one too.
	 */
	public function test_the_polyfill_is_installed(): void {
		$this->assertFileExists(
			GATEDMEDIA_DIR_PATH . 'vendor/symfony/polyfill-intl-icu/Resources/stubs/Locale.php',
			'The stub is what stands in for the extension where it is missing.'
		);
	}

	/**
	 * @testdox Currency data reads without the extension being asked for.
	 */
	public function test_currency_data_reads(): void {
		$this->assertTrue( \Symfony\Component\Intl\Currencies::exists( 'GBP' ) );
		$this->assertFalse( \Symfony\Component\Intl\Currencies::exists( 'NOPE' ) );
	}

	/**
	 * @testdox Zero-decimal and three-decimal currencies keep their own division.
	 *
	 * The reason a fallback of "always 2" would not have done: JPY has no
	 * minor unit and KWD has three, so 1000 minor units is ¥1,000 and KD 1.000
	 * — not 10.00 of either.
	 *
	 * @dataProvider currencies
	 *
	 * @param string $currency The ISO code.
	 * @param string $expected What 1000 minor units is worth, as a decimal.
	 */
	public function test_fraction_digits_are_per_currency( string $currency, string $expected ): void {
		$this->assertSame( $expected, Money::to_decimal( 1000, $currency ) );
	}

	/**
	 * Currencies whose minor units are not two decimal places.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function currencies(): array {
		return array(
			'GBP — two decimals'  => array( 'GBP', '10.00' ),
			'JPY — none'          => array( 'JPY', '1000' ),
			'KWD — three'         => array( 'KWD', '1.000' ),
		);
	}

	/** @testdox A typed amount goes to minor units by the same measure. */
	public function test_to_minor_matches(): void {
		$this->assertSame( 1000, Money::to_minor( '10.00', 'GBP' ) );
		$this->assertSame( 1000, Money::to_minor( '1000', 'JPY' ) );
		$this->assertSame( 1000, Money::to_minor( '1.000', 'KWD' ) );
	}

	/** @testdox Formatting still renders an amount, and zero is still the word Free. */
	public function test_format_renders(): void {
		$this->assertSame( 'Free', Money::format( 0, 'GBP' ) );
		$this->assertStringContainsString( '10', Money::format( 1000, 'GBP' ) );
	}

	/**
	 * @testdox The settings dropdown gets the whole ISO list, not a short fallback.
	 */
	public function test_the_currency_list_is_whole(): void {
		$this->assertGreaterThan( 100, count( \Symfony\Component\Intl\Currencies::getNames() ) );
	}
}
