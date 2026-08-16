<?php
/**
 * §6.10 Empty state — a dashed box, everything centred.
 *
 * The only deliberately airy thing in the design, and §8 conflict 6 settled
 * that there is exactly one of it: at page level, only when the view has
 * nothing at all. Three dashed boxes down a page read as three failures rather
 * than one empty account, so an empty *section* is simply not rendered.
 *
 * The message is not optional. §6.10 is explicit that the line beneath the
 * headline always states what would put something here — the box is never a
 * dead end — so this renders nothing without one rather than drawing a box
 * that leaves someone stuck.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_title   = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';
$gatedmedia_message = isset( $attributes['message'] ) ? (string) $attributes['message'] : '';

if ( '' === $gatedmedia_title || '' === $gatedmedia_message ) {
	return;
}

$gatedmedia_icon = isset( $attributes['icon'] ) ? (string) $attributes['icon'] : 'i-empty';
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-empty-state' ) ) ); ?>>
	<?php if ( '' !== $gatedmedia_icon ) : ?>
	<svg class="gatedmedia-empty-state__icon" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
	<?php endif; ?>
	<p class="gatedmedia-empty-state__title"><?php echo esc_html( $gatedmedia_title ); ?></p>
	<p class="gatedmedia-text gatedmedia-text--meta"><?php echo esc_html( $gatedmedia_message ); ?></p>
</div>
