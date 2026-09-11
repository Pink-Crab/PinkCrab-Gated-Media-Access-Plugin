<?php
/**
 * The per-item Access metabox: who holds this item, a staged grant, and the groups it sits in.
 *
 * Links and fields, never a form: a metabox lives inside the editor's own form, and a form in a form posts the wrong one.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{holders: array<int, array{name: string, expires: string, revoke_url: string}>, groups: array<int, array{name: string, remove_url: string}>, user_picker: \PinkCrab\Gated_Access\Admin\Pickers\Picker, group_picker: \PinkCrab\Gated_Access\Admin\Pickers\Picker, user_field_id: string, days_field_id: string, days_field_name: string, group_field_id: string, nonce_action: string, nonce_name: string} $data
 */

?>
<?php if ( array() === $data['holders'] ) : ?>
	<p><?php esc_html_e( 'Nobody holds direct access to this item.', 'gated-media-access' ); ?></p>
<?php else : ?>
	<ul>
		<?php foreach ( $data['holders'] as $holder ) : ?>
			<li>
				<?php echo esc_html( $holder['name'] ); ?>
				<span class="description">(<?php echo esc_html( $holder['expires'] ); ?>)</span>
				<a href="<?php echo esc_url( $holder['revoke_url'] ); ?>"><?php esc_html_e( 'Revoke', 'gated-media-access' ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<div class="gatedmedia-inline-grant">
	<label class="screen-reader-text" for="<?php echo esc_attr( $data['user_field_id'] ); ?>"><?php esc_html_e( 'User to give access to', 'gated-media-access' ); ?></label>
	<?php $data['user_picker']->render(); ?>

	<label class="screen-reader-text" for="<?php echo esc_attr( $data['days_field_id'] ); ?>"><?php esc_html_e( 'Days of access, or empty for lifetime', 'gated-media-access' ); ?></label>
	<input type="number" min="1" id="<?php echo esc_attr( $data['days_field_id'] ); ?>" name="<?php echo esc_attr( $data['days_field_name'] ); ?>" placeholder="<?php esc_attr_e( 'Days', 'gated-media-access' ); ?>" class="gatedmedia-inline-grant-days" />
	<p class="description"><?php esc_html_e( 'Empty days means lifetime. Access is given when you save.', 'gated-media-access' ); ?></p>

	<?php wp_nonce_field( $data['nonce_action'], $data['nonce_name'] ); ?>
</div>

<hr />
<p><strong><?php esc_html_e( 'Groups', 'gated-media-access' ); ?></strong></p>

<?php if ( array() === $data['groups'] ) : ?>
	<p><?php esc_html_e( 'This item is in no group.', 'gated-media-access' ); ?></p>
<?php else : ?>
	<ul>
		<?php foreach ( $data['groups'] as $group ) : ?>
			<li>
				<?php echo esc_html( $group['name'] ); ?>
				<a href="<?php echo esc_url( $group['remove_url'] ); ?>"><?php esc_html_e( 'Remove', 'gated-media-access' ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<label class="screen-reader-text" for="<?php echo esc_attr( $data['group_field_id'] ); ?>_search"><?php esc_html_e( 'Group to add this item to', 'gated-media-access' ); ?></label>
<?php $data['group_picker']->render(); ?>
<p class="description"><?php esc_html_e( 'The item joins the group when you save.', 'gated-media-access' ); ?></p>
