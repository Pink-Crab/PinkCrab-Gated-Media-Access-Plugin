<?php
/**
 * Reading the plugin's settings.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

/**
 * The one settings option, `gatedmedia_settings`, an array this class only reads and `Settings_Page` writes.
 *
 * One reader per setting keeps every accessor the same shape, so the method count grows with the option, and they stay together with the counter quieted.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class Settings {

	/** The option holding every setting. */
	public const OPTION = 'gatedmedia_settings';

	/** Stripe's sandbox. The default: never live by accident. */
	public const MODE_TEST = 'test';

	/** Real money. */
	public const MODE_LIVE = 'live';

	/** Revoke marks the record revoked. The default, keeping history. */
	public const REVOKE_BEHAVIOUR_REVOKE = 'revoke';

	/** Expire pulls the record's date to now. */
	public const REVOKE_BEHAVIOUR_EXPIRE = 'expire';

	/** Delete removes the record outright. */
	public const REVOKE_BEHAVIOUR_DELETE = 'delete';

	/** Anyone may sign themselves up on the front end. The default. */
	public const ACCOUNT_CREATION_REGISTRATION = 'registration';

	/** Only an administrator creates accounts. */
	public const ACCOUNT_CREATION_ADMIN = 'admin';

	/** An account appears when a purchase completes, and no other way. */
	public const ACCOUNT_CREATION_PURCHASE = 'purchase';

	/**
	 * What revoking a record does on this site: revoke, expire or delete.
	 *
	 * Defaults to revoke, so records are kept as history, and anything unrecognised lands back there.
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
	 * The currency the shop sells in, and every product is priced in it. A real ISO code or the GBP default, with `gatedmedia_currency` having the last word.
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
	 * Whether deleting the plugin also takes the payments table, the access records, the products and the coupons, off unless asked for.
	 */
	public function purge_on_uninstall(): bool {
		$settings = get_option( self::OPTION );
		$purge    = is_array( $settings ) && '1' === (string) ( $settings['purge_on_uninstall'] ?? '0' );

		/**
		 * Filters whether an uninstall takes the data with it.
		 *
		 * @param bool $purge The stored setting.
		 */
		return (bool) apply_filters( 'gatedmedia_purge_on_uninstall', $purge );
	}

	/**
	 * The path segment a product's UUID URL lives under, since `/{segment}/{uuid}` is the only public way to a product. Default `access`, with `gatedmedia_product_path` having the last word.
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
	 * Whether the plugin's own `/account/` route is registered at all.
	 *
	 * Off means a site places the account blocks on its own pages instead, and both roads render the same blocks. Default on, with `gatedmedia_account_route` having the last word.
	 */
	public function account_route(): bool {
		$settings = get_option( self::OPTION );
		$stored   = is_array( $settings ) && isset( $settings['account_route'] ) ? (string) $settings['account_route'] : '1';

		/**
		 * Filters whether the account route is on, over the stored setting.
		 *
		 * @param bool $on Whether to register the route.
		 */
		return (bool) apply_filters( 'gatedmedia_account_route', '1' === $stored );
	}

	/**
	 * How someone gets an account: registration, admin or purchase. Only this plugin's own sign-up obeys it, and core's `users_can_register` is never read or written, so wp-login.php keeps its own policy.
	 *
	 * Registration by default, `gatedmedia_account_creation` has the last word, and anything unrecognised lands back there.
	 */
	public function account_creation(): string {
		$settings = get_option( self::OPTION );
		$route    = is_array( $settings ) && isset( $settings['account_creation'] ) ? (string) $settings['account_creation'] : self::ACCOUNT_CREATION_REGISTRATION;

		/**
		 * Filters how accounts are created, over the stored setting.
		 *
		 * @param string $route One of registration, admin, purchase.
		 */
		$route = (string) apply_filters( 'gatedmedia_account_creation', $route );

		$known = array( self::ACCOUNT_CREATION_REGISTRATION, self::ACCOUNT_CREATION_ADMIN, self::ACCOUNT_CREATION_PURCHASE );

		return in_array( $route, $known, true ) ? $route : self::ACCOUNT_CREATION_REGISTRATION;
	}

	/**
	 * Whether a thin account is asked to complete itself on first sign-in.
	 *
	 * Default off, because a prompt interrupting everyone is worse than a thin profile. `gatedmedia_profile_prompt` has the last word.
	 */
	public function profile_prompt(): bool {
		$settings = get_option( self::OPTION );
		$stored   = is_array( $settings ) && isset( $settings['profile_prompt'] ) ? (string) $settings['profile_prompt'] : '0';

		/**
		 * Filters whether first sign-in prompts for a thin profile.
		 *
		 * @param bool $prompt Whether to prompt.
		 */
		return (bool) apply_filters( 'gatedmedia_profile_prompt', '1' === $stored );
	}

	/**
	 * Which Stripe the site talks to: test unless live is stored, never anything else, and `gatedmedia_stripe_mode` has the last word.
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
	 * Whether one notification type sends at all, on unless switched off, and `gatedmedia_notification_enabled` has the last word.
	 *
	 * @param string $type The notification type key.
	 */
	public function notification_enabled( string $type ): bool {
		$settings = get_option( self::OPTION );
		$enabled  = ! is_array( $settings ) || '0' !== ( $settings[ "notify_{$type}" ] ?? '1' );

		/**
		 * Filters whether one notification type sends.
		 *
		 * @param bool   $enabled The stored switch.
		 * @param string $type    The notification type key.
		 */
		return (bool) apply_filters( 'gatedmedia_notification_enabled', $enabled, $type );
	}

	/**
	 * A stored template override for one notification type: subject and body, each '' where the shipped default should be used. `gatedmedia_notification_template` has the last word.
	 *
	 * @param string $type The notification type key.
	 * @return array{subject: string, body: string}
	 */
	public function notification_template( string $type ): array {
		$settings = get_option( self::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$template = array(
			'subject' => (string) ( $settings[ "template_{$type}_subject" ] ?? '' ),
			'body'    => (string) ( $settings[ "template_{$type}_body" ] ?? '' ),
		);

		/**
		 * Filters one notification type's stored template override.
		 *
		 * @param array{subject: string, body: string} $template The stored override, '' parts meaning the default.
		 * @param string                               $type     The notification type key.
		 */
		$template = (array) apply_filters( 'gatedmedia_notification_template', $template, $type );

		return array(
			'subject' => (string) ( $template['subject'] ?? '' ),
			'body'    => (string) ( $template['body'] ?? '' ),
		);
	}

	/**
	 * How many days before a timed record lapses the warning is sent: 7 by default, never below 1, and `gatedmedia_expiry_warning_days` has the last word.
	 */
	public function expiry_warning_days(): int {
		$settings = get_option( self::OPTION );
		$days     = is_array( $settings ) && isset( $settings['expiry_warning_days'] ) ? (int) $settings['expiry_warning_days'] : 7;

		/**
		 * Filters the expiry warning lead time.
		 *
		 * @param int $days Days before expiry the warning is sent.
		 */
		$days = (int) apply_filters( 'gatedmedia_expiry_warning_days', $days );

		return max( 1, $days );
	}

	/**
	 * The address every enabled notification is copied to, or '' when the admin-copy switch is off, defaulting to the site admin email once switched on, with `gatedmedia_notification_admin_copy` having the last word.
	 */
	public function admin_copy_address(): string {
		$settings = get_option( self::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$address = '';

		if ( '1' === ( $settings['admin_copy'] ?? '0' ) ) {
			$stored  = sanitize_email( (string) ( $settings['admin_copy_address'] ?? '' ) );
			$address = '' === $stored ? (string) get_option( 'admin_email' ) : $stored;
		}

		/**
		 * Filters the admin-copy address, '' meaning no copy.
		 *
		 * @param string $address Where copies go.
		 */
		return (string) apply_filters( 'gatedmedia_notification_admin_copy', $address );
	}

	/**
	 * The one credential read, and the only place they are read: `stripe_{mode}_{suffix}` from the option, filtered so wp-config can keep keys out of the database entirely.
	 *
	 * @param string           $suffix      key, secret, or webhook_secret.
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
