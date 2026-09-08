<?php
/**
 * How an expiry reads.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * Turns a stored expiry timestamp into the state and wording the expiry block declares, one of the four values `blocks/expiry/block.json` enumerates.
 *
 * Shared because more than one view says the same thing about the same date: My Access says it about access held, and an order detail says it about the access that order created.
 *
 * Two copies would drift, and the "soon" threshold is filterable, so they would drift for third parties too.
 */
class Expiry {

	/**
	 * The state and label for an expiry, or lifetime when there is none.
	 *
	 * @param int|null $expires_at Unix timestamp, or null for access that never ends.
	 * @return array{state: string, label: string}
	 */
	public static function describe( ?int $expires_at ): array {
		if ( null === $expires_at ) {
			return array(
				'state' => 'lifetime',
				'label' => __( 'Lifetime', 'gated-media-access' ),
			);
		}

		$days_left = (int) ceil( ( $expires_at - time() ) / DAY_IN_SECONDS );

		/**
		 * How close an expiry has to be before it is flagged as soon.
		 *
		 * @param int $days Defaults to 7.
		 */
		$soon_days = (int) apply_filters( 'gatedmedia_expiry_soon_days', 7 );

		if ( $days_left <= $soon_days ) {
			return array(
				'state' => 'soon',
				/* translators: %d: days until the access expires. */
				'label' => sprintf( _n( 'Expires in %d day', 'Expires in %d days', max( 1, $days_left ), 'gated-media-access' ), max( 1, $days_left ) ),
			);
		}

		return array(
			'state' => 'dated',
			/* translators: %s: the date the access expires. */
			'label' => sprintf( __( 'Expires %s', 'gated-media-access' ), wp_date( (string) get_option( 'date_format' ), $expires_at ) ),
		);
	}

	/**
	 * Access that ran out.
	 *
	 * `describe()` floors a past date at one day, because everywhere it is asked about live access that is the only sensible reading, and a view listing records whatever their status asks for this instead.
	 *
	 * @return array{state: string, label: string}
	 */
	public static function ended(): array {
		return array(
			'state' => 'expired',
			'label' => __( 'Expired', 'gated-media-access' ),
		);
	}

	/**
	 * Access taken back, which is not the same as access that ran out.
	 *
	 * A revoke leaves the stored date alone, so the date is never the thing to report here, or a refunded lifetime record would read "Lifetime".
	 *
	 * @return array{state: string, label: string}
	 */
	public static function withdrawn(): array {
		return array(
			'state' => 'expired',
			'label' => __( 'Withdrawn', 'gated-media-access' ),
		);
	}
}
