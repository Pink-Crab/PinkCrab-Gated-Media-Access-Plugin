<?php
/**
 * The Settings screen: one form, two tabs, one save.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{group: string, form_url: string, general_url: string, notifications_url: string, on_notifications: bool, general: array<string, mixed>, notifications: \PinkCrab\Gated_Access\Settings\Notification_Fields} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<div class="wrap">
	<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
		<?php settings_fields( $data['group'] ); ?>
		<div class="gatedmedia-admin">
			<header class="gatedmedia-admin-header">
				<div>
					<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
					<h1><?php esc_html_e( 'Settings', 'gated-media-access' ); ?></h1>
				</div>
				<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Save Changes', 'gated-media-access' ); ?></button>
			</header>

			<nav class="gatedmedia-admin-tabs">
				<a class="<?php echo $data['on_notifications'] ? '' : 'is-active'; ?>" href="<?php echo esc_url( $data['general_url'] ); ?>"><?php esc_html_e( 'General', 'gated-media-access' ); ?></a>
				<a class="<?php echo $data['on_notifications'] ? 'is-active' : ''; ?>" href="<?php echo esc_url( $data['notifications_url'] ); ?>"><?php esc_html_e( 'Notifications', 'gated-media-access' ); ?></a>
			</nav>

			<?php
			if ( $data['on_notifications'] ) {
				$data['notifications']->render();
			} else {
				View::render( 'admin/settings/general', $data['general'] );
			}
			?>
		</div>
	</form>
</div>
