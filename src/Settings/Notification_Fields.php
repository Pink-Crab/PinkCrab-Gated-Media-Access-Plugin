<?php
/**
 * The notification fields of the Settings screen.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Settings;

use PinkCrab\Gated_Access\Notifications\Notification_Sender;

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
	 *
	 * @return array{delivery: array<int, array<string, mixed>>, panels: array<int, array<string, mixed>>}
	 */
	public function tab(): array {
		return array(
			'delivery' => $this->delivery(),
			'panels'   => $this->panels(),
		);
	}

	/**
	 * The delivery fields: admin copies, where they go, and how early the expiry warning is sent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function delivery(): array {
		$stored = get_option( Settings::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$option = Settings::OPTION;

		return array(
			array(
				'type'    => 'checkbox',
				'name'    => $option . '[admin_copy]',
				'id'      => 'gatedmedia_admin_copy',
				'label'   => __( 'Send admin copies', 'gated-media-access' ),
				'checked' => '1' === (string) ( $stored['admin_copy'] ?? '0' ),
				'help'    => __( 'A copy of every enabled notification.', 'gated-media-access' ),
			),
			array(
				'type'        => 'text',
				'input_type'  => 'email',
				'name'        => $option . '[admin_copy_address]',
				'id'          => 'gatedmedia_admin_copy_address',
				'label'       => __( 'Admin copy address', 'gated-media-access' ),
				'value'       => (string) ( $stored['admin_copy_address'] ?? '' ),
				'placeholder' => (string) get_option( 'admin_email' ),
			),
			array(
				'type'   => 'number',
				'name'   => $option . '[expiry_warning_days]',
				'id'     => 'gatedmedia_expiry_warning_days',
				'label'  => __( 'Expiry warning lead time', 'gated-media-access' ),
				'value'  => (string) $this->settings->expiry_warning_days(),
				'suffix' => __( 'days', 'gated-media-access' ),
				'help'   => __( 'How many days before timed access lapses the warning is sent.', 'gated-media-access' ),
			),
		);
	}

	/**
	 * One panel per notification type, the first unfolded.
	 *
	 * A template that has never been edited shows the shipped wording rather than an empty box.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function panels(): array {
		$option = Settings::OPTION;
		$panels = array();

		foreach ( Notification_Sender::types() as $type => $label ) {
			$stored   = $this->settings->notification_template( $type );
			$fallback = Notification_Sender::default_template( $type );

			$panels[] = array(
				'title'       => $label,
				'open'        => array() === $panels,
				'enabled'     => $this->settings->notification_enabled( $type ),
				'switch_name' => $option . '[notify_' . $type . ']',
				'tokens'      => Notification_Sender::TOKENS,
				'fields'      => array(
					array(
						'type'  => 'text',
						'name'  => $option . '[template_' . $type . '_subject]',
						'id'    => 'gatedmedia_template_' . $type . '_subject',
						'label' => __( 'Subject', 'gated-media-access' ),
						'value' => '' !== $stored['subject'] ? $stored['subject'] : $fallback['subject'],
						'class' => 'large-text',
					),
					array(
						'type'  => 'textarea',
						'name'  => $option . '[template_' . $type . '_body]',
						'id'    => 'gatedmedia_template_' . $type . '_body',
						'label' => __( 'Body', 'gated-media-access' ),
						'value' => '' !== $stored['body'] ? $stored['body'] : $fallback['body'],
						'rows'  => 8,
					),
				),
			);
		}

		return $panels;
	}
}
