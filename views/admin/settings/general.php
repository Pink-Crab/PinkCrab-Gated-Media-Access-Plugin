<?php
/**
 * The General tab: accounts, the store, Stripe, revoking, and what uninstalling takes.
 *
 * The uninstall checkbox is inline with the rest rather than a section of its own, which read against the shape of the screen.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{option: string, accounts: \PinkCrab\Gated_Access\Settings\Account_Fields, currencies: array<string, string>, currency: string, product_path: string, home_url: string, stripe_mode: string, mode_test: string, mode_live: string, key_rows: array<int, array{type: string, label: string, name: string, value: string, has_value: bool}>, revoke_behaviour: string, revoke_options: array<string, string>, purge_on_uninstall: bool} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<?php $data['accounts']->render(); ?>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Store', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Currency and address', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_currency"><?php esc_html_e( 'Currency', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[currency]" id="gatedmedia_currency">
		<?php foreach ( $data['currencies'] as $code => $name ) : ?>
			<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $data['currency'] ); ?>><?php echo esc_html( "{$code}: {$name}" ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Every product is priced and sold in this currency.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_product_path"><?php esc_html_e( 'Product URL path', 'gated-media-access' ); ?></label>
	<span class="gatedmedia-admin-inline">
		<code><?php echo esc_html( $data['home_url'] ); ?></code>
		<input type="text" class="regular-text code" name="<?php echo esc_attr( $data['option'] ); ?>[product_path]" id="gatedmedia_product_path" value="<?php echo esc_attr( $data['product_path'] ); ?>" />
		<code>/&lt;uuid&gt;</code>
	</span>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'The only public way to a product is this path plus its UUID, never a slug or an ID.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Stripe', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Mode and keys', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_stripe_mode"><?php esc_html_e( 'Mode', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[stripe_mode]" id="gatedmedia_stripe_mode">
		<option value="<?php echo esc_attr( $data['mode_test'] ); ?>" <?php selected( $data['mode_test'], $data['stripe_mode'] ); ?>><?php esc_html_e( 'Test', 'gated-media-access' ); ?></option>
		<option value="<?php echo esc_attr( $data['mode_live'] ); ?>" <?php selected( $data['mode_live'], $data['stripe_mode'] ); ?>><?php esc_html_e( 'Live', 'gated-media-access' ); ?></option>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Which set of keys checkout and the webhook use.', 'gated-media-access' ); ?></p>
</div>

<?php foreach ( $data['key_rows'] as $row ) : ?>
	<?php
	View::render(
		'admin/settings/key-row',
		array(
			'option' => $data['option'],
			'row'    => $row,
		)
	);
	?>
<?php endforeach; ?>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Access', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Revoke behaviour', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_revoke_behaviour"><?php esc_html_e( 'Revoking access', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[revoke_behaviour]" id="gatedmedia_revoke_behaviour">
		<?php foreach ( $data['revoke_options'] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $data['revoke_behaviour'] ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'What the Revoke action on the Access list does.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Uninstall', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'What deleting the plugin takes', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-check">
	<input type="hidden" name="<?php echo esc_attr( $data['option'] ); ?>[purge_on_uninstall]" value="0" />
	<input type="checkbox" id="gatedmedia_purge_on_uninstall" name="<?php echo esc_attr( $data['option'] ); ?>[purge_on_uninstall]" value="1" <?php checked( true, $data['purge_on_uninstall'] ); ?> />
	<label class="gatedmedia-admin-caps" for="gatedmedia_purge_on_uninstall"><?php esc_html_e( 'Delete all data on uninstall', 'gated-media-access' ); ?></label>
</div>
<p class="gatedmedia-admin-help"><?php esc_html_e( 'Settings, keys and capabilities always go. Tick this and the payments table, the access records, the products and the coupons go too. There is no undo.', 'gated-media-access' ); ?></p>
