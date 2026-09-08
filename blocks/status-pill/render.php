<?php
/**
 * §6.6 Status pill — an icon and a label in a small fill.
 *
 * Seven values: §6.6's five, plus a payment's own pending and failed. The
 * pill itself never dims: §6.6 says a refunded or revoked *row* drops to 60%,
 * which is the row's job.
 *
 * The default label comes from the value, so a caller passing only `value`
 * gets the right words, and a caller with its own wording can override it.
 * A value outside the table renders nothing, and `block.json` enumerates the
 * same seven.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_value = isset( $attributes['value'] ) ? (string) $attributes['value'] : 'active';

// Icon and default wording per value, from §6.6's table.
$gatedmedia_values = array(
	'complete' => array( 'i-check', __( 'Complete', 'gated-media-access' ) ),
	'refunded' => array( 'i-refund', __( 'Refunded', 'gated-media-access' ) ),
	'active'   => array( 'i-active', __( 'Active', 'gated-media-access' ) ),
	'expired'  => array( 'i-blocked', __( 'Expired', 'gated-media-access' ) ),
	'revoked'  => array( 'i-revoked', __( 'Revoked', 'gated-media-access' ) ),
	// A payment's own two states (Payment::STATUS_*), which §6.6's table
	// predates: an order can be waiting on Stripe or have failed outright.
	'pending'  => array( 'i-clock', __( 'Pending', 'gated-media-access' ) ),
	'failed'   => array( 'i-error', __( 'Failed', 'gated-media-access' ) ),
);

if ( ! isset( $gatedmedia_values[ $gatedmedia_value ] ) ) {
	return;
}

list( $gatedmedia_icon, $gatedmedia_default ) = $gatedmedia_values[ $gatedmedia_value ];

$gatedmedia_label = '' !== (string) ( $attributes['label'] ?? '' )
	? (string) $attributes['label']
	: $gatedmedia_default;
// Block-level host around an inline component — see the button block for why.
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-inline-host' ) ) ); ?>>
	<span class="gatedmedia-status-pill">
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</span>
</div>
