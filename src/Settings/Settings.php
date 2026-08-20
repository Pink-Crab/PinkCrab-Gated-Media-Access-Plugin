<?php
/**
 * Reading the plugin's settings.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

/**
 * The one settings option, `gatedmedia_settings`, an array (specification.md
 * §8). This class only reads it — the screen that writes it is the round 5
 * Settings build, so today every key answers with its default.
 */
class Settings {

	/** The option holding every setting. */
	public const OPTION = 'gatedmedia_settings';

	/** Stripe's sandbox. The default: never live by accident. */
	public const MODE_TEST = 'test';

	/** Real money. */
	public const MODE_LIVE = 'live';

	/** Revoke marks the record revoked — the default, history kept. */
	public const REVOKE_BEHAVIOUR_REVOKE = 'revoke';

	/** Expire pulls the record's date to now. */
	public const REVOKE_BEHAVIOUR_EXPIRE = 'expire';

	/** Delete removes the record outright. */
	public const REVOKE_BEHAVIOUR_DELETE = 'delete';

	/**
	 * What revoking a record does on this site: revoke, expire or delete.
	 *
	 * Defaults to revoke — records are kept as history (requirements.md) —
	 * and anything unrecognised lands back there.
	 */
	public function revoke_behaviour(): string {
		$settings  = get_option( self::OPTION );
		$behaviour = is_array( $settings ) && isset( $settings['revoke_behaviour'] ) ? (string) $settings['revoke_behaviour'] : self::REVOKE_BEHAVIOUR_REVOKE;

		/**
		 * Filters the revoke behaviour, over the stored setting.
		 *
		 * @param string $behaviour One of revoke, expire, delete.
		 */
		$behaviour = (string) apply_filters( 'gatedmedia_revoke_behaviour', $behaviour );

		$known = array( self::REVOKE_BEHAVIOUR_REVOKE, self::REVOKE_BEHAVIOUR_EXPIRE, self::REVOKE_BEHAVIOUR_DELETE );

		return in_array( $behaviour, $known, true ) ? $behaviour : self::REVOKE_BEHAVIOUR_REVOKE;
	}

	/**
	 * The currency the shop sells in — every product is priced in it.
	 * A real ISO code or the GBP default; filter `gatedmedia_currency`
	 * has the last word.
	 */
	public function currency(): string {
		$settings = get_option( self::OPTION );
		$code     = is_array( $settings ) && isset( $settings['currency'] ) ? strtoupper( (string) $settings['currency'] ) : 'GBP';

		/**
		 * Filters the shop currency, over the stored setting.
		 *
		 * @param string $code ISO currency code.
		 */
		$code = strtoupper( (string) apply_filters( 'gatedmedia_currency', $code ) );

		return \Symfony\Component\Intl\Currencies::exists( $code ) ? $code : 'GBP';
	}

	/**
	 * The path segment a product's UUID URL lives under —
	 * `/{segment}/{uuid}` is the only public way to a product. Default
	 * `access`; filter `gatedmedia_product_path` has the last word.
	 */
	public function product_path(): string {
		$settings = get_option( self::OPTION );
		$path     = is_array( $settings ) && isset( $settings['product_path'] ) ? (string) $settings['product_path'] : 'access';

		/**
		 * Filters the product URL segment, over the stored setting.
		 *
		 * @param string $path The path segment.
		 */
		$path = sanitize_title( (string) apply_filters( 'gatedmedia_product_path', $path ) );

		return '' === $path ? 'access' : $path;
	}

	/**
	 * Which Stripe the site talks to: test unless live is stored, and never
	 * anything else. Filter `gatedmedia_stripe_mode` has the last word.
	 */
	public function stripe_mode(): string {
		$settings = get_option( self::OPTION );
		$mode     = is_array( $settings ) && isset( $settings['stripe_mode'] ) ? (string) $settings['stripe_mode'] : self::MODE_TEST;

		/**
		 * Filters the Stripe mode, over the stored setting.
		 *
		 * @param string $mode One of test, live.
		 */
		$mode = (string) apply_filters( 'gatedmedia_stripe_mode', $mode );

		return self::MODE_LIVE === $mode ? self::MODE_LIVE : self::MODE_TEST;
	}

	/**
	 * The publishable key for the current mode.
	 */
	public function stripe_key(): string {
		return $this->credential( 'key', 'gatedmedia_stripe_key' );
	}

	/**
	 * The secret key for the current mode.
	 */
	public function stripe_secret(): string {
		return $this->credential( 'secret', 'gatedmedia_stripe_secret' );
	}

	/**
	 * The webhook signing secret for the current mode.
	 */
	public function stripe_webhook_secret(): string {
		return $this->credential( 'webhook_secret', 'gatedmedia_stripe_webhook_secret' );
	}

	/**
	 * The one credential read (architecture §7: exactly one place reads
	 * them): `stripe_{mode}_{suffix}` from the option, filtered so wp-config
	 * can keep keys out of the database entirely.
	 *
	 * @param string           $suffix      key, secret, or webhook_secret — spec §8's names.
	 * @param non-empty-string $filter_name The filter with the last word.
	 */
	private function credential( string $suffix, string $filter_name ): string {
		$mode     = $this->stripe_mode();
		$settings = get_option( self::OPTION );
		$name     = "stripe_{$mode}_{$suffix}";
		$value    = is_array( $settings ) && isset( $settings[ $name ] ) ? (string) $settings[ $name ] : '';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Every caller passes a literal gatedmedia_ name, documented on its reader.
		return (string) apply_filters( $filter_name, $value, $mode );
	}
}
