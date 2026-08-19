<?php
/**
 * §6.2 Row — the workhorse. A file, a post, a group, an order.
 *
 * Wide: one line, title and meta on the left, the aside right-aligned.
 * Narrow: the row stacks and its action goes full width beneath — the single
 * biggest wide-to-narrow change in the design, and the reason `__action`
 * exists separately from `__aside`. Wide, groups and posts have no action at
 * all; narrow, they do.
 *
 * Four states, all drawn in the corpus:
 *
 * - **normal** — as above, with or without an aside.
 * - **unavailable** — whole row at 50%, title struck through, the aside
 *   replaced by a plain statement. A record outlives what it points at
 *   (architecture.md §8), so this is a real state and not an error.
 * - **loading** — two grey bars and a button-sized block, gently pulsing.
 *
 * The aside is inner blocks because it genuinely varies: an expiry, a status
 * pill, a price, a button, or several. The narrow action is one button, so it
 * is attributes — and it is composed from the button block rather than having
 * its markup written again here.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_state = isset( $attributes['state'] ) ? (string) $attributes['state'] : 'normal';

$gatedmedia_classes = array( 'gatedmedia-row' );

if ( 'order' === ( isset( $attributes['variant'] ) ? (string) $attributes['variant'] : 'default' ) ) {
	$gatedmedia_classes[] = 'gatedmedia-row--order';
}

// -----------------------------------------------------------------------------
// Loading. Nothing else on the row renders — there is no content yet to render.
// -----------------------------------------------------------------------------
if ( 'loading' === $gatedmedia_state ) {
	$gatedmedia_classes[] = 'is-loading';
	?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => implode( ' ', $gatedmedia_classes ) ) ) ); ?> aria-hidden="true">
	<div class="gatedmedia-row__main">
		<div class="gatedmedia-skeleton gatedmedia-skeleton--title"></div>
		<div class="gatedmedia-skeleton gatedmedia-skeleton--meta"></div>
	</div>
	<div class="gatedmedia-row__aside">
		<div class="gatedmedia-skeleton gatedmedia-skeleton--action"></div>
	</div>
</div>
	<?php
	return;
}

$gatedmedia_title = isset( $attributes['title'] ) ? (string) $attributes['title'] : '';

if ( '' === $gatedmedia_title ) {
	return;
}

if ( 'unavailable' === $gatedmedia_state ) {
	$gatedmedia_classes[] = 'is-unavailable';
}

$gatedmedia_meta = isset( $attributes['meta'] ) ? (string) $attributes['meta'] : '';
$gatedmedia_href = isset( $attributes['href'] ) ? (string) $attributes['href'] : '';

// An unavailable row states so on the right and carries no action — there is
// nothing left to act on.
$gatedmedia_aside = 'unavailable' === $gatedmedia_state
	? ''
	: trim( $content );

$gatedmedia_unavailable_label = isset( $attributes['unavailableLabel'] ) && '' !== $attributes['unavailableLabel']
	? (string) $attributes['unavailableLabel']
	: __( 'No longer available', 'gated-media-access' );

// §7.1 narrow — the full-width action. Composed from the button block so its
// markup lives in exactly one place.
$gatedmedia_action = '';

if ( 'unavailable' !== $gatedmedia_state && '' !== (string) ( $attributes['actionLabel'] ?? '' ) ) {
	$gatedmedia_action = do_blocks(
		sprintf(
			'<!-- wp:gated-media-access/button %s /-->',
			(string) wp_json_encode(
				array(
					'label'   => (string) $attributes['actionLabel'],
					'href'    => isset( $attributes['actionHref'] ) ? (string) $attributes['actionHref'] : '',
					'icon'    => isset( $attributes['actionIcon'] ) ? (string) $attributes['actionIcon'] : '',
					'variant' => 'primary',
					'full'    => true,
				)
			)
		)
	);
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => implode( ' ', $gatedmedia_classes ) ) ) ); ?>>
	<div class="gatedmedia-row__main">
		<?php if ( '' !== $gatedmedia_href ) : ?>
		<p class="gatedmedia-row__title"><a href="<?php echo esc_url( $gatedmedia_href ); ?>"><?php echo esc_html( $gatedmedia_title ); ?></a></p>
		<?php else : ?>
		<p class="gatedmedia-row__title"><?php echo esc_html( $gatedmedia_title ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $gatedmedia_meta ) : ?>
		<p class="gatedmedia-row__meta"><?php echo esc_html( $gatedmedia_meta ); ?></p>
		<?php endif; ?>
	</div>

	<div class="gatedmedia-row__aside">
		<?php
		if ( 'unavailable' === $gatedmedia_state ) {
			printf( '<span class="gatedmedia-text gatedmedia-text--meta">%s</span>', esc_html( $gatedmedia_unavailable_label ) );
		} elseif ( '' !== $gatedmedia_aside ) {
			echo $gatedmedia_aside; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner block output, escaped by the blocks that produced it.
		}
		?>
	</div>

	<?php if ( '' !== $gatedmedia_action ) : ?>
	<div class="gatedmedia-row__action">
		<?php echo $gatedmedia_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
	</div>
	<?php endif; ?>
</div>
