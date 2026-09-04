<?php
/**
 * The expiry warning job.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Notifications;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Account\Sections\My_Access_Section;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * Warns holders before timed access lapses — `Sweep`'s shape (spec §9):
 * daily, idempotent scheduling, named statuses, and if it never ran nothing
 * would leak. Lifetime records hold '' in the expiry meta and are never
 * warned; the lead time is the `expiry_warning_days` setting through its
 * filter.
 *
 * Warned-once bookkeeping is one meta key on the record, owned and
 * registered here — cleared again when the record is rescheduled, so a new
 * date earns a new warning.
 */
class Expiry_Warning implements Hookable {

	/** The cron hook, daily (specification.md §9). */
	public const HOOK = 'gatedmedia_expiry_warnings';

	/** When the warning for the record's current date went out. */
	public const META_WARNED_AT = 'gatedmedia_expiry_warned_at';

	/**
	 * Reads records and settings, sends through the one sender.
	 *
	 * @param Notification_Sender $sender   The one sender.
	 * @param Settings            $settings The lead-time setting.
	 * @param Access_Taxonomy     $taxonomy Turns a group UUID back into its term.
	 * @param My_Access_Section   $section  The section the email links to.
	 */
	public function __construct(
		private Notification_Sender $sender,
		private Settings $settings,
		private Access_Taxonomy $taxonomy,
		private My_Access_Section $section
	) {
	}

	/**
	 * Schedules on init, runs on the cron hook, forgets the warning when a
	 * record's date moves.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
		$loader->action( 'init', array( $this, 'schedule' ) );
		$loader->action( self::HOOK, array( $this, 'run' ) );
		$loader->action( 'gatedmedia_access_rescheduled', array( $this, 'forget_warning' ) );
	}

	/**
	 * Declares the one key this class writes.
	 */
	public function register_meta(): void {
		register_post_meta(
			Post_Types::ACCESS,
			self::META_WARNED_AT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => '__return_false',
			)
		);
	}

	/**
	 * Marks our key protected, like every key the plugin writes.
	 *
	 * @param bool   $is_protected Whether the key is already protected.
	 * @param string $meta_key     The key being asked about.
	 * @param string $meta_type    The object type the key is on.
	 */
	public function protect_meta( bool $is_protected, string $meta_key, string $meta_type ): bool {
		return ( 'post' === $meta_type && self::META_WARNED_AT === $meta_key ) ? true : $is_protected;
	}

	/**
	 * Schedules the daily event, exactly once.
	 */
	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK );
		}
	}

	/**
	 * A rescheduled record may warn again for its new date.
	 *
	 * @param int $access_id The record whose date moved.
	 */
	public function forget_warning( int $access_id ): void {
		delete_post_meta( $access_id, self::META_WARNED_AT );
	}

	/**
	 * Warns every active record inside the lead window, once.
	 *
	 * @return int How many warnings were sent.
	 */
	public function run(): int {
		// Switched off means untouched — nothing sends, and nothing is
		// marked warned, so switching back on picks records up again.
		if ( ! $this->settings->notification_enabled( Notification_Sender::TYPE_EXPIRY_WARNING ) ) {
			return 0;
		}

		$warned = 0;

		foreach ( $this->expiring_ids() as $access_id ) {
			$holder = (int) get_post_field( 'post_author', $access_id );
			$sent   = $this->sender->send(
				Notification_Sender::TYPE_EXPIRY_WARNING,
				$holder,
				array(
					'item'    => $this->item_label( $access_id ),
					'link'    => Account_Url::section( $this->section->slug() ),
					'expires' => $this->expires_label( $access_id ),
				)
			);

			// Flagged only on a send that worked: the flag is what the query
			// skips on, so writing it after a failure means that holder is
			// never warned. Left unflagged, tomorrow's run tries again.
			if ( ! $sent ) {
				continue;
			}

			update_post_meta( $access_id, self::META_WARNED_AT, gmdate( 'Y-m-d H:i:s' ) );

			++$warned;
		}

		return $warned;
	}

	/**
	 * Active records expiring inside the lead window, not yet warned.
	 *
	 * The status is named, never 'any' (the round 1 trap). Lifetime records
	 * hold '' and never match the window.
	 *
	 * @return array<int, int>
	 */
	private function expiring_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron; the window and the warned flag are the whole condition.
				'meta_query'     => array(
					array(
						'key'     => Access_Writer::META_EXPIRES_AT,
						'value'   => array( gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + $this->settings->expiry_warning_days() * DAY_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => self::META_WARNED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * What the record grants, named for a person.
	 *
	 * @param int $access_id The record.
	 */
	private function item_label( int $access_id ): string {
		$type       = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true );
		$identifier = (string) get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true );

		if ( 'group' === $type ) {
			$term = $this->taxonomy->find_group( $identifier );

			return null === $term ? $identifier : $term->name;
		}

		$title = get_the_title( (int) $identifier );

		return '' === $title ? "#{$identifier}" : $title;
	}

	/**
	 * The record's expiry as a date a person reads.
	 *
	 * @param int $access_id The record.
	 */
	private function expires_label( int $access_id ): string {
		$stored    = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );
		$timestamp = '' === $stored ? false : strtotime( $stored . ' +0000' );

		return false === $timestamp
			? __( 'never', 'gated-media-access' )
			: date_i18n( (string) get_option( 'date_format' ), $timestamp );
	}
}
