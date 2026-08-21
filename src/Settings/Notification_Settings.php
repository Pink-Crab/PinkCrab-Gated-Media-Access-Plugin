<?php
/**
 * Reading the notification settings.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

/**
 * The notification half of the one `gatedmedia_settings` option — split from
 * `Settings` for size (phpmd's ten-public-methods ceiling), not principle:
 * same option, same read-validate-filter shape on every accessor.
 */
class Notification_Settings {

	/**
	 * Whether one notification type sends at all. On unless switched off;
	 * filter `gatedmedia_notification_enabled` has the last word.
	 *
	 * @param string $type The notification type key.
	 */
	public function notification_enabled( string $type ): bool {
		$settings = get_option( Settings::OPTION );
		$enabled  = ! is_array( $settings ) || '0' !== ( $settings[ "notify_{$type}" ] ?? '1' );

		/**
		 * Filters whether one notification type sends.
		 *
		 * @param bool   $enabled The stored switch.
		 * @param string $type    The notification type key.
		 */
		return (bool) apply_filters( 'gatedmedia_notification_enabled', $enabled, $type );
	}

	/**
	 * A stored template override for one notification type — subject and
	 * body, each '' where the shipped default should be used. Filter
	 * `gatedmedia_notification_template` has the last word.
	 *
	 * @param string $type The notification type key.
	 * @return array{subject: string, body: string}
	 */
	public function notification_template( string $type ): array {
		$settings = get_option( Settings::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$template = array(
			'subject' => (string) ( $settings[ "template_{$type}_subject" ] ?? '' ),
			'body'    => (string) ( $settings[ "template_{$type}_body" ] ?? '' ),
		);

		/**
		 * Filters one notification type's stored template override.
		 *
		 * @param array{subject: string, body: string} $template The stored override, '' parts meaning the default.
		 * @param string                               $type     The notification type key.
		 */
		$template = (array) apply_filters( 'gatedmedia_notification_template', $template, $type );

		return array(
			'subject' => (string) ( $template['subject'] ?? '' ),
			'body'    => (string) ( $template['body'] ?? '' ),
		);
	}

	/**
	 * How many days before a timed record lapses the warning is sent.
	 * Default 7, never below 1; filter `gatedmedia_expiry_warning_days`
	 * has the last word (spec §9).
	 */
	public function expiry_warning_days(): int {
		$settings = get_option( Settings::OPTION );
		$days     = is_array( $settings ) && isset( $settings['expiry_warning_days'] ) ? (int) $settings['expiry_warning_days'] : 7;

		/**
		 * Filters the expiry warning lead time.
		 *
		 * @param int $days Days before expiry the warning is sent.
		 */
		$days = (int) apply_filters( 'gatedmedia_expiry_warning_days', $days );

		return max( 1, $days );
	}

	/**
	 * The address every enabled notification is copied to, or '' when the
	 * admin-copy switch is off. Defaults to the site admin email once
	 * switched on; filter `gatedmedia_notification_admin_copy` has the
	 * last word.
	 */
	public function admin_copy_address(): string {
		$settings = get_option( Settings::OPTION );
		$settings = is_array( $settings ) ? $settings : array();

		$address = '';

		if ( '1' === ( $settings['admin_copy'] ?? '0' ) ) {
			$stored  = sanitize_email( (string) ( $settings['admin_copy_address'] ?? '' ) );
			$address = '' === $stored ? (string) get_option( 'admin_email' ) : $stored;
		}

		/**
		 * Filters the admin-copy address, '' meaning no copy.
		 *
		 * @param string $address Where copies go.
		 */
		return (string) apply_filters( 'gatedmedia_notification_admin_copy', $address );
	}
}
