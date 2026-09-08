<?php
/**
 * Price, inline: right-aligned, 16px semibold.
 *
 * Where a coupon was used the original is struck through and muted immediately to the left of what was actually paid, on the same line.
 *
 * Zero is the word "Free", which Money::format() owns so this template and the price block cannot disagree about it.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Support\Money;

defined( 'ABSPATH' ) || exit;

$gatedmedia_currency = isset( $attributes['currency'] ) ? (string) $attributes['currency'] : 'GBP';
$gatedmedia_amount   = isset( $attributes['amount'] ) ? (int) $attributes['amount'] : 0;
$gatedmedia_original = isset( $attributes['original'] ) ? (int) $attributes['original'] : 0;

// No price exists at all, as with access an administrator added. A dash, not a zero.
$gatedmedia_display = true === ( $attributes['notApplicable'] ?? false )
	? Money::not_applicable()
	: Money::format( $gatedmedia_amount, $gatedmedia_currency );

// Only a genuine reduction is shown struck through, because an "original" equal to or below what was paid is not a discount and would read as a mistake.
$gatedmedia_shows_original = $gatedmedia_original > $gatedmedia_amount
	&& true !== ( $attributes['notApplicable'] ?? false );
// Block-level host around an inline component, as the button block does.
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-inline-host' ) ) ); ?>>
	<span class="gatedmedia-price">
		<?php if ( $gatedmedia_shows_original ) : ?>
		<span class="gatedmedia-price__original"><?php echo esc_html( Money::format( $gatedmedia_original, $gatedmedia_currency ) ); ?></span>
		<?php endif; ?>
		<?php echo esc_html( $gatedmedia_display ); ?>
	</span>
</div>
