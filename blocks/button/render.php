<?php
/**
 * Buttons: primary, secondary, and the text link.
 *
 * Every button is 44px tall, with no exceptions. Narrow drops the minimum width and goes full width, which is CSS rather than a variant here.
 *
 * A `href` makes it an anchor and its absence a button, which is the difference between navigating and submitting.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_label = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';

if ( '' === $gatedmedia_label ) {
	return;
}

$gatedmedia_variant = isset( $attributes['variant'] ) ? (string) $attributes['variant'] : 'primary';
$gatedmedia_href    = isset( $attributes['href'] ) ? (string) $attributes['href'] : '';
$gatedmedia_icon    = isset( $attributes['icon'] ) ? (string) $attributes['icon'] : '';
$gatedmedia_full    = true === ( $attributes['full'] ?? false );

// The text link is its own component, with no box, minimum width or height.
$gatedmedia_classes = 'link' === $gatedmedia_variant
	? array( 'gatedmedia-text-link' )
	: array( 'gatedmedia-button', 'gatedmedia-button--' . $gatedmedia_variant );

if ( $gatedmedia_full && 'link' !== $gatedmedia_variant ) {
	$gatedmedia_classes[] = 'gatedmedia-button--full';
}

// The wrapper is block-level with the control inside, because a theme centres a top-level block with `margin-inline: auto`, which does nothing to an inline element.
$gatedmedia_attrs = get_block_wrapper_attributes( array( 'class' => 'gatedmedia-inline-host' ) );
$gatedmedia_class = implode( ' ', $gatedmedia_classes );
?>
<div <?php echo wp_kses_data( $gatedmedia_attrs ); ?>>
	<?php if ( '' !== $gatedmedia_href ) : ?>
	<a class="<?php echo esc_attr( $gatedmedia_class ); ?>" href="<?php echo esc_url( $gatedmedia_href ); ?>">
		<?php if ( '' !== $gatedmedia_icon ) : ?>
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<?php endif; ?>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</a>
	<?php else : ?>
	<button
		class="<?php echo esc_attr( $gatedmedia_class ); ?>"
		type="<?php echo esc_attr( isset( $attributes['type'] ) ? (string) $attributes['type'] : 'button' ); ?>"
		<?php if ( '' !== (string) ( $attributes['name'] ?? '' ) ) : ?>
		name="<?php echo esc_attr( (string) $attributes['name'] ); ?>"
		value="<?php echo esc_attr( isset( $attributes['value'] ) ? (string) $attributes['value'] : '' ); ?>"
		<?php endif; ?>
		<?php // A button may submit a form it is not inside, by naming its id. ?>
		<?php if ( '' !== (string) ( $attributes['form'] ?? '' ) ) : ?>
		form="<?php echo esc_attr( (string) $attributes['form'] ); ?>"
		<?php endif; ?>
	>
		<?php if ( '' !== $gatedmedia_icon ) : ?>
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<?php endif; ?>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</button>
	<?php endif; ?>
</div>
