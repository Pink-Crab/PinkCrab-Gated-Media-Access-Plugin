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
	 */
	public function render(): void {
		$this->render_delivery();
		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Templates', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Subject and body per email', 'gated-media-access' ); ?></span>
		</div>
		<?php

		$first = true;

		foreach ( Notification_Sender::types() as $type => $label ) {
			$this->render_panel( $type, $label, $first );
			$first = false;
		}
	}

	/**
	 * The delivery card: admin copies and the warning lead time.
	 */
	private function render_delivery(): void {
		$stored = get_option( Settings::OPTION );
		$stored = is_array( $stored ) ? $stored : array();
		$option = Settings::OPTION;
		?>
		<div class="gatedmedia-admin-section-head">
			<h2><?php esc_html_e( 'Delivery', 'gated-media-access' ); ?></h2>
			<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Copies and timing', 'gated-media-access' ); ?></span>
		</div>

		<div class="gatedmedia-admin-check">
			<input type="hidden" name="<?php echo esc_attr( $option ); ?>[admin_copy]" value="0" />
			<input type="checkbox" id="gatedmedia_admin_copy" name="<?php echo esc_attr( $option ); ?>[admin_copy]" value="1" <?php checked( '1', (string) ( $stored['admin_copy'] ?? '0' ) ); ?> />
			<label class="gatedmedia-admin-caps" for="gatedmedia_admin_copy"><?php esc_html_e( 'Send admin copies', 'gated-media-access' ); ?></label>
		</div>
		<p class="gatedmedia-admin-help"><?php esc_html_e( 'A copy of every enabled notification.', 'gated-media-access' ); ?></p>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_admin_copy_address"><?php esc_html_e( 'Admin copy address', 'gated-media-access' ); ?></label>
			<input type="email" class="regular-text" id="gatedmedia_admin_copy_address" name="<?php echo esc_attr( $option ); ?>[admin_copy_address]" value="<?php echo esc_attr( (string) ( $stored['admin_copy_address'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
		</div>

		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_expiry_warning_days"><?php esc_html_e( 'Expiry warning lead time', 'gated-media-access' ); ?></label>
			<span class="gatedmedia-admin-inline">
				<input type="number" min="1" step="1" style="width: 90px;" id="gatedmedia_expiry_warning_days" name="<?php echo esc_attr( $option ); ?>[expiry_warning_days]" value="<?php echo esc_attr( (string) $this->settings->expiry_warning_days() ); ?>" />
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'days', 'gated-media-access' ); ?></span>
			</span>
			<p class="gatedmedia-admin-help"><?php esc_html_e( 'How many days before timed access lapses the warning is sent.', 'gated-media-access' ); ?></p>
		</div>
		<?php
	}

	/**
	 * One notification's panel: switch, subject, body, tokens.
	 *
	 * @param string $type  The notification type key.
	 * @param string $label Its human name.
	 * @param bool   $open  Whether the panel starts unfolded.
	 */
	private function render_panel( string $type, string $label, bool $open ): void {
		$option   = Settings::OPTION;
		$stored   = $this->settings->notification_template( $type );
		$fallback = Notification_Sender::default_template( $type );
		$subject  = '' !== $stored['subject'] ? $stored['subject'] : $fallback['subject'];
		$body     = '' !== $stored['body'] ? $stored['body'] : $fallback['body'];
		?>
		<details class="gatedmedia-admin-panel" <?php echo $open ? 'open' : ''; ?>>
			<summary>
				<span class="gatedmedia-admin-panel-title"><?php echo esc_html( $label ); ?></span>
				<label class="gatedmedia-admin-caps">
					<input type="hidden" name="<?php echo esc_attr( $option ); ?>[notify_<?php echo esc_attr( $type ); ?>]" value="0" />
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notify_<?php echo esc_attr( $type ); ?>]" value="1" <?php checked( true, $this->settings->notification_enabled( $type ) ); ?> />
					<?php esc_html_e( 'Enabled', 'gated-media-access' ); ?>
				</label>
			</summary>
			<div class="gatedmedia-admin-panel-body">
				<div class="gatedmedia-admin-field">
					<label class="gatedmedia-admin-caps" for="gatedmedia_template_<?php echo esc_attr( $type ); ?>_subject"><?php esc_html_e( 'Subject', 'gated-media-access' ); ?></label>
					<input type="text" class="large-text" id="gatedmedia_template_<?php echo esc_attr( $type ); ?>_subject" name="<?php echo esc_attr( $option ); ?>[template_<?php echo esc_attr( $type ); ?>_subject]" value="<?php echo esc_attr( $subject ); ?>" />
				</div>
				<div class="gatedmedia-admin-field">
					<label class="gatedmedia-admin-caps" for="gatedmedia_template_<?php echo esc_attr( $type ); ?>_body"><?php esc_html_e( 'Body', 'gated-media-access' ); ?></label>
					<textarea rows="8" id="gatedmedia_template_<?php echo esc_attr( $type ); ?>_body" name="<?php echo esc_attr( $option ); ?>[template_<?php echo esc_attr( $type ); ?>_body]"><?php echo esc_textarea( $body ); ?></textarea>
				</div>
				<div class="gatedmedia-admin-tokens">
					<?php foreach ( Notification_Sender::TOKENS as $token ) : ?>
						<code><?php echo esc_html( $token ); ?></code>
					<?php endforeach; ?>
				</div>
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'Tokens are replaced when the email is sent.', 'gated-media-access' ); ?></p>
			</div>
		</details>
		<?php
	}
}
