<?php
/**
 * A select over a fixed set of choices.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array{name: string, id: string, value?: string, options: array<string, string>, blank?: string} $data
 */

?>
<select name="<?php echo esc_attr( (string) $data['name'] ); ?>" id="<?php echo esc_attr( (string) $data['id'] ); ?>">
	<?php if ( isset( $data['blank'] ) ) : ?>
		<option value=""><?php echo esc_html( (string) $data['blank'] ); ?></option>
	<?php endif; ?>
	<?php foreach ( $data['options'] as $option => $label ) : ?>
		<option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( (string) $option, (string) ( $data['value'] ?? '' ) ); ?>><?php echo esc_html( (string) $label ); ?></option>
	<?php endforeach; ?>
</select>
