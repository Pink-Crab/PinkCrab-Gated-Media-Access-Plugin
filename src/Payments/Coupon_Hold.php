<?php
/**
 * A short reservation on a limited coupon.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

/**
 * Usage is counted from completed payments, which leaves a window: everyone who starts a checkout while nobody has finished one passes the same limit check, and they all complete.
 *
 * This closes it for buyers arriving together, by reserving the coupon for the minute or so a checkout takes.
 *
 * The reservation is one conditional `INSERT ... SELECT`, so the database decides who was first rather than PHP, a check followed by a write being racy however tight the gap, which is the reasoning behind `Payment_Store`'s status moves too.
 *
 * Holds carry their own expiry in the meta value and count only while that is in the future, so an abandoned checkout needs no sweeper and its hold simply stops counting.
 *
 * `gatedmedia_coupon_hold_seconds` sets the window, and `0` turns reserving off altogether.
 *
 * Only the holds are counted here: completions are read through `Payment_Store`, which owns that table, and arrive as the room left.
 */
class Coupon_Hold {

	/** A whole-coupon hold, suffixed with the payment it belongs to. */
	private const KEY = '_gatedmedia_coupon_held_';

	/** One buyer's hold, suffixed with their id and the payment. */
	private const USER_KEY = '_gatedmedia_coupon_user_held_';

	/**
	 * How long a reservation lasts, in seconds. Zero means no reserving.
	 */
	public function seconds(): int {
		/**
		 * Filters how long a checkout reserves a limited coupon.
		 *
		 * @param int $seconds The window, 0 to reserve nothing.
		 */
		return max( 0, (int) apply_filters( 'gatedmedia_coupon_hold_seconds', MINUTE_IN_SECONDS ) );
	}

	/**
	 * Reserves one use of the coupon, if there is room for it.
	 *
	 * @param int    $coupon_id The coupon.
	 * @param string $uuid      The payment the hold belongs to.
	 * @param int    $room      Uses left after completions, from Payment_Store.
	 * @return bool Whether this checkout got one.
	 */
	public function take( int $coupon_id, string $uuid, int $room ): bool {
		return $this->reserve( $coupon_id, self::KEY . $uuid, self::KEY . '%', $room );
	}

	/**
	 * Reserves one of this buyer's own uses, if there is room for it.
	 *
	 * @param int    $coupon_id The coupon.
	 * @param int    $user_id   The buyer.
	 * @param string $uuid      The payment the hold belongs to.
	 * @param int    $room      Uses left after their completions.
	 * @return bool Whether this checkout got one.
	 */
	public function take_for_user( int $coupon_id, int $user_id, string $uuid, int $room ): bool {
		return $this->reserve( $coupon_id, $this->user_key( $user_id ) . $uuid, $this->user_key( $user_id ) . '%', $room );
	}

	/**
	 * How many live reservations the coupon carries.
	 *
	 * @param int $coupon_id The coupon.
	 */
	public function live( int $coupon_id ): int {
		return $this->count( $coupon_id, self::KEY . '%' );
	}

	/**
	 * How many live reservations one buyer holds on the coupon.
	 *
	 * @param int $coupon_id The coupon.
	 * @param int $user_id   The buyer.
	 */
	public function live_for_user( int $coupon_id, int $user_id ): int {
		return $this->count( $coupon_id, $this->user_key( $user_id ) . '%' );
	}

	/**
	 * Gives back whatever a payment reserved, safely for a payment that reserved nothing and safely twice.
	 *
	 * @param int    $coupon_id The coupon.
	 * @param int    $user_id   The buyer.
	 * @param string $uuid      The payment.
	 */
	public function release( int $coupon_id, int $user_id, string $uuid ): void {
		if ( 0 === $coupon_id || '' === $uuid ) {
			return;
		}

		delete_post_meta( $coupon_id, self::KEY . $uuid );
		delete_post_meta( $coupon_id, $this->user_key( $user_id ) . $uuid );
	}

	/**
	 * The per-buyer key's prefix, with their id in the key so one buyer's holds can be counted without reading anybody else's.
	 *
	 * @param int $user_id The buyer.
	 */
	private function user_key( int $user_id ): string {
		return self::USER_KEY . $user_id . '_';
	}

	/**
	 * The reservation itself: insert the hold only while the live ones are still under the room left, in one statement.
	 *
	 * The row source is `posts` rather than `postmeta` because MySQL will not read the table being inserted into in the outer select, though the subquery may still name it.
	 *
	 * WooCommerce's `check_and_hold_coupon()` takes the same route, deadlock retry included, since under load the database aborts whichever side has done less work.
	 *
	 * @param int    $coupon_id The coupon.
	 * @param string $key       The exact key this hold is written under.
	 * @param string $like      The pattern matching every hold it competes with.
	 * @param int    $room      How many of that kind may exist at once.
	 */
	private function reserve( int $coupon_id, string $key, string $like, int $room ): bool {
		global $wpdb;

		$seconds = $this->seconds();

		if ( 0 === $seconds || $room <= 0 ) {
			return $room > 0;
		}

		$statement = $wpdb->prepare(
			"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
			SELECT %d, %s, %s FROM {$wpdb->posts}
			WHERE ( SELECT COUNT(*) FROM {$wpdb->postmeta}
				WHERE post_id = %d AND meta_key LIKE %s AND CAST( meta_value AS UNSIGNED ) > UNIX_TIMESTAMP()
				FOR UPDATE ) < %d
			LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core's own table names.
			$coupon_id,
			$key,
			(string) ( time() + $seconds ),
			$coupon_id,
			$like,
			$room
		);

		// A combined index on post_id and meta_key can deadlock this insert under load, and three attempts is what WooCommerce settled on.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
			$inserted = $wpdb->query( $statement );

			if ( false !== $inserted ) {
				wp_cache_delete( $coupon_id, 'post_meta' );

				return $inserted > 0;
			}
		}

		return false;
	}

	/**
	 * Live holds matching a key pattern.
	 *
	 * @param int    $coupon_id The coupon.
	 * @param string $like      The key pattern.
	 */
	private function count( int $coupon_id, string $like ): int {
		global $wpdb;

		if ( 0 === $this->seconds() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting rows core has no API for.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s AND CAST( meta_value AS UNSIGNED ) > UNIX_TIMESTAMP()", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core's own table name.
				$coupon_id,
				$like
			)
		);
	}
}
