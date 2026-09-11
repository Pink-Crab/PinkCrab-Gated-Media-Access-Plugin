<?php
/**
 * The Payments list, and nothing else: no form, no actions, no buttons.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{table: \PinkCrab\Gated_Access\Admin\Payments_List_Table} $data
 */

?>
<div class="wrap">
	<h1><?php esc_html_e( 'Payments', 'gated-media-access' ); ?></h1>
	<?php $data['table']->display(); ?>
</div>
