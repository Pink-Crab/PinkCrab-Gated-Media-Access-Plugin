<?php
/**
 * The one sender of notification email.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Notifications;

use PinkCrab\Gated_Access\Settings\Settings;

/**
 * Every notification goes out through here (architecture §10, spec §5):
 * type → template (stored override or shipped default) → placeholders →
 * recipients filter → content filter → the sending action → `wp_mail()`.
 *
 * A site can take over entirely: listen on
 * `gatedmedia_notification_sending` and empty the recipients through
 * `gatedmedia_notification_recipients` — with nobody to mail, nothing
 * sends. Nothing here is load-bearing for access.
 */
class Notification_Sender {

	public const TYPE_ACCESS_CREATED    = 'access_created';
	public const TYPE_EXPIRY_WARNING    = 'expiry_warning';
	public const TYPE_INVITE_USER_FREE  = 'invite_user_free';
	public const TYPE_INVITE_USER_PAID  = 'invite_user_paid';
	public const TYPE_INVITE_GUEST_FREE = 'invite_guest_free';
	public const TYPE_INVITE_GUEST_PAID = 'invite_guest_paid';

	/** The placeholder tokens every template may use. */
	public const TOKENS = array( '{name}', '{item}', '{link}', '{expires}', '{site}' );

	/**
	 * The switches and template overrides live in settings.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Every type this plugin sends, label included — the Settings screen
	 * renders this list, so the sender stays the one place types exist.
	 *
	 * @return array<string, string> Type key to human label.
	 */
	public static function types(): array {
		return array(
			self::TYPE_ACCESS_CREATED    => __( 'Access created', 'gated-media-access' ),
			self::TYPE_EXPIRY_WARNING    => __( 'Expiry warning', 'gated-media-access' ),
			self::TYPE_INVITE_USER_FREE  => __( 'Invite — existing user, free product', 'gated-media-access' ),
			self::TYPE_INVITE_USER_PAID  => __( 'Invite — existing user, paid product', 'gated-media-access' ),
			self::TYPE_INVITE_GUEST_FREE => __( 'Invite — new user, free product', 'gated-media-access' ),
			self::TYPE_INVITE_GUEST_PAID => __( 'Invite — new user, paid product', 'gated-media-access' ),
		);
	}

	/**
	 * One type's shipped template — what sends until a site stores its own.
	 *
	 * @param string $type The notification type key.
	 * @return array{subject: string, body: string}
	 */
	public static function default_template( string $type ): array {
		$templates = array(
			self::TYPE_ACCESS_CREATED    => array(
				'subject' => __( 'Your access to {item} is ready', 'gated-media-access' ),
				'body'    => __( "Hi {name},\n\nGreat news! You now have access to: {item}\nYou can view it here: {link}\n\nAccess expires: {expires}\n\n{site}", 'gated-media-access' ),
			),
			self::TYPE_EXPIRY_WARNING    => array(
				'subject' => __( 'Your access to {item} expires soon', 'gated-media-access' ),
				'body'    => __( "Hi {name},\n\nYour access to {item} expires on {expires}.\nYou can view it until then here: {link}\n\n{site}", 'gated-media-access' ),
			),
			self::TYPE_INVITE_USER_FREE  => array(
				'subject' => __( 'You have been given access to {item}', 'gated-media-access' ),
				'body'    => __( "Hi {name},\n\nYou have been given access to: {item}\nSign in and view it here: {link}\n\n{site}", 'gated-media-access' ),
			),
			self::TYPE_INVITE_USER_PAID  => array(
				'subject' => __( 'You are invited to {item}', 'gated-media-access' ),
				'body'    => __( "Hi {name},\n\nYou are invited to purchase access to: {item}\nView it here: {link}\n\n{site}", 'gated-media-access' ),
			),
			self::TYPE_INVITE_GUEST_FREE => array(
				'subject' => __( 'You are invited to {item}', 'gated-media-access' ),
				'body'    => __( "Hi,\n\nYou have been invited to: {item}\nCreate an account to get access here: {link}\n\n{site}", 'gated-media-access' ),
			),
			self::TYPE_INVITE_GUEST_PAID => array(
				'subject' => __( 'You are invited to {item}', 'gated-media-access' ),
				'body'    => __( "Hi,\n\nYou have been invited to: {item}\nCreate an account to purchase access here: {link}\n\n{site}", 'gated-media-access' ),
			),
		);

		return $templates[ $type ] ?? array(
			'subject' => '',
			'body'    => '',
		);
	}

