<?php
/**
 * The coupon box: what it takes off, and the three limits on using it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{fields: array<int, array<string, mixed>>} $data
 */

use PinkCrab\Gated_Access\Support\View;

?>
<p class="description"><?php esc_html_e( 'The code is the title. It must be unique, and WordPress keeps it so.', 'gated-media-access' ); ?></p>

<?php View::render( 'components/fields', array( 'fields' => $data['fields'] ) ); ?>
