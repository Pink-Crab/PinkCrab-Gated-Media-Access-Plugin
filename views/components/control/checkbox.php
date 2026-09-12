<?php
/**
 * A checkbox, with the hidden zero before it so unticking really stores the off.
 *
 * It carries its own label beside the box rather than above it, which is why `components/fields.php` draws it outside the labelled row.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, label: string, checked?: bool} $data
 */

?>
<div class="gatedmedia-admin-check">
	<input type="hidden" name="<?php echo esc_attr( (string) $data['name'] ); ?>" value="0" />
	<input type="checkbox" id="<?php echo esc_attr( (string) $data['id'] ); ?>" name="<?php echo esc_attr( (string) $data['name'] ); ?>" value="1" <?php checked( true, (bool) ( $data['checked'] ?? false ) ); ?> />
	<label class="gatedmedia-admin-caps" for="<?php echo esc_attr( (string) $data['id'] ); ?>"><?php echo esc_html( (string) $data['label'] ); ?></label>
</div>
