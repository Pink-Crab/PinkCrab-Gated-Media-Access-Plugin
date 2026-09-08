<?php
/**
 * What deactivating and deleting the plugin take with them.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

use PinkCrab\Gated_Access\Access\Gated_Post_Route;
use PinkCrab\Gated_Access\Access\Sweep;
use PinkCrab\Gated_Access\Account\Account_Route;
use PinkCrab\Gated_Access\Auth\Auth_Route;
use PinkCrab\Gated_Access\Notifications\Expiry_Warning;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Products\Product_Route;
use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Deactivating stops the two daily events, which would otherwise keep firing at code that is no longer loaded, and deleting the plugin also takes every option, the Stripe secrets among them, and the four capabilities.
 *
 * Payments and access records are business history, so a delete keeps them unless the site has ticked "Delete all data on uninstall", which takes the payments table, the records, the products, the coupons and their terms.
 *
 * Static throughout, because `uninstall.php` runs with nothing booted and there is no container to resolve a service from.
 */
class Lifecycle {

	/**
	 * Every option the plugin writes.
	 *
	 * @return array<int, string>
	 */
	private static function options(): array {
		return array(
			Settings::OPTION,
			Payments_Schema::OPTION_DB_VERSION,
			Capabilities::CAPS_OPTION,
			Auth_Route::REWRITE_OPTION,
			Gated_Post_Route::REWRITE_OPTION,
			Product_Route::REWRITE_OPTION,
			Account_Route::REWRITE_OPTION,
		);
	}

	/**
	 * On deactivation: the two daily events go, and the rewrite rules are flushed so ours stop pointing at query vars nothing registers.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Sweep::HOOK );
		wp_clear_scheduled_hook( Expiry_Warning::HOOK );

		flush_rewrite_rules();
	}

	/**
	 * On delete: the credentials, the options and the capabilities always, and the data only when the site asked for it.
	 */
	public static function uninstall(): void {
		// Read before the options are deleted, since the flag lives in one.
		$purge = ( new Settings() )->purge_on_uninstall();

		self::deactivate();
		self::remove_capabilities();

		if ( $purge ) {
			self::purge_content();
		}

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Takes the four capabilities back off administrator.
	 */
	private static function remove_capabilities(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		foreach ( array(
			Capabilities::GIVE_ACCESS,
			Capabilities::MANAGE_PRODUCTS,
			Capabilities::VIEW_PAYMENTS,
			Capabilities::MANAGE_SETTINGS,
		) as $capability ) {
			$role->remove_cap( $capability );
		}
	}

	/**
	 * Everything the plugin ever stored: the payments table, every record, product and coupon, their terms, and the profile fields on users.
	 */
	private static function purge_content(): void {
		global $wpdb;

		// Core's delete functions want the types registered, and uninstall.php boots nothing.
		( new Post_Types() )->register();
		( new Access_Taxonomy() )->register();

		self::delete_posts();
		self::delete_terms();
		self::delete_user_meta();

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dropping our own table on uninstall; the name is built from $wpdb->prefix.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Every access record, product and coupon, with their meta and terms.
	 */
	private static function delete_posts(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Records carry custom statuses that WP_Query's "any" excludes.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s, %s )",
				Post_Types::ACCESS,
				Post_Types::PRODUCT,
				Post_Types::COUPON
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * The access taxonomy's marker terms.
	 */
	private static function delete_terms(): void {
		$terms = get_terms(
			array(
				'taxonomy'   => Access_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		if ( ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term_id ) {
			wp_delete_term( (int) $term_id, Access_Taxonomy::TAXONOMY );
		}
	}

	/**
	 * The profile fields written against users, whatever they are called.
	 */
	private static function delete_user_meta(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API lists meta keys by prefix.
		$keys = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE 'gatedmedia\_%'" );

		foreach ( $keys as $key ) {
			delete_metadata( 'user', 0, (string) $key, '', true );
		}
	}
}
