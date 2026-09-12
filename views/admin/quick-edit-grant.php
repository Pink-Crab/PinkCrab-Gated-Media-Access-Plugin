<?php
/**
 * The grant fields inside quick edit.
 *
 * Core's own inline-edit classes, because the box is drawn inside core's quick edit row and has to sit in its grid.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{picker: \PinkCrab\Gated_Access\Admin\Pickers\Picker} $data
 */

?>
<fieldset class="inline-edit-col-right gatedmedia-quick-grant">
	<div class="inline-edit-col">
		<h4><?php esc_html_e( 'Grant access', 'gated-media-access' ); ?></h4>
		<label class="inline-edit-group">
			<span class="title"><?php esc_html_e( 'User', 'gated-media-access' ); ?></span>
			<span class="input-text-wrap"><?php $data['picker']->render(); ?></span>
		</label>
		<label class="inline-edit-group">
			<span class="title"><?php esc_html_e( 'Days', 'gated-media-access' ); ?></span>
			<span class="input-text-wrap"><input type="number" min="1" name="gatedmedia_qe_duration" /></span>
		</label>
		<em class="inline-edit-group"><?php esc_html_e( 'A picked user gains access to this item when the row saves, and empty days means lifetime. Nobody picked, nothing granted.', 'gated-media-access' ); ?></em>
	</div>
</fieldset>
