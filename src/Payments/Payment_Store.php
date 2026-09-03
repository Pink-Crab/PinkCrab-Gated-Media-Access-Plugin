<?php
/**
 * The payments table's one reader and writer.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use PinkCrab\Gated_Access\Admin\Coupon_Metabox;

/**
 * Every query against `{prefix}gatedmedia_payments` lives here. Payments are
 * not Access records — `Access_Writer` has no part in this table — but the
 * same one-owner rule applies.
 *
 * **The row is the replay guard** (spec §3, architecture §7). Stripe retries
 * deliveries, so each status mover is a single conditional update — move from
 * exactly one state, and let affected-rows answer who was first. One
 * statement, no gap between checking and writing, nothing else stored.
 *
 * Thirteen public methods, all queries against the one table. Splitting them
 * would put two owners on `{prefix}gatedmedia_payments` to satisfy a counter,
 * which is the rule this class exists to keep.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class Payment_Store {

	/**
	 * Creates the pending row — before the person leaves for Stripe, so the
	 * confirmation has something to attach to and the return page something
	 * to poll.
	 *
	 * @param int                $user_id           Who is buying.
	 * @param int                $product_id        The product bought.
	 * @param int                $amount_total      What they will pay, minor units, after discount.
	 * @param string             $currency          ISO code.
	 * @param array<int, string> $contents_snapshot The product's `type:id` targets, frozen now.
	 * @param int                $coupon_id         The coupon applied, 0 for none.
	 * @param int                $discount_amount   What the coupon took off, minor units.
	 * @return Payment|null The created row, or null when the insert failed.
	 */
	public function create_pending( int $user_id, int $product_id, int $amount_total, string $currency, array $contents_snapshot, int $coupon_id = 0, int $discount_amount = 0 ): ?Payment {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Our own table; there is no API in front of it.
		$inserted = $wpdb->insert(
			Payments_Schema::table_name(),
			array(
				'uuid'              => wp_generate_uuid4(),
				'user_id'           => $user_id,
				'product_id'        => $product_id,
				'amount_total'      => $amount_total,
				'currency'          => strtoupper( $currency ),
				'coupon_id'         => $coupon_id,
				'discount_amount'   => $discount_amount,
				'status'            => Payment::STATUS_PENDING,
				'contents_snapshot' => (string) wp_json_encode( array_values( $contents_snapshot ) ),
				'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	/**
	 * Records the Stripe session against its row, once the session exists —
	 * the one write that is not a status move.
	 *
	 * @param string $uuid       The payment.
	 * @param string $session_id Stripe's checkout session id.
	 */
	public function attach_session( string $uuid, string $session_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$updated = $wpdb->update(
			Payments_Schema::table_name(),
			array( 'stripe_session_id' => $session_id ),
			array( 'uuid' => $uuid ),
			array( '%s' ),
			array( '%s' )
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Records why a grant failed, or clears it with an empty string once one
	 * succeeds. Stripe's retries are finite, so this is what is left to look
	 * at on the payment's own screen after it has stopped delivering.
	 *
	 * @param string $uuid   The payment.
	 * @param string $reason The cause, or '' to clear.
	 */
	public function record_grant_error( string $uuid, string $reason ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$updated = $wpdb->update(
			Payments_Schema::table_name(),
			array( 'grant_error' => $reason ),
			array( 'uuid' => $uuid ),
			array( '%s' ),
			array( '%s' )
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Pending → complete, exactly once. True means we were first and the
	 * caller grants access; false means another delivery already did.
	 *
	 * @param string $uuid      The payment.
	 * @param string $intent_id Stripe's payment intent, kept for the refund path.
	 */
	public function mark_complete( string $uuid, string $intent_id = '' ): bool {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, stripe_payment_intent_id = %s, completed_at = %s WHERE uuid = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Payment::STATUS_COMPLETE,
				$intent_id,
				gmdate( 'Y-m-d H:i:s' ),
				$uuid,
				Payment::STATUS_PENDING
			)
		);

		return is_int( $moved ) && $moved > 0;
	}

	/**
	 * Complete → refunded, exactly once. True and the caller revokes the
	 * access this payment created.
	 *
	 * @param string $uuid The payment.
	 */
	public function mark_refunded( string $uuid ): bool {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, refunded_at = %s WHERE uuid = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Payment::STATUS_REFUNDED,
				gmdate( 'Y-m-d H:i:s' ),
				$uuid,
				Payment::STATUS_COMPLETE
			)
		);

		return is_int( $moved ) && $moved > 0;
	}

	/**
	 * Pending → failed — the checkout expired or was abandoned. Same guard,
	 * so a late confirmation cannot complete a payment already marked failed,
	 * and a late failure cannot undo a completion.
	 *
	 * @param string $uuid The payment.
	 */
	public function mark_failed( string $uuid ): bool {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
		$moved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE uuid = %s AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Payment::STATUS_FAILED,
				$uuid,
				Payment::STATUS_PENDING
			)
		);

		return is_int( $moved ) && $moved > 0;
	}

	/**
	 * One payment by its public identifier — what the return page polls.
	 *
	 * @param string $uuid The payment.
	 */
	public function find_by_uuid( string $uuid ): ?Payment {
		return $this->find_one( 'uuid = %s', $uuid );
	}

	/**
	 * One payment by Stripe's payment intent — how a refund event finds its
	 * row.
	 *
	 * @param string $intent_id Stripe's payment intent id.
	 */
	public function find_by_intent( string $intent_id ): ?Payment {
		if ( '' === $intent_id ) {
			return null;
		}

		return $this->find_one( 'stripe_payment_intent_id = %s', $intent_id );
	}

	/**
	 * One page of payments, newest first — the Payments screen.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Rows per page.
	 * @return array<int, Payment>
	 */
	public function paged( int $page, int $per_page ): array {
		global $wpdb;

		$table  = Payments_Schema::table_name();
		$offset = max( 0, $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array_map( array( Payment::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One person's payments, newest first — §7.3 Orders.
	 *
	 * Unpaged: an account holds few enough orders that the list is one flat
	 * read, which is the same reason §7.3 has no filter.
	 *
	 * @param int $user_id Whose orders.
	 * @return array<int, Payment>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table; the name is not user input.
				$user_id
			),
			ARRAY_A
		);

		return array_map( array( Payment::class, 'from_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How many payments exist — the screen's pagination.
	 */
	public function total(): int {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name; no input at all.
	}

	/**
	 * How many completed payments carry a coupon — its usage count, since
	 * usage is never stored (spec §1b). Counted at completion, so an
	 * abandoned checkout consumes nothing. `Coupon_Hold` covers the window
	 * between starting a checkout and finishing one; this counts only what
	 * finished.
	 *
	 * @param int $coupon_id The coupon.
	 * @param int $user_id   Restrict to one user's completions, 0 for all.
	 */
	public function coupon_completions( int $coupon_id, int $user_id = 0 ): int {
		global $wpdb;

		$table = Payments_Schema::table_name();

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND status = %s AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
					$coupon_id,
					Payment::STATUS_COMPLETE,
					$user_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
				$coupon_id,
				Payment::STATUS_COMPLETE
			)
		);
	}

	/**
	 * Whether this payment took its coupon past a limit, and by how much —
	 * null when it carries no coupon, has not completed, or stayed inside
	 * both limits.
	 *
	 * `Coupon_Hold` reserves a limited coupon only briefly, so two checkouts
	 * that overlap by longer than that window can both complete, and nothing
	 * can be refused once Stripe has the money. This is how the Payments
	 * screen says so afterwards. The whole-coupon limit is reported ahead of
	 * the per-buyer one when a payment breaks both.
	 *
	 * @param Payment $payment The row being asked about.
	 * @return array{used: int, limit: int, per_user: bool}|null
	 */
	public function coupon_overuse( Payment $payment ): ?array {
		if ( 0 === $payment->coupon_id || Payment::STATUS_COMPLETE !== $payment->status ) {
			return null;
		}

		$limits = array(
			Coupon_Metabox::META_USAGE_LIMIT    => 0,
			Coupon_Metabox::META_PER_USER_LIMIT => $payment->user_id,
		);

		foreach ( $limits as $key => $scope ) {
			$limit = (string) get_post_meta( $payment->coupon_id, $key, true );
			$used  = '' === $limit ? 0 : $this->coupon_use_ordinal( $payment, $scope );

			if ( '' !== $limit && $used > (int) $limit ) {
				return array(
					'used'     => $used,
					'limit'    => (int) $limit,
					'per_user' => 0 !== $scope,
				);
			}
		}

		return null;
	}

	/**
	 * Which use of the coupon this payment was: how many completions carrying
	 * it exist up to and including this row.
	 *
	 * Ordered by id, so the answer for a given payment never changes as later
	 * ones complete.
	 *
	 * @param Payment $payment The row being asked about.
	 * @param int     $user_id Count only this user's completions, 0 for all.
	 */
	private function coupon_use_ordinal( Payment $payment, int $user_id ): int {
		global $wpdb;

		$table = Payments_Schema::table_name();

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND status = %s AND id <= %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
					$payment->coupon_id,
					Payment::STATUS_COMPLETE,
					$payment->payment_id,
					$user_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE coupon_id = %d AND status = %s AND id <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Our own table name.
				$payment->coupon_id,
				Payment::STATUS_COMPLETE,
				$payment->payment_id
			)
		);
	}

	/**
	 * One row by primary key — the insert's read-back.
	 *
	 * @param int $row_id The row.
	 */
	private function find_by_id( int $row_id ): ?Payment {
		return $this->find_one( 'id = %d', $row_id );
	}

	/**
	 * The shared single-row read: newest match on one indexed column.
	 *
	 * @param string     $clause A `column = placeholder` pair from this class only.
	 * @param string|int $value  The value for its placeholder.
	 */
	private function find_one( string $clause, string|int $value ): ?Payment {
		global $wpdb;

		$table = Payments_Schema::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Our own table.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table and clause are this class's literals; the clause carries the placeholder, the value fills it.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT 1", $value ),
			ARRAY_A
		);

		return is_array( $row ) ? Payment::from_row( $row ) : null;
	}
}
