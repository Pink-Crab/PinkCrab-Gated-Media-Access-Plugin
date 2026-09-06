<?php
/**
 * The payments table's migration.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Creates and versions `{prefix}gatedmedia_payments` (spec §3).
 *
 * Runs from a load-time version check, never an activation hook: the test
 * bootstrap requires the plugin by path and nothing ever activates it, so an
 * activation-only migration would leave the integration suite without the
 * table. dbDelta makes the check cheap — one option read per request until
 * the version moves.
 */
class Payments_Schema implements Hookable {

	/** The option holding the installed schema version. */
	public const OPTION_DB_VERSION = 'gatedmedia_db_version';

	/** The current schema version. */
	public const DB_VERSION = '1';

	/**
	 * Migrates on init, before anything queries the table.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'migrate' ) );
	}

	/**
	 * The table, fully qualified for this site.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'gatedmedia_payments';
	}

	/**
	 * Brings the table up to the current version, once per bump.
	 */
	public function migrate(): void {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				stripe_session_id varchar(255) NOT NULL DEFAULT '',
				stripe_payment_intent_id varchar(255) NOT NULL DEFAULT '',
				amount_total bigint(20) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
				discount_amount bigint(20) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				contents_snapshot longtext,
				created_at datetime NOT NULL,
				completed_at datetime DEFAULT NULL,
				refunded_at datetime DEFAULT NULL,
				grant_error text,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				KEY user_id (user_id),
				KEY stripe_payment_intent_id (stripe_payment_intent_id(190)),
				KEY coupon_status (coupon_id,status)
			) {$collate};"
		);

		// Stamped only on a table that is really there: dbDelta neither throws
		// nor reports, so recording a failure as done retries nothing, ever.
		if ( self::table_exists() ) {
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
		}
	}

	/**
	 * Whether the payments table is actually in the database.
	 */
	public static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asked once per request, before anything is cached.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
