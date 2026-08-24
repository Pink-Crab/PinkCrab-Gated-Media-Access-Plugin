<?php
/**
 * §6.8 Form field — label above, input, message beneath.
 *
 * The invalid treatment is the part that matters and the part most easily
 * left out: the border goes `error`, **the label goes `error` too**, and the
 * message sits beneath in 14px error. The field is marked invalid and pointed
 * at its message for screen readers — `aria-invalid` and `aria-describedby`
 * are both already in the corpus, so they are requirements rather than extras.
 *
 * `error` and `message` occupy the same line and never both show: an error
 * replaces the helper text, because a field explaining itself while also
 * complaining reads as two problems.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_name = isset( $attributes['name'] ) ? (string) $attributes['name'] : '';

if ( '' === $gatedmedia_name ) {
	return;
}

$gatedmedia_label     = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';
$gatedmedia_error     = isset( $attributes['error'] ) ? (string) $attributes['error'] : '';
$gatedmedia_help      = isset( $attributes['message'] ) ? (string) $attributes['message'] : '';
$gatedmedia_multiline = true === ( $attributes['multiline'] ?? false );

// Invalid with no message of its own: §7.7's failed sign-in marks both fields
// while the notice above them carries the one explanation. Without this the
// only way to a red border is an error string, and the page would say the same
// thing three times.
$gatedmedia_invalid = '' !== $gatedmedia_error || true === ( $attributes['invalid'] ?? false );

// An error replaces the helper line rather than joining it.
$gatedmedia_message = $gatedmedia_invalid ? $gatedmedia_error : $gatedmedia_help;

$gatedmedia_id         = 'gatedmedia-' . sanitize_key( $gatedmedia_name );
$gatedmedia_message_id = $gatedmedia_id . '-message';

$gatedmedia_classes = 'gatedmedia-field' . ( $gatedmedia_invalid ? ' is-invalid' : '' );

$gatedmedia_input_attrs = array(
	'class' => 'gatedmedia-field__input',
	'id'    => $gatedmedia_id,
	'name'  => $gatedmedia_name,
);

if ( '' !== (string) ( $attributes['placeholder'] ?? '' ) ) {
	$gatedmedia_input_attrs['placeholder'] = (string) $attributes['placeholder'];
}

if ( '' !== (string) ( $attributes['autocomplete'] ?? '' ) ) {
	$gatedmedia_input_attrs['autocomplete'] = (string) $attributes['autocomplete'];
}

// Only point at the message when there is one to point at.
if ( '' !== $gatedmedia_message ) {
	$gatedmedia_input_attrs['aria-describedby'] = $gatedmedia_message_id;
}

if ( $gatedmedia_invalid ) {
	$gatedmedia_input_attrs['aria-invalid'] = 'true';
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $gatedmedia_classes ) ) ); ?>>
	<?php if ( '' !== $gatedmedia_label ) : ?>
	<label
		class="gatedmedia-field__label<?php echo true === ( $attributes['labelHidden'] ?? false ) ? ' gatedmedia-visually-hidden' : ''; ?>"
		for="<?php echo esc_attr( $gatedmedia_id ); ?>"
	><?php echo esc_html( $gatedmedia_label ); ?></label>
	<?php endif; ?>

	<?php if ( $gatedmedia_multiline ) : ?>
	<textarea
		<?php foreach ( $gatedmedia_input_attrs as $gatedmedia_key => $gatedmedia_val ) : ?>
			<?php echo esc_attr( $gatedmedia_key ); ?>="<?php echo esc_attr( $gatedmedia_val ); ?>"
		<?php endforeach; ?>
		<?php echo true === ( $attributes['disabled'] ?? false ) ? 'disabled' : ''; ?>
		<?php echo true === ( $attributes['required'] ?? false ) ? 'required' : ''; ?>
	><?php echo esc_textarea( isset( $attributes['value'] ) ? (string) $attributes['value'] : '' ); ?></textarea>
	<?php else : ?>
	<input
		<?php foreach ( $gatedmedia_input_attrs as $gatedmedia_key => $gatedmedia_val ) : ?>
			<?php echo esc_attr( $gatedmedia_key ); ?>="<?php echo esc_attr( $gatedmedia_val ); ?>"
		<?php endforeach; ?>
		type="<?php echo esc_attr( isset( $attributes['type'] ) ? (string) $attributes['type'] : 'text' ); ?>"
		value="<?php echo esc_attr( isset( $attributes['value'] ) ? (string) $attributes['value'] : '' ); ?>"
		<?php echo true === ( $attributes['disabled'] ?? false ) ? 'disabled' : ''; ?>
		<?php echo true === ( $attributes['required'] ?? false ) ? 'required' : ''; ?>
	>
	<?php endif; ?>

	<?php if ( '' !== $gatedmedia_message ) : ?>
	<p class="gatedmedia-field__message" id="<?php echo esc_attr( $gatedmedia_message_id ); ?>">
		<?php echo esc_html( $gatedmedia_message ); ?>
	</p>
	<?php endif; ?>
</div>
