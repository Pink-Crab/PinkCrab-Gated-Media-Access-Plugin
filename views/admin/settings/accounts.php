<?php
/**
 * The Accounts section of the General tab.
 *
 * Core's `users_can_register` is deliberately absent: `account_creation` governs this plugin's own sign-up, and wp-login.php keeps whatever policy the site already gave it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{option: string, creation: string, creation_options: array<string, string>, auth_pages: string, auth_options: array<string, string>, account_route: bool, profile_prompt: bool, account_url: string} $data
 */

?>
<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Accounts', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'How people get one, and where it lives', 'gated-media-access' ); ?></span>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_account_creation"><?php esc_html_e( 'How a user gets an account', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[account_creation]" id="gatedmedia_account_creation">
		<?php foreach ( $data['creation_options'] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $data['creation'] ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Only the first draws a sign-up form. This governs this plugin alone, and WordPress’ own registration setting is left exactly as you set it.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_auth_pages"><?php esc_html_e( 'Signing in and up', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[auth_pages]" id="gatedmedia_auth_pages">
		<?php foreach ( $data['auth_options'] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $data['auth_pages'] ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'The WordPress screen carries whatever your captcha or two-factor plugin puts on it, and its own styling. Sign-up there needs WordPress’ own registration setting on, and it emails a password rather than signing the buyer straight in.', 'gated-media-access' ); ?></p>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_account_route"><?php esc_html_e( 'Account pages', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[account_route]" id="gatedmedia_account_route">
		<option value="1" <?php selected( true, $data['account_route'] ); ?>><?php esc_html_e( 'Use the plugin’s own pages', 'gated-media-access' ); ?></option>
		<option value="0" <?php selected( false, $data['account_route'] ); ?>><?php esc_html_e( 'I will place the blocks on my own pages', 'gated-media-access' ); ?></option>
	</select>
	<p class="gatedmedia-admin-help">
		<?php
		printf(
			/* translators: %s: the account area's URL. */
			esc_html__( 'Switched on, the account area answers at %s. Switched off it does not, and the same blocks can be placed on pages of your own.', 'gated-media-access' ),
			'<code>' . esc_html( $data['account_url'] ) . '</code>'
		);
		?>
	</p>
</div>

<div class="gatedmedia-admin-field">
	<label class="gatedmedia-admin-caps" for="gatedmedia_profile_prompt"><?php esc_html_e( 'Ask for missing details', 'gated-media-access' ); ?></label>
	<select name="<?php echo esc_attr( $data['option'] ); ?>[profile_prompt]" id="gatedmedia_profile_prompt">
		<option value="0" <?php selected( false, $data['profile_prompt'] ); ?>><?php esc_html_e( 'No', 'gated-media-access' ); ?></option>
		<option value="1" <?php selected( true, $data['profile_prompt'] ); ?>><?php esc_html_e( 'On their first sign-in', 'gated-media-access' ); ?></option>
	</select>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'Someone whose profile is missing a required field is asked to complete it. Never when they were part-way through buying something.', 'gated-media-access' ); ?></p>
</div>
