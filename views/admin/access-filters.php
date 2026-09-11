<?php
/**
 * The Access list's toolbar filters: holder, item type, item, source.
 *
 * Each label is read by assistive technology only, as core's own toolbar does: the meaning is carried by the first option, so a visible label would say it twice, and a placeholder is not a label because it goes as soon as anything is typed.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{holder_picker: \PinkCrab\Gated_Access\Admin\Pickers\Picker, item_type: string, item_types: array<string, string>, item: string, item_label: string, item_endpoint: string, source: string, sources: array<int, string>} $data
 */

?>
<label class="screen-reader-text" for="gatedmedia_filter_holder_search"><?php esc_html_e( 'Filter by holder', 'gated-media-access' ); ?></label>
<?php $data['holder_picker']->render(); ?>

<label class="screen-reader-text" for="gatedmedia_filter_item_type"><?php esc_html_e( 'Filter by item type', 'gated-media-access' ); ?></label>
<select name="gatedmedia_item_type" id="gatedmedia_filter_item_type">
	<option value=""><?php esc_html_e( 'All item types', 'gated-media-access' ); ?></option>
	<?php foreach ( $data['item_types'] as $value => $label ) : ?>
		<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $data['item_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
	<?php endforeach; ?>
</select>

<label class="screen-reader-text" for="gatedmedia_filter_item_search"><?php esc_html_e( 'Filter by item', 'gated-media-access' ); ?></label>
<input type="text" class="gatedmedia-picker" id="gatedmedia_filter_item_search" data-gatedmedia-picker="<?php echo esc_attr( $data['item_endpoint'] ); ?>" data-gatedmedia-target="gatedmedia_filter_item" value="<?php echo esc_attr( $data['item_label'] ); ?>" placeholder="<?php esc_attr_e( 'Any item…', 'gated-media-access' ); ?>" autocomplete="off" />
<input type="hidden" name="gatedmedia_item" id="gatedmedia_filter_item" value="<?php echo esc_attr( $data['item'] ); ?>" />

<label class="screen-reader-text" for="gatedmedia_filter_source"><?php esc_html_e( 'Filter by source', 'gated-media-access' ); ?></label>
<select name="gatedmedia_source" id="gatedmedia_filter_source">
	<option value=""><?php esc_html_e( 'All sources', 'gated-media-access' ); ?></option>
	<?php foreach ( $data['sources'] as $known ) : ?>
		<option value="<?php echo esc_attr( $known ); ?>" <?php selected( $data['source'], $known ); ?>><?php echo esc_html( $known ); ?></option>
	<?php endforeach; ?>
</select>
