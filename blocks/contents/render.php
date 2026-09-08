<?php
/**
 * Contents list: what a product contains, or what an order contained.
 *
 * **The same component in both places.** Product's "what you get" and order detail's "what this included" are one thing, built once.
 *
 * It is a statement of contents, not a list you can act on: no rules between rows, no right-hand column, no actions. That is the whole distinction from a Row, and why this is not a list of Rows.
 *
 * The optional note is used in order detail to record that the contents are frozen as they were on the order date, because groups are live and what an order contained is not what the group holds now.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_items = isset( $attributes['items'] ) && is_array( $attributes['items'] )
	? $attributes['items']
	: array();

if ( array() === $gatedmedia_items ) {
	return;
}

$gatedmedia_note = isset( $attributes['note'] ) ? (string) $attributes['note'] : '';
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
	<ul class="gatedmedia-contents">
		<?php foreach ( $gatedmedia_items as $gatedmedia_item ) : ?>
			<?php
			if ( ! is_array( $gatedmedia_item ) || '' === (string) ( $gatedmedia_item['text'] ?? '' ) ) {
				continue;
			}

			$gatedmedia_icon = isset( $gatedmedia_item['icon'] ) ? (string) $gatedmedia_item['icon'] : '';
			?>
		<li class="gatedmedia-contents__item">
			<?php if ( '' !== $gatedmedia_icon ) : ?>
			<svg class="gatedmedia-icon gatedmedia-contents__icon" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
			<?php endif; ?>
			<span><?php echo esc_html( (string) $gatedmedia_item['text'] ); ?></span>
		</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( '' !== $gatedmedia_note ) : ?>
	<p class="gatedmedia-contents__note"><?php echo esc_html( $gatedmedia_note ); ?></p>
	<?php endif; ?>
</div>
