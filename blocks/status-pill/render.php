<?php
/**
 * Status pill: an icon and a label in a small fill.
 *
 * Seven values: five access states plus a payment's own pending and failed. The pill never dims; a refunded or revoked row drops to 60%, which is the row's job.
 *
 * The default label comes from the value, and a caller with its own wording can override it. A value outside the table renders nothing, and `block.json` enumerates the same seven.
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

$gatedmedia_value = isset( $attributes['value'] ) ? (string) $attributes['value'] : 'active';

// Icon and default wording per value.
$gatedmedia_values = array(
	'complete' => array( 'i-check', Labels::text( 'status.complete' ) ),
	'refunded' => array( 'i-refund', Labels::text( 'status.refunded' ) ),
	'active'   => array( 'i-active', Labels::text( 'status.active' ) ),
	'expired'  => array( 'i-blocked', Labels::text( 'status.expired' ) ),
	'revoked'  => array( 'i-revoked', Labels::text( 'status.revoked' ) ),
	// A payment's own two states (Payment::STATUS_*).
	'pending'  => array( 'i-clock', Labels::text( 'status.pending' ) ),
	'failed'   => array( 'i-error', Labels::text( 'status.failed' ) ),
);

if ( ! isset( $gatedmedia_values[ $gatedmedia_value ] ) ) {
	return;
}

list( $gatedmedia_icon, $gatedmedia_default ) = $gatedmedia_values[ $gatedmedia_value ];

$gatedmedia_label = '' !== (string) ( $attributes['label'] ?? '' )
	? (string) $attributes['label']
	: $gatedmedia_default;
// Block-level host around an inline component, as the button block does.
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-inline-host' ) ) ); ?>>
	<span class="gatedmedia-status-pill">
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</span>
</div>
