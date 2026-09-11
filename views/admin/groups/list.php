<?php
/**
 * Every group: the create form, then one panel each.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{form_url: string, create_action: string, groups: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'New group', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'A name is all it needs', 'gated-media-access' ); ?></span>
</div>

<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( $data['create_action'] ); ?>" />
	<?php wp_nonce_field( $data['create_action'] ); ?>

	<div class="gatedmedia-admin-field">
		<label class="gatedmedia-admin-caps" for="gatedmedia_group_name"><?php esc_html_e( 'Name', 'gated-media-access' ); ?></label>
		<span class="gatedmedia-admin-inline">
			<input type="text" class="regular-text" id="gatedmedia_group_name" name="group_name" required />
			<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Create group', 'gated-media-access' ); ?></button>
		</span>
		<p class="gatedmedia-admin-help"><?php esc_html_e( 'Content joins a group from the item’s own Access panel.', 'gated-media-access' ); ?></p>
	</div>
</form>

<div class="gatedmedia-admin-section-head">
	<h2><?php esc_html_e( 'Every group', 'gated-media-access' ); ?></h2>
	<span class="gatedmedia-admin-caps"><?php esc_html_e( 'What it holds, and who holds it', 'gated-media-access' ); ?></span>
</div>

<?php if ( array() === $data['groups'] ) : ?>
	<p class="gatedmedia-admin-help"><?php esc_html_e( 'No groups yet.', 'gated-media-access' ); ?></p>
<?php endif; ?>

<?php foreach ( $data['groups'] as $group ) : ?>
	<?php View::render( 'admin/groups/panel', $group ); ?>
<?php endforeach; ?>
