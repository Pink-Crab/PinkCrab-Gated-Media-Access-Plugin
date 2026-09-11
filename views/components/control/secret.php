<?php
/**
 * A secret. Always empty, marked when one is stored, and an empty submit keeps what is stored.
 *
 * The stored value never travels back to the browser, so `value` is read only for whether there is one.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, has_value?: bool} $data
 */

?>
<input
	type="password"
	name="<?php echo esc_attr( (string) $data['name'] ); ?>"
	id="<?php echo esc_attr( (string) $data['id'] ); ?>"
	value=""
	class="large-text code"
	autocomplete="new-password"
	placeholder="<?php echo esc_attr( (bool) ( $data['has_value'] ?? false ) ? __( 'saved, leave empty to keep', 'gated-media-access' ) : '' ); ?>"
/>
