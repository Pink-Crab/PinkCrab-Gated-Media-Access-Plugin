<?php
/**
 * The coupon box: what it takes off, and the three limits on using it.
 *
 * Core's form table, because this box sits inside the post editor rather than on a screen of the plugin's own.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{is_percent: bool, value: string, usage_limit: string, per_user_limit: string, expires: string} $data
 */

?>
<p class="description"><?php esc_html_e( 'The code is the title. It must be unique, and WordPress keeps it so.', 'gated-media-access' ); ?></p>
<table class="form-table" role="presentation">
	<tr>
		<th scope="row"><label for="gatedmedia_discount_type"><?php esc_html_e( 'Discount', 'gated-media-access' ); ?></label></th>
		<td>
			<select name="gatedmedia_discount_type" id="gatedmedia_discount_type">
				<option value="percent" <?php selected( $data['is_percent'] ); ?>><?php esc_html_e( 'Percent off', 'gated-media-access' ); ?></option>
				<option value="fixed" <?php selected( ! $data['is_percent'] ); ?>><?php esc_html_e( 'Fixed amount off', 'gated-media-access' ); ?></option>
			</select>
			<input type="number" step="any" min="0" class="small-text" name="gatedmedia_discount_value" id="gatedmedia_discount_value" value="<?php echo esc_attr( $data['value'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Whole percent (100 is free), or the amount taken off the price.', 'gated-media-access' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="gatedmedia_usage_limit"><?php esc_html_e( 'Usage limit', 'gated-media-access' ); ?></label></th>
		<td>
			<input type="number" min="1" step="1" class="small-text" name="gatedmedia_usage_limit" id="gatedmedia_usage_limit" value="<?php echo esc_attr( $data['usage_limit'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Completed payments in total. Empty for unlimited.', 'gated-media-access' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="gatedmedia_per_user_limit"><?php esc_html_e( 'Per-user limit', 'gated-media-access' ); ?></label></th>
		<td>
			<input type="number" min="1" step="1" class="small-text" name="gatedmedia_per_user_limit" id="gatedmedia_per_user_limit" value="<?php echo esc_attr( $data['per_user_limit'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Completed payments per person. Empty for unlimited.', 'gated-media-access' ); ?></p>
		</td>
	</tr>
	<tr>
		<th scope="row"><label for="gatedmedia_coupon_expires"><?php esc_html_e( 'Expires', 'gated-media-access' ); ?></label></th>
		<td>
			<input type="date" name="gatedmedia_coupon_expires" id="gatedmedia_coupon_expires" value="<?php echo esc_attr( $data['expires'] ); ?>" />
			<p class="description"><?php esc_html_e( 'Usable through this day (UTC). Empty for never.', 'gated-media-access' ); ?></p>
		</td>
	</tr>
</table>
