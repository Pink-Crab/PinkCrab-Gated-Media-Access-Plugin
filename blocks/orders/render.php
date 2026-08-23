<?php
/**
 * §7.3 Orders — the record of what was taken and when, and §7.4 one of them.
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
 * The `detail` attribute is the second URL segment, set by
 * `Account_Renderer::section_content()` from `/account/orders/{uuid}`. With one
 * present this draws that single order instead of the list — including the
 * state a buyer returning from Stripe lands on, which is why §7.8 has no page
 * of its own.
 *
 * Free products never appear here: a free claim grants access directly and
 * writes no payment row at all (`Checkout::claim_free()`, architecture §7).
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
// /account/orders/{uuid}.
$gatedmedia_order_id = isset( $attributes['detail'] ) && is_string( $attributes['detail'] )
	? $attributes['detail']
	: '';

/**
 * Supplied by Order_History from the payments table; the empty defaults are
 * what someone who has bought nothing renders.
 *
 * @var array{orders: array<int, array<string, mixed>>, detail: array<string, mixed>|null} $gatedmedia_data
 */
$gatedmedia_data = apply_filters(
	'gatedmedia_orders_data',
	array(
		'orders' => array(),
		'detail' => null,
	),
	$gatedmedia_order_id
);

$gatedmedia_orders = is_array( $gatedmedia_data['orders'] ?? null ) ? $gatedmedia_data['orders'] : array();
$gatedmedia_detail = is_array( $gatedmedia_data['detail'] ?? null ) ? $gatedmedia_data['detail'] : null;

$gatedmedia_body = '';

if ( '' !== $gatedmedia_order_id ) {
	// -------------------------------------------------------------------
	// §7.4 — one order.
	// -------------------------------------------------------------------
	if ( null === $gatedmedia_detail ) {
		// Someone else's order and an order that never existed read the same.
		$gatedmedia_body = Block::render(
			'gated-media-access/empty-state',
			array(
				'icon'    => 'i-empty',
				'title'   => __( 'Order not found', 'gated-media-access' ),
				'message' => __( 'We could not find that order on your account.', 'gated-media-access' ),
			)
		);
	} else {
		$gatedmedia_status = (string) ( $gatedmedia_detail['status'] ?? 'complete' );

		// Built from the section's own URL rather than by chopping the
		// order's: an empty href would make dirname() answer '.' and the link
		// would point at the page it is on.
		$gatedmedia_back = Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Back to orders', 'gated-media-access' ),
				'href'    => (string) ( $gatedmedia_data['section_url'] ?? '' ),
				'variant' => 'link',
				'icon'    => 'i-back',
			)
		);

		$gatedmedia_price = Block::render(
			'gated-media-access/price',
			array(
				'amount'   => (int) ( $gatedmedia_detail['amount'] ?? 0 ),
				'original' => (int) ( $gatedmedia_detail['original'] ?? 0 ),
				'currency' => (string) ( $gatedmedia_detail['currency'] ?? 'GBP' ),
			)
		);

		$gatedmedia_pill = Block::render(
			'gated-media-access/status-pill',
			array( 'value' => $gatedmedia_status )
		);

		ob_start();
		?>
		<div class="gatedmedia-order-header">
			<h2 class="gatedmedia-heading gatedmedia-heading--page"><?php echo esc_html( (string) ( $gatedmedia_detail['title'] ?? '' ) ); ?></h2>

			<div class="gatedmedia-order-header__line">
				<span class="gatedmedia-text gatedmedia-text--meta"><?php echo esc_html( (string) ( $gatedmedia_detail['date'] ?? '' ) ); ?></span>
				<?php echo $gatedmedia_price; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the price block. ?>
				<?php echo $gatedmedia_pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the pill block. ?>
			</div>
		</div>
		<?php
		$gatedmedia_body = (string) ob_get_clean();

		// §7.8 — the state a buyer sees coming back from Stripe. Drawn when
		// they have just arrived, and whenever the order is not simply done,
		// so a pending or failed order explains itself on any visit.
		if ( true === ( $gatedmedia_detail['is_new'] ?? false ) || 'complete' !== $gatedmedia_status ) {
			$gatedmedia_body .= Block::render(
				'gated-media-access/payment-status',
				array(
					'status'      => $gatedmedia_status,
					'reference'   => (string) ( $gatedmedia_detail['uuid'] ?? '' ),
					// The same value as the reference today, passed separately
					// because the poll matches on the uuid and must not depend
					// on what the reference is chosen to show.
					'uuid'        => (string) ( $gatedmedia_detail['uuid'] ?? '' ),
					'actionLabel' => 'complete' === $gatedmedia_status ? __( 'Go to my access', 'gated-media-access' ) : '',
					'actionHref'  => 'complete' === $gatedmedia_status ? home_url( '/account/my-access/' ) : '',
				)
			);
		}

		$gatedmedia_contents = is_array( $gatedmedia_detail['contents'] ?? null ) ? $gatedmedia_detail['contents'] : array();

		if ( array() !== $gatedmedia_contents ) {
			$gatedmedia_body .= '<section class="gatedmedia-section">'
				. Block::render(
					'gated-media-access/section-heading',
					array( 'text' => __( 'What this included at the time', 'gated-media-access' ) )
				)
				. Block::render(
					'gated-media-access/contents',
					array(
						'items' => $gatedmedia_contents,
						/* translators: %s: the order date. */
						'note'  => sprintf( __( 'Frozen as it was on %s.', 'gated-media-access' ), (string) ( $gatedmedia_detail['date'] ?? '' ) ),
					)
				)
				. '</section>';
		}

		$gatedmedia_access = is_array( $gatedmedia_detail['access'] ?? null ) ? $gatedmedia_detail['access'] : array();

		if ( array() !== $gatedmedia_access ) {
			$gatedmedia_access_rows = '';

			foreach ( $gatedmedia_access as $gatedmedia_record ) {
				$gatedmedia_chip = Block::render(
					'gated-media-access/expiry',
					array(
						'state' => (string) ( $gatedmedia_record['expiry_state'] ?? 'lifetime' ),
						'label' => (string) ( $gatedmedia_record['expiry_label'] ?? '' ),
						'chip'  => true,
					)
				);

				$gatedmedia_access_rows .= sprintf(
					'<div class="gatedmedia-order-access__row"><h3 class="gatedmedia-heading gatedmedia-heading--row">%s</h3>%s</div>',
					esc_html( (string) ( $gatedmedia_record['title'] ?? '' ) ),
					$gatedmedia_chip
				);
			}

			$gatedmedia_body .= '<section class="gatedmedia-section">'
				. Block::render(
					'gated-media-access/section-heading',
					array( 'text' => __( 'Access this created', 'gated-media-access' ) )
				)
				. '<div class="gatedmedia-order-access">' . $gatedmedia_access_rows . '</div>'
				. '</section>';
		}

		$gatedmedia_body = $gatedmedia_back . $gatedmedia_body;
	}
} else {
	// -------------------------------------------------------------------
	// §7.3 — the list.
	// -------------------------------------------------------------------
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
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-view gatedmedia-view--orders' ) ) ); ?>>
	<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it. ?>
</div>
