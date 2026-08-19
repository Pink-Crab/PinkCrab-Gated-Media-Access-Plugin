<?php
/**
 * §6.3 Buttons — primary, secondary, and the text link.
 *
 * Every button is 44px tall; the corpus has no exception. Narrow drops the
 * minimum width and goes full width, which is CSS rather than a variant here.
 *
 * A `href` makes it an anchor, its absence a button — the difference between
 * navigating and submitting, and the two must not be interchangeable markup.
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

// The text link is its own component in §6.3, not a third button variant — it
// has no box, no minimum width and no height.
$gatedmedia_classes = 'link' === $gatedmedia_variant
	? array( 'gatedmedia-text-link' )
	: array( 'gatedmedia-button', 'gatedmedia-button--' . $gatedmedia_variant );

if ( $gatedmedia_full && 'link' !== $gatedmedia_variant ) {
	$gatedmedia_classes[] = 'gatedmedia-button--full';
}

// The block wrapper is block-level, with the control inside it.
//
// A button is inline-flex, and a theme placing a top-level block centres it
// with `margin-inline: auto` — which does nothing to an inline element, so the
// button would sit against the left edge of the page rather than in the
// content column. Inside a Row's aside the flex container handles it; standing
// on its own it needs a host of its own.
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
	>
		<?php if ( '' !== $gatedmedia_icon ) : ?>
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<?php endif; ?>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</button>
	<?php endif; ?>
</div>
