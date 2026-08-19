<?php
/**
 * §6.15 Pinned action bar — narrow only, Product only.
 *
 * **The only pinned bottom element in the design.** §8 conflict 3 removed the
 * fixed bottom tab bar, because a fixed bar owns the bottom of a viewport that
 * is not ours to take — it collides with the theme's footer, cookie notices
 * and the admin bar. This one survives because it is a single action on a
 * public page rather than navigation.
 *
 * §6.15 is explicit: **it must not be used on account views.** Nothing here can
 * enforce that; it is a rule for whoever composes a view, and the account
 * shell does not compose it.
 *
 * The price goes in the button's own label, so there is one control rather
 * than a bar with a price beside a button.
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

$gatedmedia_button = do_blocks(
	sprintf(
		'<!-- wp:gated-media-access/button %s /-->',
		(string) wp_json_encode(
			array(
				'label'   => $gatedmedia_label,
				'href'    => isset( $attributes['href'] ) ? (string) $attributes['href'] : '',
				'variant' => 'primary',
				'full'    => true,
			)
		)
	)
);
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-action-bar' ) ) ); ?>>
	<?php echo $gatedmedia_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
</div>
