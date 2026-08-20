<?php
/**
 * Rendering an amount of money.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * Turns minor units into something a person reads.
 *
 * Amounts are held in minor units throughout (specification.md §1a), so every
 * display of one has to divide and format. Doing that in each template is how
 * two screens end up disagreeing about whether zero is "Free" or "£0.00" — and
 * ui-spec.md §6.7 and §6.13 are both emphatic that it is "Free".
 *
 * **Currency formatting is not specified anywhere.** Neither the brief nor the
 * spec says how an amount is presented, so the default below is a reasonable
 * reading rather than a settled decision, and it is filtered so a site can
 * replace it without touching a template.
 */
class Money {

	/**
	 * Formats an amount for display.
	 *
	 * Zero is the word "Free" and never a zero amount — the one display rule
	 * both §6.7 and §6.13 state outright.
	 *
	 * ICU (the intl extension) supplies both facts a currency needs: its
	 * fraction digits — so minor units divide by 10^digits, never an assumed
	 * 100 — and its presentation in the site's locale. The locale's own
	 * currency gets its bare symbol, a foreign one its unambiguous form
	 * (CHF, PLN, JP¥): three kronas all write "kr", so this is ICU's
	 * disambiguation, not a fallback.
	 *
	 * @param int    $minor_units The amount, in minor units.
	 * @param string $currency    ISO code.
	 */
	public static function format( int $minor_units, string $currency = 'GBP' ): string {
		if ( 0 === $minor_units ) {
			return self::filter( __( 'Free', 'gated-media-access' ), $minor_units, $currency );
		}

		$currency  = strtoupper( $currency );
		$formatter = new \NumberFormatter( get_locale(), \NumberFormatter::CURRENCY );
		$formatter->setTextAttribute( \NumberFormatter::CURRENCY_CODE, $currency );

		$digits = $formatter->getAttribute( \NumberFormatter::FRACTION_DIGITS );
		$digits = false === $digits ? 2 : $digits;
		$amount = $minor_units / ( 10 ** $digits );

		$formatted = $formatter->formatCurrency( $amount, $currency );

		if ( false === $formatted ) {
			$formatted = $currency . ' ' . number_format_i18n( $amount, $digits );
		}

		return self::filter( $formatted, $minor_units, $currency );
	}

	/**
	 * §6.13 — where no price exists at all, an em dash. Used for access an
	 * administrator added, which has no order behind it.
	 */
	public static function not_applicable(): string {
		return '—';
	}

	/**
	 * Lets a site format money its own way.
	 *
	 * @param string $formatted   What we produced.
	 * @param int    $minor_units The amount, in minor units.
	 * @param string $currency    ISO code.
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
