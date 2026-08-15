<?php
/**
 * §7.3 Orders — the record of what was taken and when.
 *
 * **This is not a shop.** One flat list, no sections and no filter: an account
 * holds few enough orders that grouping them would be scaffolding around
 * nothing.
 *
 * Each row (§6.2) carries the order title and its date on the left, and on the
 * right, stacked tight, the inline price (§6.7) above the status pill (§6.6).
 * Orders is the one row that does **not** stack on narrow — price and status
 * stay right-aligned at every width, which is why it takes the `--order`
 * modifier.
 *
 * **The rows are not built yet.** Orders come from the payments table
 * (specification.md §3), which does not exist — it is step 1 of §12, and the
 * Stripe route that fills it is step 4.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_user_id = get_current_user_id();

if ( 0 === $gatedmedia_user_id ) {
	return;
}

// The second URL segment. §7.4 order detail is reached at
// /account/orders/{id}, and this is where that branch will go once there are
// payments to look one up from.
$gatedmedia_order_id = isset( $attributes['detail'] ) && is_string( $attributes['detail'] )
	? $attributes['detail']
	: '';

// Placeholder for the payments query — annotated with the shape it will
// return, so static analysis does not narrow it to the empty array.
/** @var array<int, array<string, mixed>> $gatedmedia_orders */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_orders = array();
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--orders' ) ) ); ?>>

	<?php if ( array() === $gatedmedia_orders ) : ?>

		<div class="gatedmedia-empty-state">
			<svg class="gatedmedia-empty-state__icon" aria-hidden="true" focusable="false"><use href="#i-orders"></use></svg>
			<p class="gatedmedia-empty-state__title"><?php esc_html_e( 'No orders yet', 'gated-media-access' ); ?></p>
			<p class="gatedmedia-text gatedmedia-text--meta">
				<?php esc_html_e( 'Anything you pay for will be listed here, with what it included.', 'gated-media-access' ); ?>
			</p>
		</div>

	<?php endif; ?>

</div>
