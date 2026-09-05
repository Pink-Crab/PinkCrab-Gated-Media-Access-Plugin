<?php
/**
 * Rendering an amount of money.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

use Symfony\Component\Intl\Currencies;

/**
 * Turns minor units into something a person reads, and typed amounts back
 * into minor units for storage.
 *
 * Amounts are held in minor units throughout (specification.md §1a), so every
 * display of one has to divide and format. Doing that in each template is how
 * two screens end up disagreeing about whether zero is "Free" or "£0.00" — and
 * ui-spec.md §6.7 and §6.13 are both emphatic that it is "Free".
 *
 * No hand-kept currency tables. ICU answers every currency question — the
 * fraction digits that drive the minor-unit division, the symbol, the
 * placement — for all of ISO 4217, on every host.
 *
 * **Two different things are needed, and they are not interchangeable.**
 * `symfony/intl` carries ICU's *data*; the intl *extension* provides
 * `\NumberFormatter` for locale-aware layout and `\Locale`, which every
 * `symfony/intl` reader reaches for on its way to the data.
 *
 * `symfony/polyfill-intl-icu` supplies `\Locale` where the extension is
 * absent, which is what makes the data readable at all — on shared hosting
 * without ext-intl, and in WordPress Playground. Its stubs are classmapped,
 * so a host that *has* the extension finds the real classes first and the
 * polyfill is never loaded.
 *
 * The extension is therefore detected with `extension_loaded()`, never with
 * `class_exists( \NumberFormatter::class )` — the polyfill defines that class
 * too, but its constructor accepts only the locale `en` and its
 * `setTextAttribute()` throws unconditionally. Asking whether the class
 * exists gets a yes and then a fatal.
 */
class Money {

	/**
	 * One formatter per locale and currency, for this request.
	 *
	 * @var array<string, \NumberFormatter|null>
	 */
	private static array $formatters = array();

	/**
	 * The ICU formatter for a currency, or null where the extension is absent.
	 *
	 * Built once per locale and currency: constructing one loads ICU locale
	 * data, and `format()` used to build two of them for every price on a page.
	 *
	 * @param string $currency ISO code.
	 */
	public static function formatter( string $currency ): ?\NumberFormatter {
		$currency = strtoupper( $currency );
		$locale   = get_locale();
		$key      = $locale . '|' . $currency;

		if ( array_key_exists( $key, self::$formatters ) ) {
			return self::$formatters[ $key ];
		}

		// The extension, not the class: `symfony/polyfill-intl-icu` defines
		// \NumberFormatter too, but its constructor takes only the locale `en`
		// and `setTextAttribute()` throws unconditionally.
		if ( ! extension_loaded( 'intl' ) ) {
			self::$formatters[ $key ] = null;

			return null;
		}

		$formatter = new \NumberFormatter( $locale, \NumberFormatter::CURRENCY );
		$formatter->setTextAttribute( \NumberFormatter::CURRENCY_CODE, $currency );

		self::$formatters[ $key ] = $formatter;

		return $formatter;
	}

	/**
	 * Formats an amount for display.
	 *
	 * Zero is the word "Free" and never a zero amount — the one display rule
	 * both §6.7 and §6.13 state outright.
	 *
	 * @param int    $minor_units The amount, in minor units.
	 * @param string $currency    ISO code.
	 */
	public static function format( int $minor_units, string $currency = 'GBP' ): string {
		if ( 0 === $minor_units ) {
			return self::filter( __( 'Free', 'gated-media-access' ), $minor_units, $currency );
		}

		$currency = strtoupper( $currency );
		$digits   = self::digits( $currency );
		$amount   = $minor_units / ( 10 ** $digits );

		return self::filter( self::render( $amount, $currency, $digits ), $minor_units, $currency );
	}

	/**
	 * §6.13 — where no price exists at all, an em dash. Used for access an
	 * administrator added, which has no order behind it.
	 */
	public static function not_applicable(): string {
		return '—';
	}

	/**
	 * A typed decimal amount to stored minor units — "12.50" GBP is 1250,
	 * "1250" JPY is 1250.
	 *
	 * @param string $amount   A decimal amount, dot-separated, as an admin input submits it.
	 * @param string $currency ISO code.
	 */
	public static function to_minor( string $amount, string $currency ): int {
		return (int) round( (float) $amount * ( 10 ** self::digits( strtoupper( $currency ) ) ) );
	}

	/**
	 * Stored minor units back to the plain decimal an input can hold —
	 * 1250 GBP is "12.50", 1250 JPY is "1250".
	 *
	 * @param int    $minor_units The amount, in minor units.
	 * @param string $currency    ISO code.
	 */
	public static function to_decimal( int $minor_units, string $currency ): string {
		$digits = self::digits( strtoupper( $currency ) );

		return number_format( $minor_units / ( 10 ** $digits ), $digits, '.', '' );
	}

	/**
	 * The amount as the site's locale writes it: the extension where loaded,
	 * symbol-prefix from the bundled data where not, code-prefix where the
	 * code is unknown to either.
	 *
	 * @param float  $amount   The amount, in major units.
	 * @param string $currency ISO code, uppercased.
	 * @param int    $digits   Its fraction digits.
	 */
	private static function render( float $amount, string $currency, int $digits ): string {
		$formatter = self::formatter( $currency );

		if ( null !== $formatter ) {
			$formatted = $formatter->formatCurrency( $amount, $currency );

			if ( false !== $formatted ) {
				return $formatted;
			}
		}

		$symbol = Currencies::exists( $currency ) ? Currencies::getSymbol( $currency ) : $currency . ' ';

		return $symbol . number_format_i18n( $amount, $digits );
	}

	/**
	 * How many decimal places a currency has — ICU's answer through either
	 * door, 2 for a code neither knows (most of ISO 4217).
	 *
	 * @param string $currency ISO code, already uppercased.
	 */
	private static function digits( string $currency ): int {
		$formatter = self::formatter( $currency );

		if ( null !== $formatter ) {
			$digits = $formatter->getAttribute( \NumberFormatter::FRACTION_DIGITS );

			if ( false !== $digits ) {
				return $digits;
			}
		}

		return Currencies::exists( $currency ) ? Currencies::getFractionDigits( $currency ) : 2;
	}

	/**
	 * Lets a site format money its own way.
	 *
	 * @param string $formatted   What we produced.
	 * @param int    $minor_units The amount, in minor units.
	 * @param string $currency    ISO currency code.
	 */
	private static function filter( string $formatted, int $minor_units, string $currency ): string {
		/**
		 * Filters a displayed amount.
		 *
		 * @param string $formatted   The formatted amount.
		 * @param int    $minor_units The amount, in minor units.
		 * @param string $currency    ISO currency code.
		 */
		$filtered = apply_filters( 'gatedmedia_format_price', $formatted, $minor_units, $currency );

		return is_string( $filtered ) ? $filtered : $formatted;
	}
}
