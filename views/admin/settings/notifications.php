<?php
/**
 * The Notifications tab: delivery, then one template panel per notification.
 *
 * Each switch prints a hidden '0' before its checkbox, so unticking really stores the off.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{option: string, admin_copy: bool, admin_copy_address: string, admin_email: string, expiry_warning_days: int, tokens: array<int, string>, panels: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Delivery', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Copies and timing', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-check">
	<input type="hidden" name="<?php echo esc_attr( $data['option'] ); ?>[admin_copy]" value="0" />
	<input type="checkbox" id="gatedmedia_admin_copy" name="<?php echo esc_attr( $data['option'] ); ?>[admin_copy]" value="1" <?php checked( true, $data['admin_copy'] ); ?> />
	<label class="gatedmedia-admin-caps" for="gatedmedia_admin_copy"><?php esc_html_e( 'Send admin copies', 'gated-media-access' ); ?></label>
</div>
<p class="gatedmedia-admin-help"><?php esc_html_e( 'A copy of every enabled notification.', 'gated-media-access' ); ?></p>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_admin_copy_address"><?php esc_html_e( 'Admin copy address', 'gated-media-access' ); ?></label>
	<input type="email" class="regular-text" id="gatedmedia_admin_copy_address" name="<?php echo esc_attr( $data['option'] ); ?>[admin_copy_address]" value="<?php echo esc_attr( $data['admin_copy_address'] ); ?>" placeholder="<?php echo esc_attr( $data['admin_email'] ); ?>" />
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_expiry_warning_days"><?php esc_html_e( 'Expiry warning lead time', 'gated-media-access' ); ?></label>
	<span class="gatedmedia-admin-inline">
		<input type="number" min="1" step="1" class="gatedmedia-admin-days" id="gatedmedia_expiry_warning_days" name="<?php echo esc_attr( $data['option'] ); ?>[expiry_warning_days]" value="<?php echo esc_attr( (string) $data['expiry_warning_days'] ); ?>" />
		<span class="gatedmedia-admin-caps"><?php esc_html_e( 'days', 'gated-media-access' ); ?></span>
	</span>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'How many days before timed access lapses the warning is sent.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Templates', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Subject and body per email', 'gated-media-access' ); ?></span>
</div>

<?php foreach ( $data['panels'] as $panel ) : ?>
	<?php
	View::render(
		'admin/settings/notification-panel',
		array(
			'option' => $data['option'],
			'panel'  => $panel,
			'tokens' => $data['tokens'],
		)
	);
	?>
<?php endforeach; ?>
