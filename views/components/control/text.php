<?php
/**
 * A text control. `input_type` covers the near-identical ones: email, date, datetime-local.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, value?: string, input_type?: string, class?: string, placeholder?: string, autocomplete?: string, required?: bool} $data
 */

?>
<input
	type="<?php echo esc_attr( (string) ( $data['input_type'] ?? 'text' ) ); ?>"
	name="<?php echo esc_attr( (string) $data['name'] ); ?>"
	id="<?php echo esc_attr( (string) $data['id'] ); ?>"
	value="<?php echo esc_attr( (string) ( $data['value'] ?? '' ) ); ?>"
	class="<?php echo esc_attr( (string) ( $data['class'] ?? 'regular-text' ) ); ?>"
	<?php if ( '' !== (string) ( $data['placeholder'] ?? '' ) ) : ?>
		placeholder="<?php echo esc_attr( (string) $data['placeholder'] ); ?>"
	<?php endif; ?>
	autocomplete="<?php echo esc_attr( (string) ( $data['autocomplete'] ?? 'off' ) ); ?>"
	<?php echo (bool) ( $data['required'] ?? false ) ? 'required' : ''; ?>
/>
