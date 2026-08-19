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
	 * Symbols for the currencies most likely to come up. Anything else falls
	 * back to its ISO code, which is correct if plain.
	 *
	 * @var array<string, string>
	 */
	private const SYMBOLS = array(
		'GBP' => '£',
		'EUR' => '€',
		'USD' => '$',
		'AUD' => '$',
		'CAD' => '$',
		'NZD' => '$',
		'JPY' => '¥',
	);

	/**
	 * Currencies with no minor unit — the amount is already whole.
	 *
	 * @var array<int, string>
	 */
	private const ZERO_DECIMAL = array( 'JPY', 'KRW', 'VND', 'CLP', 'ISK' );

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
		$symbol   = self::SYMBOLS[ $currency ] ?? $currency . ' ';

		$formatted = in_array( $currency, self::ZERO_DECIMAL, true )
			? number_format_i18n( (float) $minor_units, 0 )
			: number_format_i18n( $minor_units / 100, 2 );

		return self::filter( $symbol . $formatted, $minor_units, $currency );
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
