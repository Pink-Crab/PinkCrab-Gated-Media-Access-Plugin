<?php
/**
 * §7.3 Orders — the record of what was taken and when.
 *
 * **This is not a shop.** One flat list, no sections and no filter: an account
 * holds few enough orders that grouping them would be scaffolding around
 * nothing.
 *
 * Each row carries the order title and its date on the left, and on the right,
 * stacked tight, the inline price (§6.7) above the status pill (§6.6). The
 * whole row links to §7.4.
 *
 * Orders is **the one row that does not stack on narrow** — price and status
 * stay right-aligned at every width, which is what the `order` variant means.
 *
 * A refunded or revoked order additionally drops the whole row to 60% (§6.6),
 * which is the row's job rather than the pill's.
 *
 * Orders come from the payments table (specification.md §3), which does not
 * exist — it is step 1 of §12, and the Stripe route that fills it is step 4.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

$gatedmedia_user_id = get_current_user_id();

if ( 0 === $gatedmedia_user_id ) {
	return;
}

// The second URL segment. §7.4 order detail is reached at
// /account/orders/{id}; this is where that branch goes once there are payments
// to look one up from.
$gatedmedia_order_id = isset( $attributes['detail'] ) && is_string( $attributes['detail'] )
	? $attributes['detail']
	: '';

/** @var array<int, array<string, mixed>> $gatedmedia_orders */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort -- Inline type annotation, not a description.
$gatedmedia_orders = array();

$gatedmedia_body = '';

foreach ( $gatedmedia_orders as $gatedmedia_order ) {
	$gatedmedia_status = (string) ( $gatedmedia_order['status'] ?? 'complete' );

	// §6.6 — a refunded or revoked row is muted as a whole.
	$gatedmedia_spent = in_array( $gatedmedia_status, array( 'refunded', 'revoked' ), true );

	$gatedmedia_aside = Block::render(
		'gated-media-access/price',
		array(
			'amount'   => (int) ( $gatedmedia_order['amount'] ?? 0 ),
			'original' => (int) ( $gatedmedia_order['original'] ?? 0 ),
			'currency' => (string) ( $gatedmedia_order['currency'] ?? 'GBP' ),
		)
	) . Block::render(
		'gated-media-access/status-pill',
		array( 'value' => $gatedmedia_status )
	);

	$gatedmedia_row = Block::render(
		'gated-media-access/row',
		array(
			'title'   => (string) ( $gatedmedia_order['title'] ?? '' ),
			'meta'    => (string) ( $gatedmedia_order['date'] ?? '' ),
			'href'    => (string) ( $gatedmedia_order['href'] ?? '' ),
			'variant' => 'order',
		),
		$gatedmedia_aside
	);

	$gatedmedia_body .= $gatedmedia_spent
		? '<div class="is-spent">' . $gatedmedia_row . '</div>'
		: $gatedmedia_row;
}

if ( '' === $gatedmedia_body ) {
	$gatedmedia_body = Block::render(
		'gated-media-access/empty-state',
		array(
			'icon'    => 'i-orders',
			'title'   => __( 'No orders yet', 'gated-media-access' ),
			'message' => __( 'Anything you pay for will be listed here, with what it included.', 'gated-media-access' ),
		)
	);
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--orders' ) ) ); ?>>
	<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it. ?>
</div>
