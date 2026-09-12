<?php
/**
 * A textarea.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, value?: string, rows?: int, class?: string} $data
 */

?>
<textarea
	name="<?php echo esc_attr( (string) $data['name'] ); ?>"
	id="<?php echo esc_attr( (string) $data['id'] ); ?>"
	rows="<?php echo esc_attr( (string) ( $data['rows'] ?? 6 ) ); ?>"
	class="<?php echo esc_attr( (string) ( $data['class'] ?? 'large-text' ) ); ?>"
><?php echo esc_textarea( (string) ( $data['value'] ?? '' ) ); ?></textarea>
