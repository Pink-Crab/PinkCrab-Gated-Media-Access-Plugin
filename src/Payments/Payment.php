<?php
/**
 * One row of the payments table.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

/**
 * A payment as read back from `{prefix}gatedmedia_payments` — typed and
 * never written to: `Payment_Store` owns every query, and this is what its
 * reads return. Not `readonly` only because phpstan's shared config parses
 * for PHP 8.0, where the keyword does not exist.
 *
 * Status is one of the four spec §3 values, held here so every caller names
 * the same strings.
 *
 * One field per column, and the table decides how many there are: this is
 * what a row of `{prefix}gatedmedia_payments` looks like, so dropping a
 * field or splitting the class would make it lie about the row it came from.
 *
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
final class Payment {

	/** Created before the person leaves for Stripe; nothing has happened yet. */
	public const STATUS_PENDING = 'pending';

	/** Stripe confirmed payment; access has been granted. */
	public const STATUS_COMPLETE = 'complete';

	/** Refunded after completion; the access it created is revoked. */
	public const STATUS_REFUNDED = 'refunded';

	/** The checkout ended without payment. */
	public const STATUS_FAILED = 'failed';

	/**
	 * The row's primary key.
	 *
	 * @var int
	 */
	public int $payment_id = 0;

	/**
	 * The public identifier — what goes to Stripe and into the return URL.
	 *
	 * @var string
	 */
	public string $uuid = '';

	/**
	 * Who is paying.
	 *
	 * @var int
	 */
	public int $user_id = 0;

	/**
	 * The product bought.
	 *
	 * @var int
	 */
	public int $product_id = 0;

	/**
	 * Stripe's checkout session, once one exists.
	 *
	 * @var string
	 */
	public string $stripe_session_id = '';

	/**
	 * Stripe's payment intent — how a refund finds this row.
	 *
	 * @var string
	 */
	public string $stripe_payment_intent_id = '';

	/**
	 * What was paid, minor units, after discount.
	 *
	 * @var int
	 */
	public int $amount_total = 0;

	/**
	 * ISO currency code.
	 *
	 * @var string
	 */
	public string $currency = '';

	/**
	 * The coupon applied, 0 for none.
	 *
	 * @var int
	 */
	public int $coupon_id = 0;

	/**
	 * What the coupon took off, minor units.
	 *
	 * @var int
	 */
	public int $discount_amount = 0;

	/**
	 * One of the four STATUS_* values.
	 *
	 * @var string
	 */
	public string $status = self::STATUS_PENDING;

	/**
	 * The product's `type:id` targets frozen at purchase (spec §3): groups
	 * are live, so the contents at the moment of purchase are not
	 * recoverable later.
	 *
	 * @var array<int, string>
	 */
	public array $contents_snapshot = array();

	/**
	 * When the row was created, UTC.
	 *
	 * @var string
	 */
	public string $created_at = '';

	/**
	 * When Stripe confirmed, UTC, or null while it has not.
	 *
	 * @var string|null
	 */
	public ?string $completed_at = null;

	/**
	 * When the refund landed, UTC, or null while none has.
	 *
	 * @var string|null
	 */
	public ?string $refunded_at = null;

	/**
	 * Why the last grant attempt failed, empty when none has.
	 *
	 * A payment whose grant fails stays pending so Stripe delivers again,
	 * but its retries are finite — so the cause is kept here, where the
	 * payment's own screen can show it once Stripe has given up.
	 *
	 * @var string
	 */
	public string $grant_error = '';

	/**
	 * Built from a row, nowhere else — the property list stays honest to the
	 * table and the constructor stays out of every caller's way.
	 *
	 * @param array<string, string|null> $row One `{prefix}gatedmedia_payments` row, as $wpdb returns it.
	 */
	public static function from_row( array $row ): self {
		$payment = new self();

		$payment->payment_id               = (int) ( $row['id'] ?? 0 );
		$payment->uuid                     = (string) ( $row['uuid'] ?? '' );
		$payment->user_id                  = (int) ( $row['user_id'] ?? 0 );
		$payment->product_id               = (int) ( $row['product_id'] ?? 0 );
		$payment->stripe_session_id        = (string) ( $row['stripe_session_id'] ?? '' );
		$payment->stripe_payment_intent_id = (string) ( $row['stripe_payment_intent_id'] ?? '' );
		$payment->amount_total             = (int) ( $row['amount_total'] ?? 0 );
		$payment->currency                 = (string) ( $row['currency'] ?? '' );
		$payment->coupon_id                = (int) ( $row['coupon_id'] ?? 0 );
		$payment->discount_amount          = (int) ( $row['discount_amount'] ?? 0 );
		$payment->status                   = (string) ( $row['status'] ?? self::STATUS_PENDING );
		$payment->contents_snapshot        = self::decode_snapshot( (string) ( $row['contents_snapshot'] ?? '' ) );
		$payment->created_at               = (string) ( $row['created_at'] ?? '' );
		$payment->completed_at             = isset( $row['completed_at'] ) ? (string) $row['completed_at'] : null;
		$payment->refunded_at              = isset( $row['refunded_at'] ) ? (string) $row['refunded_at'] : null;
		$payment->grant_error              = (string) ( $row['grant_error'] ?? '' );

		return $payment;
	}

	/**
	 * The stored JSON back to its list of `type:id` strings. Anything that
	 * does not decode to a list is an empty snapshot, never an error.
	 *
	 * @param string $snapshot The stored JSON.
	 * @return array<int, string>
	 */
	private static function decode_snapshot( string $snapshot ): array {
		$decoded = json_decode( $snapshot, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_map( 'strval', $decoded ) );
	}

	/**
	 * Rows come through from_row(); nothing builds an empty one.
	 */
	private function __construct() {
	}
}
