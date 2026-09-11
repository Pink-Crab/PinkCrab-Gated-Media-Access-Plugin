<?php
/**
 * The Accounts section of the settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\View;

/**
 * The three account settings: `account_creation`, `account_route` and `profile_prompt`.
 *
 * Its own class rather than three more methods on `Settings_Page`, which is at phpmd's class-complexity ceiling, the same call made when `Coupon_Pricing` was split out of `Checkout` rather than suppressing the rule.
 *
 * `Notification_Fields` is the pattern: render the section, sanitize its own keys, and let the screen own only the frame.
 *
 * **Core's `users_can_register` is not here, deliberately.** `account_creation` governs this plugin's own sign-up, wp-login.php keeps whatever policy the site already gave it, and the two run alongside each other.
 */
class Account_Fields {

	/**
	 * The settings reader, for the current values.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The section: how someone gets an account, whether the plugin's own account pages are on, and whether a thin profile is prompted.
	 */
	public function render(): void {
		View::render(
			'admin/settings/accounts',
			array(
				'option'           => Settings::OPTION,
				'creation'         => $this->settings->account_creation(),
				'creation_options' => array(
					Settings::ACCOUNT_CREATION_REGISTRATION => __( 'They sign themselves up', 'gated-media-access' ),
					Settings::ACCOUNT_CREATION_ADMIN    => __( 'An administrator creates them', 'gated-media-access' ),
					Settings::ACCOUNT_CREATION_PURCHASE => __( 'One is made when they buy', 'gated-media-access' ),
				),
				'auth_pages'       => $this->settings->auth_pages(),
				'auth_options'     => array(
					Settings::AUTH_PAGES_PLUGIN => __( 'On the plugin’s own pages', 'gated-media-access' ),
					Settings::AUTH_PAGES_CORE   => __( 'On the WordPress login screen', 'gated-media-access' ),
				),
				'account_route'    => $this->settings->account_route(),
				'profile_prompt'   => $this->settings->profile_prompt(),
				// Not Account_Url::section(): this names the route being switched.
				'account_url'      => home_url( '/' . Account_Url::slug() . '/' ),
			)
		);
	}

	/**
	 * The three keys, cleaned.
	 *
	 * `Settings_Page::sanitize()` starts from what is already stored and copies forward only the keys it knows, so a key with no clause anywhere is dropped on every save, which is what happened to these three.
	 *
	 * The route and the prompt are read with `isset()` rather than defaulted, so a form that does not carry them leaves them as they were instead of switching them off.
	 *
	 * @param array<string, mixed>  $input What options.php handed over.
	 * @param array<string, string> $clean The cleaned settings so far.
	 * @return array<string, string>
	 */
	public function sanitize( array $input, array $clean ): array {
		if ( isset( $input['account_creation'] ) ) {
			$route = (string) $input['account_creation'];
			$known = array( Settings::ACCOUNT_CREATION_REGISTRATION, Settings::ACCOUNT_CREATION_ADMIN, Settings::ACCOUNT_CREATION_PURCHASE );

			$clean['account_creation'] = in_array( $route, $known, true ) ? $route : Settings::ACCOUNT_CREATION_REGISTRATION;
		}

		if ( isset( $input['auth_pages'] ) ) {
			$pages = (string) $input['auth_pages'];

			$clean['auth_pages'] = Settings::AUTH_PAGES_CORE === $pages ? Settings::AUTH_PAGES_CORE : Settings::AUTH_PAGES_PLUGIN;
		}

		// $clean still carries what was stored, so this is the previous value.
		$previous = (string) ( $clean['account_route'] ?? '1' );

		foreach ( array( 'account_route', 'profile_prompt' ) as $name ) {
			if ( isset( $input[ $name ] ) ) {
				$clean[ $name ] = '1' === (string) $input[ $name ] ? '1' : '0';
			}
		}

		// The account area's rewrite rules exist only while the route is on, so switching it either way changes which URLs the site answers.
		if ( (string) ( $clean['account_route'] ?? '1' ) !== $previous ) {
			add_action( 'shutdown', 'flush_rewrite_rules' );
		}

		return $clean;
	}
}