	/**
	 * Sends one notification, honouring the whole contract.
	 *
	 * @param string                $type    One of the TYPE_* keys.
	 * @param int                   $user_id Who it concerns — 0 when no account exists (guest invites).
	 * @param array<string, string> $args    Placeholder values: name, item, link, expires, site.
	 * @param string                $address An explicit address; '' means the holder's.
	 * @return bool Whether `wp_mail()` reported the send.
	 */
	public function send( string $type, int $user_id, array $args = array(), string $address = '' ): bool {
		if ( ! array_key_exists( $type, self::types() ) || ! $this->settings->notification_enabled( $type ) ) {
			return false;
		}

		$recipients = $this->initial_recipients( $user_id, $address );

		/**
		 * Filters who receives one notification. Empty the list to stop the
		 * plugin's own send while still hearing the sending action.
		 *
		 * @param array<int, string> $recipients The addresses to mail.
		 * @param string             $type       The notification type key.
		 * @param int                $user_id    Who it concerns, 0 for none.
		 */
		$recipients = (array) apply_filters( 'gatedmedia_notification_recipients', $recipients, $type, $user_id );

		$content = $this->content( $type, $args, $user_id );

		/**
		 * Filters the subject and body about to send, after placeholders.
		 *
		 * @param array{subject: string, body: string} $content The rendered email.
		 * @param string                               $type    The notification type key.
		 * @param array<string, string>                $args    The placeholder values.
		 */
		$content = (array) apply_filters( 'gatedmedia_notification_content', $content, $type, $args );

		/**
		 * Fires before every send — the take-over point for sites that mail
		 * through their own system instead (spec §5).
		 *
		 * @param string                $type    The notification type key.
		 * @param int                   $user_id Who it concerns, 0 for none.
		 * @param array<string, string> $args    The placeholder values.
		 */
		do_action( 'gatedmedia_notification_sending', $type, $user_id, $args );

		if ( array() === $recipients ) {
			return false;
		}

		return wp_mail(
			array_map( 'strval', $recipients ),
			(string) ( $content['subject'] ?? '' ),
			(string) ( $content['body'] ?? '' )
		);
	}

	/**
	 * Who the send starts addressed to: the named address or the holder's,
	 * plus the admin copy when that switch is on.
	 *
	 * @param int    $user_id The holder, 0 for none.
	 * @param string $address An explicit address, '' for the holder's.
	 * @return array<int, string>
	 */
	private function initial_recipients( int $user_id, string $address ): array {
		$recipients = array();

		if ( '' !== $address ) {
			$recipients[] = $address;
		} elseif ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			if ( false !== $user && '' !== $user->user_email ) {
				$recipients[] = $user->user_email;
			}
		}

		$copy = $this->settings->admin_copy_address();

		if ( '' !== $copy && ! in_array( $copy, $recipients, true ) ) {
			$recipients[] = $copy;
		}

		return $recipients;
	}

	/**
	 * The template — stored override winning over the shipped default —
	 * with every placeholder replaced.
	 *
	 * @param string                $type    The notification type key.
	 * @param array<string, string> $args    The placeholder values.
	 * @param int                   $user_id Fills {name} when none is given.
	 * @return array{subject: string, body: string}
	 */
	private function content( string $type, array $args, int $user_id ): array {
		$stored   = $this->settings->notification_template( $type );
		$fallback = self::default_template( $type );

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		$values = array(
			'{name}'    => (string) ( $args['name'] ?? ( false === $user ? '' : $user->display_name ) ),
			'{item}'    => (string) ( $args['item'] ?? '' ),
			'{link}'    => (string) ( $args['link'] ?? '' ),
			'{expires}' => (string) ( $args['expires'] ?? __( 'never', 'gated-media-access' ) ),
			'{site}'    => (string) ( $args['site'] ?? get_bloginfo( 'name' ) ),
		);

		return array(
			'subject' => strtr( '' !== $stored['subject'] ? $stored['subject'] : $fallback['subject'], $values ),
			'body'    => strtr( '' !== $stored['body'] ? $stored['body'] : $fallback['body'], $values ),
		);
	}
}
