<?php
/**
 * The Add Access form.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{action: string, form_url: string, prefill: array{type: string, post_id: string, post_title: string, file_id: string, file_title: string}, pickers: array<string, \PinkCrab\Gated_Access\Admin\Pickers\Picker>} $data
 */

$prefill = $data['prefill'];
$pickers = $data['pickers'];

?>
<div class="wrap">
	<form method="post" action="<?php echo esc_url( $data['form_url'] ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $data['action'] ); ?>" />
		<?php wp_nonce_field( $data['action'] ); ?>

		<div class="gatedmedia-admin">
			<header class="gatedmedia-admin-header">
				<div>
					<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Gated Media Access', 'gated-media-access' ); ?></span>
					<h1><?php esc_html_e( 'Add Access', 'gated-media-access' ); ?></h1>
				</div>
				<button type="submit" class="gatedmedia-admin-button"><?php esc_html_e( 'Add Access', 'gated-media-access' ); ?></button>
			</header>

			<div class="gatedmedia-admin-section-head">
				<h2><?php esc_html_e( 'Grant', 'gated-media-access' ); ?></h2>
				<span class="gatedmedia-admin-caps"><?php esc_html_e( 'Person, item and duration', 'gated-media-access' ); ?></span>
			</div>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_user_search"><?php esc_html_e( 'User', 'gated-media-access' ); ?></label>
				<?php $pickers['user']->render(); ?>
			</div>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_item_type"><?php esc_html_e( 'Item type', 'gated-media-access' ); ?></label>
				<select name="gatedmedia_item_type" id="gatedmedia_item_type">
					<option value="group" <?php selected( $prefill['type'], 'group' ); ?>><?php esc_html_e( 'Group', 'gated-media-access' ); ?></option>
					<option value="post" <?php selected( $prefill['type'], 'post' ); ?>><?php esc_html_e( 'Post', 'gated-media-access' ); ?></option>
					<option value="file" <?php selected( $prefill['type'], 'file' ); ?>><?php esc_html_e( 'File', 'gated-media-access' ); ?></option>
				</select>
			</div>

			<div class="gatedmedia-admin-field" data-gatedmedia-row="group">
				<label class="gatedmedia-admin-caps" for="gatedmedia_group_search"><?php esc_html_e( 'Group', 'gated-media-access' ); ?></label>
				<?php $pickers['group']->render(); ?>
			</div>

			<div class="gatedmedia-admin-field" data-gatedmedia-row="post">
				<label class="gatedmedia-admin-caps" for="gatedmedia_post_search"><?php esc_html_e( 'Post', 'gated-media-access' ); ?></label>
				<?php $pickers['post']->render(); ?>
			</div>

			<div class="gatedmedia-admin-field" data-gatedmedia-row="file">
				<label class="gatedmedia-admin-caps" for="gatedmedia_file_search"><?php esc_html_e( 'File', 'gated-media-access' ); ?></label>
				<?php $pickers['file']->render(); ?>
			</div>

			<div class="gatedmedia-admin-field">
				<label class="gatedmedia-admin-caps" for="gatedmedia_duration"><?php esc_html_e( 'Duration (days)', 'gated-media-access' ); ?></label>
				<input type="number" min="1" name="gatedmedia_duration" id="gatedmedia_duration" />
				<p class="gatedmedia-admin-help"><?php esc_html_e( 'Leave empty for lifetime access.', 'gated-media-access' ); ?></p>
			</div>
		</div>
	</form>
</div>
