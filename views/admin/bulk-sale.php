<?php
/**
 * The sale fields inside the products list's bulk edit.
 *
 * Core's own inline-edit classes, because the box is drawn inside core's bulk edit row and has to sit in its grid.
 *
 * @package PinkCrab\Gated_Access
 */

use PinkCrab\Gated_Access\Admin\Product_Sale_Bulk;

?>
<fieldset class="inline-edit-col-right gatedmedia-bulk-sale">
	<div class="inline-edit-col">
		<h4><?php esc_html_e( 'Sale', 'gated-media-access' ); ?></h4>
		<label class="inline-edit-group">
			<span class="title"><?php esc_html_e( 'Change', 'gated-media-access' ); ?></span>
			<select name="<?php echo esc_attr( Product_Sale_Bulk::FIELD_MODE ); ?>">
				<option value=""><?php esc_html_e( 'No change', 'gated-media-access' ); ?></option>
				<option value="<?php echo esc_attr( Product_Sale_Bulk::MODE_PERCENT ); ?>"><?php esc_html_e( 'Percentage off', 'gated-media-access' ); ?></option>
				<option value="<?php echo esc_attr( Product_Sale_Bulk::MODE_AMOUNT ); ?>"><?php esc_html_e( 'Amount off', 'gated-media-access' ); ?></option>
				<option value="<?php echo esc_attr( Product_Sale_Bulk::MODE_END ); ?>"><?php esc_html_e( 'End sale', 'gated-media-access' ); ?></option>
			</select>
		</label>
		<label class="inline-edit-group">
			<span class="title"><?php esc_html_e( 'Off', 'gated-media-access' ); ?></span>
			<span class="input-text-wrap"><input type="number" min="0" step="any" name="<?php echo esc_attr( Product_Sale_Bulk::FIELD_VALUE ); ?>" /></span>
		</label>
		<em class="inline-edit-group"><?php esc_html_e( 'A whole percentage, or an amount such as 2.50, taken off each product\'s own price. End sale puts them back to full price. Free products, and anything that would take a price to nothing, are left alone.', 'gated-media-access' ); ?></em>
	</div>
</fieldset>
