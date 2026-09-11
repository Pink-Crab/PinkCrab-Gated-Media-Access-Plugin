<?php
/**
 * One notification's panel: its switch, subject, body and token legend.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{option: string, tokens: array<int, string>, panel: array{type: string, label: string, open: bool, enabled: bool, subject: string, body: string}} $data
 */

$panel = $data['panel'];

?>
<details class="gatedmedia-admin-panel" <?php echo $panel['open'] ? 'open' : ''; ?>>
	<summary>
		<span class="gatedmedia-admin-panel-title"><?php echo esc_html( $panel['label'] ); ?></span>
		<label class="gatedmedia-admin-caps">
			<input type="hidden" name="<?php echo esc_attr( $data['option'] ); ?>[notify_<?php echo esc_attr( $panel['type'] ); ?>]" value="0" />
			<input type="checkbox" name="<?php echo esc_attr( $data['option'] ); ?>[notify_<?php echo esc_attr( $panel['type'] ); ?>]" value="1" <?php checked( true, $panel['enabled'] ); ?> />
			<?php esc_html_e( 'Enabled', 'gated-media-access' ); ?>
		</label>
	</summary>
	<div class="gatedmedia-admin-panel-body">
		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_template_<?php echo esc_attr( $panel['type'] ); ?>_subject"><?php esc_html_e( 'Subject', 'gated-media-access' ); ?></label>
			<input type="text" class="large-text" id="gatedmedia_template_<?php echo esc_attr( $panel['type'] ); ?>_subject" name="<?php echo esc_attr( $data['option'] ); ?>[template_<?php echo esc_attr( $panel['type'] ); ?>_subject]" value="<?php echo esc_attr( $panel['subject'] ); ?>" />
		</div>
		<div class="gatedmedia-admin-field">
			<label class="gatedmedia-admin-caps" for="gatedmedia_template_<?php echo esc_attr( $panel['type'] ); ?>_body"><?php esc_html_e( 'Body', 'gated-media-access' ); ?></label>
			<textarea rows="8" id="gatedmedia_template_<?php echo esc_attr( $panel['type'] ); ?>_body" name="<?php echo esc_attr( $data['option'] ); ?>[template_<?php echo esc_attr( $panel['type'] ); ?>_body]"><?php echo esc_textarea( $panel['body'] ); ?></textarea>
		</div>
		<div class="gatedmedia-admin-tokens">
			<?php foreach ( $data['tokens'] as $token ) : ?>
				<code><?php echo esc_html( $token ); ?></code>
			<?php endforeach; ?>
		</div>
		<p class="gatedmedia-admin-help"><?php esc_html_e( 'Tokens are replaced when the email is sent.', 'gated-media-access' ); ?></p>
	</div>
</details>
