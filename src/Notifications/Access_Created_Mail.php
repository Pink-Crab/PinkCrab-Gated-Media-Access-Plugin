<?php
/**
 * The access-created email.
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
use PinkCrab\Gated_Access\Support\Account_Url;

/**
 * Tells the holder when access is created, whichever route created it —
 * a listener on the writer's `gatedmedia_access_granted`, reading only
 * (spec §5: notifications hang off the writer's actions and never write).
 *
 * A product purchase grants one record per item in the same request, so
 * grants queue per holder and one email goes out on shutdown with the item
 * labels joined — never one email per record.
 *
 * Invite-sourced grants are skipped: the invite email is itself the
 * "you have access" message, and two emails for one act is noise.
 */
class Access_Created_Mail implements Hookable {

	/**
	 * Queued grants, holder to record IDs, flushed on shutdown.
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $queued = array();

	/**
	 * Labels resolve like the admin list's: groups through the taxonomy,
	 * everything else by title.
	 *
	 * @param Notification_Sender $sender   The one sender.
	 * @param Access_Taxonomy     $taxonomy Turns a group UUID back into its term.
	 * @param My_Access_Section   $section  The section the email links to.
	 */
	public function __construct(
		private Notification_Sender $sender,
		private Access_Taxonomy $taxonomy,
		private My_Access_Section $section
	) {
	}

	/**
	 * Queues on every grant, sends once per holder on shutdown.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'gatedmedia_access_granted', array( $this, 'queue' ), 2 );
		$loader->action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Remembers one grant for the shutdown send.
	 *
	 * @param int $access_id The new record.
	 * @param int $user_id   Who holds it.
	 */
	public function queue( int $access_id, int $user_id ): void {
		if ( Post_Types::ACCESS !== get_post_type( $access_id ) ) {
			return;
		}

		// Invite grants announce themselves through the invite email —
		// source constant owned by Products\Invites, this round's listener.
		if ( 'invite' === (string) get_post_meta( $access_id, Access_Writer::META_SOURCE, true ) ) {
			return;
		}

		$this->queued[ $user_id ][] = $access_id;
	}

	/**
	 * One email per holder for everything granted this request.
	 */
	public function flush(): void {
		$queued       = $this->queued;
		$this->queued = array();

		foreach ( $queued as $user_id => $access_ids ) {
			$this->sender->send(
				Notification_Sender::TYPE_ACCESS_CREATED,
				$user_id,
				array(
					'item'    => implode( ', ', array_unique( array_map( array( $this, 'item_label' ), $access_ids ) ) ),
					'link'    => Account_Url::section( $this->section->slug() ),
					'expires' => $this->expires_label( $access_ids ),
				)
			);
		}
	}

	/**
	 * What one record grants, named for a person.
	 *
	 * @param int $access_id The record.
	 */
	public function item_label( int $access_id ): string {
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
	 * The {expires} value across the queued records: 'never' when every
	 * record is lifetime, otherwise the soonest date — conservative and
	 * always true.
	 *
	 * @param array<int, int> $access_ids The holder's records this request.
	 */
	private function expires_label( array $access_ids ): string {
		$soonest = null;

		foreach ( $access_ids as $access_id ) {
			$stored = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

			if ( '' === $stored ) {
				continue;
			}

			$timestamp = strtotime( $stored . ' +0000' );

			if ( false !== $timestamp && ( null === $soonest || $timestamp < $soonest ) ) {
				$soonest = $timestamp;
			}
		}

		return null === $soonest
			? __( 'never', 'gated-media-access' )
			: date_i18n( (string) get_option( 'date_format' ), $soonest );
	}
}
