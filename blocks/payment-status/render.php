<?php
/**
 * Payment status: the state of an order the buyer has just placed.
 *
 * Stripe returns the buyer before its webhook has necessarily landed, so the first thing they see may be a payment that is still `pending`. This draws that honestly rather than claiming success: confirming, done, or failed.
 *
 * **It reads a status someone else established.** Access is granted on Stripe's confirmation and nowhere else, so nothing here writes, grants or asks Stripe anything. It renders the row as found.
 *
 * Server-rendered first, so the panel shows the status at page load. A pending one carries what `assets/js/modules/payment-status.js` needs to watch `Payment_Status_Route`: uuid, REST nonce and timing. The page reloads when the status moves, because the pill and the access section change with it. Without JavaScript the wording still tells the buyer to reload.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Support\Labels;

defined( 'ABSPATH' ) || exit;

$gatedmedia_status = isset( $attributes['status'] ) ? (string) $attributes['status'] : 'pending';

// Icon and default wording per state, refunded included: it is not a return-page state, but an order detail can be viewed long after one, so it has wording rather than falling through to nothing.
$gatedmedia_states = array(
	'pending'  => array(
		'icon'    => '',
		'kind'    => 'pending',
		'heading' => Labels::text( 'payment.confirming' ),
		'message' => Labels::text( 'payment.confirming_note' ),
	),
	'complete' => array(
		'icon'    => 'i-success',
		'kind'    => 'complete',
		'heading' => Labels::text( 'payment.ready' ),
		'message' => Labels::text( 'payment.ready_note' ),
	),
	'refunded' => array(
		'icon'    => 'i-refund',
		'kind'    => 'refunded',
		'heading' => Labels::text( 'payment.refunded' ),
		'message' => Labels::text( 'payment.refunded_note' ),
	),
	'failed'   => array(
		'icon'    => 'i-error',
		'kind'    => 'failed',
		'heading' => Labels::text( 'payment.failed' ),
		'message' => Labels::text( 'payment.failed_note' ),
	),
);

if ( ! isset( $gatedmedia_states[ $gatedmedia_status ] ) ) {
	return;
}

$gatedmedia_state = $gatedmedia_states[ $gatedmedia_status ];

$gatedmedia_heading = '' !== (string) ( $attributes['heading'] ?? '' )
	? (string) $attributes['heading']
	: $gatedmedia_state['heading'];

$gatedmedia_message = '' !== (string) ( $attributes['message'] ?? '' )
	? (string) $attributes['message']
	: $gatedmedia_state['message'];

$gatedmedia_reference    = isset( $attributes['reference'] ) ? (string) $attributes['reference'] : '';
$gatedmedia_action_label = isset( $attributes['actionLabel'] ) ? (string) $attributes['actionLabel'] : '';
$gatedmedia_action_href  = isset( $attributes['actionHref'] ) ? (string) $attributes['actionHref'] : '';

$gatedmedia_action = '';

if ( '' !== $gatedmedia_action_label && '' !== $gatedmedia_action_href ) {
	$gatedmedia_action = do_blocks(
		sprintf(
			'<!-- wp:gated-media-access/button %s /-->',
			(string) wp_json_encode(
				array(
					'label'   => $gatedmedia_action_label,
					'href'    => $gatedmedia_action_href,
					'variant' => 'complete' === $gatedmedia_status ? 'primary' : 'secondary',
				)
			)
		)
	);
}

$gatedmedia_classes = 'gatedmedia-payment-status gatedmedia-payment-status--' . $gatedmedia_state['kind'];

// -----------------------------------------------------------------------------
// The poll, on a pending payment only, with everything the script needs riding on the element it is already looking for: the uuid, a REST nonce, and the wording to stand down with, so a page without these attributes never polls.
// -----------------------------------------------------------------------------
$gatedmedia_uuid = isset( $attributes['uuid'] ) ? (string) $attributes['uuid'] : '';
$gatedmedia_poll = '';

if ( 'pending' === $gatedmedia_status && '' !== $gatedmedia_uuid && is_user_logged_in() ) {
	/**
	 * Filters how the return page waits for Stripe's webhook.
	 *
	 * @param array{interval: int, attempts: int} $poll Milliseconds between polls, and how many.
	 * @param string                              $uuid The payment being watched.
	 */
	$gatedmedia_timing = (array) apply_filters(
		'gatedmedia_payment_poll',
		array(
			'interval' => 3000,
			'attempts' => 20,
		),
		$gatedmedia_uuid
	);

	$gatedmedia_poll = sprintf(
		' data-gatedmedia-poll="%s" data-gatedmedia-nonce="%s" data-gatedmedia-url="%s" data-gatedmedia-interval="%d" data-gatedmedia-attempts="%d" data-gatedmedia-waiting="%s"',
		esc_attr( $gatedmedia_uuid ),
		esc_attr( wp_create_nonce( 'wp_rest' ) ),
		esc_url( rest_url( 'gated-media-access/v1/payment/' . $gatedmedia_uuid ) ),
		max( 1000, (int) ( $gatedmedia_timing['interval'] ?? 3000 ) ),
		max( 1, (int) ( $gatedmedia_timing['attempts'] ?? 20 ) ),
		esc_attr( Labels::text( 'payment.slow_note' ) )
	);
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $gatedmedia_classes ) ) ) . $gatedmedia_poll; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each attribute escaped above. ?>>
	<?php if ( 'pending' === $gatedmedia_status ) : ?>
	<div class="gatedmedia-spinner" aria-hidden="true"></div>
	<?php elseif ( '' !== $gatedmedia_state['icon'] ) : ?>
	<svg class="gatedmedia-icon gatedmedia-payment-status__icon" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_state['icon'] ); ?>"></use></svg>
	<?php endif; ?>

	<h2 class="gatedmedia-heading gatedmedia-heading--page"><?php echo esc_html( $gatedmedia_heading ); ?></h2>

	<p class="gatedmedia-text" data-gatedmedia-message><?php echo esc_html( $gatedmedia_message ); ?></p>

	<?php if ( '' !== $gatedmedia_action ) : ?>
	<div class="gatedmedia-payment-status__action">
		<?php echo $gatedmedia_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
	</div>
	<?php endif; ?>

	<?php if ( '' !== $gatedmedia_reference ) : ?>
	<p class="gatedmedia-text gatedmedia-text--meta">
		<?php
		printf(
			esc_html( Labels::text( 'payment.reference' ) ),
			esc_html( $gatedmedia_reference )
		);
		?>
	</p>
	<?php endif; ?>
</div>
