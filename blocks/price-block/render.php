<?php
/**
 * Price block: the standalone price on a product page.
 *
 * Distinct from the inline price in a row: left-aligned, stacked tight, and the payable amount set at the h1 size. **It is not a heading**, so it is a span rather than an h-tag.
 *
 * Four forms: plain, discounted with the original struck through inline before the payable amount, free as the word rather than a zero, and not applicable as a dash, for access an administrator added with no order behind it.
 *
 * The duration line still shows in the free form.
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
$gatedmedia_term     = isset( $attributes['term'] ) ? (string) $attributes['term'] : '';

$gatedmedia_display = true === ( $attributes['notApplicable'] ?? false )
	? Money::not_applicable()
	: Money::format( $gatedmedia_amount, $gatedmedia_currency );

$gatedmedia_shows_original = $gatedmedia_original > $gatedmedia_amount
	&& true !== ( $attributes['notApplicable'] ?? false );
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-price-block' ) ) ); ?>>
	<p class="gatedmedia-price-block__line">
		<?php if ( $gatedmedia_shows_original ) : ?>
		<span class="gatedmedia-price-block__original"><?php echo esc_html( Money::format( $gatedmedia_original, $gatedmedia_currency ) ); ?></span>
		<?php endif; ?>
		<span class="gatedmedia-price-block__amount"><?php echo esc_html( $gatedmedia_display ); ?></span>
	</p>
	<?php if ( '' !== $gatedmedia_term ) : ?>
	<p class="gatedmedia-price-block__term"><?php echo esc_html( $gatedmedia_term ); ?></p>
	<?php endif; ?>
</div>
