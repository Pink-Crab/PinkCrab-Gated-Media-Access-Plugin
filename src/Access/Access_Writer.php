<?php
/**
 * The one writer of access records.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use WP_Error;
use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * Every route in, whether Stripe, an administrator or the webhook, turns its input into the same four facts and hands them here. Nothing else creates or revokes access.
 *
 * The rules live here too. Re-granting timed access while it is live stacks onto the existing expiry, after expiry a fresh record, and a lifetime grant is always a new record. A grant whose source and reference have been seen before writes nothing, which is what makes a webhook retry safe.
 *
 * Owns its meta keys: the class that writes a key registers it.
 *
 * Large on purpose, and suppressed rather than split. This is the *only* writer of access records, so splitting it to satisfy a threshold would put writes outside the one class meant to hold them all.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Access_Writer implements Hookable {

	public const META_ITEM_TYPE  = 'gatedmedia_item_type';
	public const META_ITEM_ID    = 'gatedmedia_item_id';
	public const META_EXPIRES_AT = 'gatedmedia_expires_at';
	public const META_SOURCE     = 'gatedmedia_source';
	public const META_REFERENCE  = 'gatedmedia_reference';
	public const META_CREATED_BY = 'gatedmedia_created_by';
	public const META_PAYLOAD    = 'gatedmedia_payload';

	/**
	 * What each payment added to a stacked record: `source|reference|days`, one meta row per contribution.
	 *
	 * A record normally answers to the one grant that made it, and stacking breaks that: two payments become one record. Without this a refund of the second finds nothing to act on.
	 *
	 * The days are stored rather than looked up, so a refund takes back exactly what was added even if the product's duration has changed since.
	 */
	public const META_CONTRIBUTION = 'gatedmedia_contribution';

	/**
	 * Input checking and the read-side queries live beside the writer, not in it.
	 *
	 * @param Access_Validator $validator Checks a grant's four facts.
	 * @param Access_Lookup    $lookup    The retry-guard and stacking queries.
	 */
	public function __construct( private Access_Validator $validator, private Access_Lookup $lookup ) {
	}

	/**
	 * Registers the meta keys, and keeps them out of user-editable surfaces.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
	}

	/**
	 * Declares every key this class writes. Nothing is exposed over REST.
	 */
	public function register_meta(): void {
		foreach ( $this->meta_definitions() as $key => $args ) {
			register_post_meta( Post_Types::ACCESS, $key, $args );
		}
	}

	/**
	 * Marks our keys protected, so nothing treats them as user-editable.
	 *
	 * A hook rather than a naming convention, so it can be switched off for debugging without renaming anything.
	 *
	 * @param bool   $is_protected Whether the key is already protected.
	 * @param string $meta_key     The key being asked about.
	 * @param string $meta_type    The object type the key is on.
	 */
	public function protect_meta( bool $is_protected, string $meta_key, string $meta_type ): bool {
		if ( 'post' === $meta_type && array_key_exists( $meta_key, $this->meta_definitions() ) ) {
			return true;
		}

		return $is_protected;
	}

	/**
	 * Creates one access record from the four facts: who, what, how long, where from.
	 *
	 * @param int                  $user_id       Who holds it.
	 * @param string               $item_type     One of file, post, group.
	 * @param string               $item_id       Attachment ID, post ID, or group UUID.
	 * @param int|null             $duration_days How long, or null for lifetime.
	 * @param string               $source        The system it came from.
	 * @param string               $reference     That system's reference. Empty for admin grants.
	 * @param array<string, mixed> $payload       Free-form meta, stored as-is.
	 * @param int                  $created_by    The administrator who made it, when one did.
	 * @return int|WP_Error The access record's ID: new, extended, or already existing.
	 */
	public function grant( int $user_id, string $item_type, string $item_id, ?int $duration_days, string $source, string $reference = '', array $payload = array(), int $created_by = 0 ): int|WP_Error {
		$invalid = $this->validator->validate( $user_id, $item_type, $item_id, $duration_days, $source );

		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		// The retry guard: a source, reference and item already seen writes nothing, and the item is part of it because a product purchase writes one record per item under the same source and reference.
		if ( '' !== $reference ) {
			$existing = $this->lookup->find_by_reference( $source, $reference, $item_type, $item_id );

			if ( null !== $existing ) {
				return $existing;
			}
		}

		// Timed re-grant while live: the new time stacks onto the expiry.
		if ( null !== $duration_days ) {
			$stacked = $this->stack_onto_live( $user_id, $item_type, $item_id, $duration_days, $source, $reference );

			if ( null !== $stacked ) {
				return $stacked;
			}
		}

		return $this->insert( $user_id, $item_type, $item_id, $duration_days, $source, $reference, $payload, $created_by );
	}

	/**
	 * Withdraws one record. Authoritative and dateless, unlike expiry.
	 *
	 * Fires `gatedmedia_access_revoked` with the record and its holder.
	 *
	 * @param int $access_id The record to revoke.
	 */
	public function revoke( int $access_id ): bool {
		return $this->move_status( $access_id, Post_Types::STATUS_REVOKED, 'gatedmedia_access_revoked' );
	}

	/**
	 * Takes back what one payment gave, and nothing else.
	 *
	 * The reverse of the stack. A record built from several payments is not revoked outright when one is refunded, because the other periods were paid for separately. The refunded grant's own days come off the expiry, and the record is revoked only when that leaves nothing.
	 *
	 * 20 days refunded against 21 remaining leaves 1 day and access stands. Against 19 remaining, it goes.
	 *
	 * A record with no contribution recorded is a single grant, so the whole record is revoked.
	 *
	 * Idempotency is the caller's: `Stripe_Webhook::refund()` acts only when the row actually moved.
	 *
	 * @param string $source    What granted it.
	 * @param string $reference The grant's identifier.
	 * @return bool Whether anything was taken back.
	 */
	public function refund( string $source, string $reference ): bool {
		if ( '' === $reference ) {
			return false;
		}

		$acted = false;

		foreach ( $this->lookup->records_for_reference( $source, $reference ) as $access_id ) {
			$acted = $this->take_back( $access_id, $source, $reference ) || $acted;
		}

		return $acted;
	}

	/**
	 * One record, one refunded grant.
	 *
	 * @param int    $access_id The record.
	 * @param string $source    What granted it.
	 * @param string $reference The grant's identifier.
	 */
	private function take_back( int $access_id, string $source, string $reference ): bool {
		$days = $this->contribution( $access_id, $source, $reference );

		// Nothing stacked, so the record exists because of this payment alone.
		if ( null === $days ) {
			return $this->revoke( $access_id );
		}

		$expires    = (string) get_post_meta( $access_id, self::META_EXPIRES_AT, true );
		$expires_at = '' === $expires ? false : strtotime( $expires . ' +0000' );

		if ( false === $expires_at ) {
			return $this->revoke( $access_id );
		}

		$remaining = $expires_at - $days * DAY_IN_SECONDS;

		// The contribution goes either way, so a second refund cannot take the days twice.
		delete_post_meta( $access_id, self::META_CONTRIBUTION, $source . '|' . $reference . '|' . $days );

		if ( $remaining <= time() ) {
			return $this->revoke( $access_id );
		}

		update_post_meta( $access_id, self::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', $remaining ) );

		return true;
	}

	/**
	 * How many days one grant added to a record, or null when none were written down.
	 *
	 * @param int    $access_id The record.
	 * @param string $source    What granted it.
	 * @param string $reference The grant's identifier.
	 */
	private function contribution( int $access_id, string $source, string $reference ): ?int {
		$prefix = $source . '|' . $reference . '|';

		// `false` said out loud: a stacked record carries one row per payment.
		foreach ( (array) get_post_meta( $access_id, self::META_CONTRIBUTION, false ) as $stored ) {
			if ( is_string( $stored ) && str_starts_with( $stored, $prefix ) ) {
				return (int) substr( $stored, strlen( $prefix ) );
			}
		}

		return null;
	}

	/**
	 * Moves one record to expired.
	 *
	 * Two callers, one method. The daily sweep passes records already past their date, where only the status moves. The expire revoke behaviour passes live ones, where the date is pulled to now first.
	 *
	 * A revoked record is refused, because revocation has no way back but a fresh grant, while an expired record can be dated forward from Edit Access.
	 *
	 * Fires `gatedmedia_access_expired` with the record and its holder.
	 *
	 * @param int $access_id The record to expire.
	 */
	public function expire( int $access_id ): bool {
		$record = get_post( $access_id );

		if ( null === $record || Post_Types::ACCESS !== $record->post_type || Post_Types::STATUS_REVOKED === $record->post_status ) {
			return false;
		}

		$expires    = (string) get_post_meta( $access_id, self::META_EXPIRES_AT, true );
		$expires_at = '' === $expires ? false : strtotime( $expires . ' +0000' );

		if ( false === $expires_at || $expires_at > time() ) {
			update_post_meta( $access_id, self::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s' ) );
		}

		return $this->move_status( $access_id, Post_Types::STATUS_EXPIRED, 'gatedmedia_access_expired' );
	}

	/**
	 * Moves one record's expiry to a chosen date, for the Edit Access screen.
	 *
	 * The status follows the date: future or lifetime is active, past is expired. The resolver reads dates and the status only mirrors them.
	 *
	 * A revoked record is refused, because undoing one is a fresh grant rather than an edit.
	 *
	 * Fires `gatedmedia_access_rescheduled` with the record and its holder.
	 *
	 * @param int         $access_id  The record to reschedule.
	 * @param string|null $expires_at UTC `Y-m-d H:i:s`, or null/'' for lifetime.
	 */
	public function set_expiry( int $access_id, ?string $expires_at ): bool {
		$record = get_post( $access_id );

		if ( null === $record || Post_Types::ACCESS !== $record->post_type || Post_Types::STATUS_REVOKED === $record->post_status ) {
			return false;
		}

		$stored    = $expires_at ?? '';
		$timestamp = '' === $stored ? null : strtotime( $stored . ' +0000' );

		if ( false === $timestamp ) {
			return false;
		}

		update_post_meta( $access_id, self::META_EXPIRES_AT, null === $timestamp ? '' : gmdate( 'Y-m-d H:i:s', $timestamp ) );

		$status = null === $timestamp || $timestamp > time() ? Post_Types::STATUS_ACTIVE : Post_Types::STATUS_EXPIRED;

		return $this->move_status( $access_id, $status, 'gatedmedia_access_rescheduled' );
	}

	/**
	 * Removes one record outright, for the delete revoke behaviour, keeping no history at all.
	 *
	 * Fires `gatedmedia_access_revoked` once the record is gone, so every listener sees the same withdrawal a revoke announces. The record no longer resolves by then, so a listener gets the IDs.
	 *
	 * @param int $access_id The record to remove.
	 */
	public function delete( int $access_id ): bool {
		$record = get_post( $access_id );

		if ( null === $record || Post_Types::ACCESS !== $record->post_type ) {
			return false;
		}

		if ( ! wp_delete_post( $access_id, true ) instanceof WP_Post ) {
			return false;
		}

		do_action( 'gatedmedia_access_revoked', $access_id, (int) $record->post_author );

		return true;
	}

	/**
	 * The shared shape of a withdrawal: guard, move the status, announce.
	 *
	 * Every announcement carries the same pair the grant action does: the record and its holder.
	 *
	 * @param int              $access_id The record to move.
	 * @param string           $status    The status it moves to.
	 * @param non-empty-string $action    The action announcing it.
	 */
	private function move_status( int $access_id, string $status, string $action ): bool {
		$record = get_post( $access_id );

		if ( null === $record || Post_Types::ACCESS !== $record->post_type ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $access_id,
				'post_status' => $status,
			),
			true
		);

		if ( $updated instanceof WP_Error ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Both callers pass a literal gatedmedia_ name, documented on their methods.
		do_action( $action, $access_id, (int) $record->post_author );

		return true;
	}

	/**
	 * Extends a live timed record instead of writing a second one.
	 *
	 * A live lifetime record is left alone, because there is no expiry to stack onto, and the caller writes a fresh record instead.
	 *
	 * Fires `gatedmedia_access_rescheduled` when it extends one, because the date moved.
	 *
	 * @param int    $user_id       Who holds it.
	 * @param string $item_type     One of file, post, group.
	 * @param string $item_id       The target's identifier.
	 * @param int    $duration_days The time to add.
	 * @param string $source        What granted it, the contribution's owner.
	 * @param string $reference     The grant's identifier, '' for none.
	 * @return int|null The extended record's ID, or null when nothing stacked.
	 */
	private function stack_onto_live( int $user_id, string $item_type, string $item_id, int $duration_days, string $source, string $reference ): ?int {
		foreach ( $this->lookup->records_for_item( $user_id, $item_type, $item_id ) as $record_id ) {
			$expires    = (string) get_post_meta( $record_id, self::META_EXPIRES_AT, true );
			$expires_at = '' === $expires ? false : strtotime( $expires . ' +0000' );

			if ( false === $expires_at || $expires_at <= time() ) {
				continue;
			}

			update_post_meta(
				$record_id,
				self::META_EXPIRES_AT,
				gmdate( 'Y-m-d H:i:s', $expires_at + $duration_days * DAY_IN_SECONDS )
			);

			// Repeated meta rather than replacing the originals, so the reference lookups answer for every payment on the record.
			if ( '' !== $reference ) {
				add_post_meta( $record_id, self::META_SOURCE, $source );
				add_post_meta( $record_id, self::META_REFERENCE, $reference );
				add_post_meta( $record_id, self::META_CONTRIBUTION, $source . '|' . $reference . '|' . $duration_days );
			}

			// The date moved, so it is announced, exactly as set_expiry() does.
			do_action( 'gatedmedia_access_rescheduled', $record_id, $user_id );

			return $record_id;
		}

		return null;
	}

	/**
	 * Writes the record and announces it.
	 *
	 * @param int                  $user_id       Who holds it.
	 * @param string               $item_type     One of file, post, group.
	 * @param string               $item_id       The target's identifier.
	 * @param int|null             $duration_days Days, or null for lifetime.
	 * @param string               $source        The system it came from.
	 * @param string               $reference     That system's reference.
	 * @param array<string, mixed> $payload       The webhook's free-form meta.
	 * @param int                  $created_by    The administrator, when one made it.
	 * @return int|WP_Error
	 */
	private function insert( int $user_id, string $item_type, string $item_id, ?int $duration_days, string $source, string $reference, array $payload, int $created_by ): int|WP_Error {
		$meta = array(
			self::META_ITEM_TYPE  => $item_type,
			self::META_ITEM_ID    => $item_id,
			self::META_EXPIRES_AT => null === $duration_days
				? ''
				: gmdate( 'Y-m-d H:i:s', time() + $duration_days * DAY_IN_SECONDS ),
			self::META_SOURCE     => $source,
			self::META_REFERENCE  => $reference,
		);

		if ( $created_by > 0 ) {
			$meta[ self::META_CREATED_BY ] = $created_by;
		}

		if ( array() !== $payload ) {
			$meta[ self::META_PAYLOAD ] = (string) wp_json_encode( $payload );
		}

		$access_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::ACCESS,
				'post_status' => Post_Types::STATUS_ACTIVE,
				'post_author' => $user_id,
				// A label for the admin list. Never read by logic.
				'post_title'  => sprintf( '%1$s %2$s → user %3$d', $item_type, $item_id, $user_id ),
				'meta_input'  => $meta,
			),
			true
		);

		if ( $access_id instanceof WP_Error ) {
			return $access_id;
		}

		/**
		 * Fires once access has been created, whichever route created it.
		 *
		 * @param int $access_id The new record.
		 * @param int $user_id   Who holds it.
		 */
		do_action( 'gatedmedia_access_granted', (int) $access_id, $user_id );

		return (int) $access_id;
	}

	/**
	 * One definition per key. Single, typed, never in REST, with the payload checked as JSON.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function meta_definitions(): array {
		$text = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => '__return_false',
		);

		return array(
			self::META_ITEM_TYPE    => $text,
			self::META_ITEM_ID      => $text,
			self::META_EXPIRES_AT   => $text,
			self::META_SOURCE       => $text,
			self::META_REFERENCE    => $text,
			self::META_CREATED_BY   => array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => '__return_false',
			),
			self::META_PAYLOAD      => array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( $value ): string => is_string( $value ) && null !== json_decode( $value ) ? $value : '',
				'auth_callback'     => '__return_false',
			),
			// Not single: a stacked record carries one row per payment.
			self::META_CONTRIBUTION => array(
				'type'              => 'string',
				'single'            => false,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => '__return_false',
			),
		);
	}
}
