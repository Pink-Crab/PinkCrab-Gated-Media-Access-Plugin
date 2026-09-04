<?php
/**
 * The plugin's capabilities.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Grants the four capabilities to administrators, once per install.
 *
 * On `init` behind a stored version, deliberately not an activation hook: the
 * test suite requires the plugin by path and never activates it — the checkout
 * name on CI makes `activate_plugin()` fail silently — so an activation-only
 * grant would leave every capability check failing on a green codebase.
 *
 * Consumers check a capability through the lookups below rather than the raw
 * string, so each one is filterable in one place regardless of route
 * (architecture.md §3).
 */
class Capabilities implements Hookable {

	/** Creating access — admin screens and webhook alike. */
	public const GIVE_ACCESS = 'gatedmedia_give_access';

	/** Products and coupons. */
	public const MANAGE_PRODUCTS = 'gatedmedia_manage_products';

	/** The payments screen. */
	public const VIEW_PAYMENTS = 'gatedmedia_view_payments';

	/** The settings screen. */
	public const MANAGE_SETTINGS = 'gatedmedia_manage_settings';

	/** Bumped when the grant below changes, so it runs again once. */
	private const CAPS_VERSION = '1';

	/** Public so Lifecycle can delete it by name on uninstall. */
	public const CAPS_OPTION = 'gatedmedia_caps_version';

	/**
	 * Grants on init.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'grant' ) );
	}

	/**
	 * Adds the capabilities to administrator, once per version.
	 */
	public function grant(): void {
		if ( get_option( self::CAPS_OPTION ) === self::CAPS_VERSION ) {
			return;
		}

		$role = get_role( 'administrator' );

		// No administrator role, no grant — and no version write, so it is
		// retried rather than skipped forever.
		if ( null === $role ) {
			return;
		}

		foreach ( array( self::GIVE_ACCESS, self::MANAGE_PRODUCTS, self::VIEW_PAYMENTS, self::MANAGE_SETTINGS ) as $capability ) {
			$role->add_cap( $capability );
		}

		update_option( self::CAPS_OPTION, self::CAPS_VERSION, true );
	}

	/**
	 * The capability required to create access.
	 */
	public static function give_access(): string {
		/**
		 * Filters the capability required to create access.
		 *
		 * @param string $capability Defaults to gatedmedia_give_access.
		 */
		return (string) apply_filters( 'gatedmedia_give_access_capability', self::GIVE_ACCESS );
	}

	/**
	 * The capability required to manage products and coupons.
	 */
	public static function manage_products(): string {
		/**
		 * Filters the capability required to manage products and coupons.
		 *
		 * @param string $capability Defaults to gatedmedia_manage_products.
		 */
		return (string) apply_filters( 'gatedmedia_manage_products_capability', self::MANAGE_PRODUCTS );
	}

	/**
	 * The capability required to read the payments screen.
	 */
	public static function view_payments(): string {
		/**
		 * Filters the capability required to read the payments screen.
		 *
		 * @param string $capability Defaults to gatedmedia_view_payments.
		 */
		return (string) apply_filters( 'gatedmedia_view_payments_capability', self::VIEW_PAYMENTS );
	}

	/**
	 * The capability required to change the settings.
	 */
	public static function manage_settings(): string {
		/**
		 * Filters the capability required to change the settings.
		 *
		 * @param string $capability Defaults to gatedmedia_manage_settings.
		 */
		return (string) apply_filters( 'gatedmedia_manage_settings_capability', self::MANAGE_SETTINGS );
	}
}
