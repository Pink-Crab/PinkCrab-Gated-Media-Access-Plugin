<?php
/**
 * What just happened. Core's own notice classes, so it sits where an administrator expects a notice to sit.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{message: string, type?: string, dismissible?: bool} $data
 */

?>
<div class="notice notice-<?php echo esc_attr( (string) ( $data['type'] ?? 'success' ) ); ?><?php echo (bool) ( $data['dismissible'] ?? false ) ? ' is-dismissible' : ''; ?>">
	<p><?php echo esc_html( (string) $data['message'] ); ?></p>
</div>
