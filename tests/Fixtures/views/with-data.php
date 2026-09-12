<?php
/**
 * Fixture: reads the data array.
 *
 * @package PinkCrab\Gated_Access\Tests
 *
 * @var array<string, mixed> $data
 */

$title = isset( $data['title'] ) && is_string( $data['title'] ) ? $data['title'] : 'none';
$count = isset( $data['count'] ) ? (int) $data['count'] : 0;

?>
<h2><?php echo esc_html( $title ); ?></h2>
<span class="count"><?php echo esc_html( (string) $count ); ?></span>
