<?php
/**
 * The notification fields of the Settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Gated_Access\Notifications\Notification_Sender;
use PinkCrab\Gated_Access\Support\View;

/**
 * The Notifications tab's body: the delivery settings, admin copies and the expiry warning lead time, plus a template panel per notification type with its switch, subject, body and token legend.
 *
 * `Settings_Page` owns the page, the form and the tabs, and this renders the fields inside them, each switch printing a hidden '0' before its checkbox so unticking really stores the off.
 */
class Notification_Fields {

	/**
	 * Reads back what the form displays.
	 *
	 * @param Settings $settings The one settings reader.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The whole tab: delivery, then one panel per notification type.
	 */
	public function render(): void {
		$stored = get_option( Settings::OPTION );
		$stored = is_array( $stored ) ? $stored : array();

		View::render(
			'admin/settings/notifications',
			array(
				'option'              => Settings::OPTION,
				'admin_copy'          => '1' === (string) ( $stored['admin_copy'] ?? '0' ),
				'admin_copy_address'  => (string) ( $stored['admin_copy_address'] ?? '' ),
				'admin_email'         => (string) get_option( 'admin_email' ),
				'expiry_warning_days' => $this->settings->expiry_warning_days(),
				'tokens'              => Notification_Sender::TOKENS,
				'panels'              => $this->panels(),
			)
		);
	}

	/**
	 * One panel per notification type, the first unfolded.
	 *
	 * A template that has never been edited shows the shipped wording rather than an empty box.
	 *
	 * @return array<int, array{type: string, label: string, open: bool, enabled: bool, subject: string, body: string}>
	 */
	private function panels(): array {
		$panels = array();

		foreach ( array_keys( Notification_Sender::types() ) as $index => $type ) {
			$stored   = $this->settings->notification_template( $type );
			$fallback = Notification_Sender::default_template( $type );

			$panels[] = array(
				'type'    => $type,
				'label'   => Notification_Sender::types()[ $type ],
				'open'    => 0 === $index,
				'enabled' => $this->settings->notification_enabled( $type ),
				'subject' => '' !== $stored['subject'] ? $stored['subject'] : $fallback['subject'],
				'body'    => '' !== $stored['body'] ? $stored['body'] : $fallback['body'],
			);
		}

		return $panels;
	}
}
