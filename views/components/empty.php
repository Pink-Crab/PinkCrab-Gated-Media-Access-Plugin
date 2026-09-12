<?php
/**
 * What stands where a list would be when there is nothing in it.
 *
 * Never a dead end: the message says what would fill it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{message: string} $data
 */

?>
<p class="gatedmedia-admin-help"><?php echo esc_html( (string) $data['message'] ); ?></p>
