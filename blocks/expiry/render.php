<?php
/**
 * §6.5 Expiry — an icon and a label, 8px apart.
 *
 * Four states, each with its own icon, and only one of them coloured: expiring
 * soon is `error`, expired is muted to 60%. The other two are ordinary text.
 *
 * `chip` switches to the filled form. §8 conflict 7 settled where each is used
 * — bare in lists, chip in detail panels — because a list is already busy with
 * rules and buttons and a detail panel is not.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_label = isset( $attributes['label'] ) ? (string) $attributes['label'] : '';

if ( '' === $gatedmedia_label ) {
	return;
}

$gatedmedia_state = isset( $attributes['state'] ) ? (string) $attributes['state'] : 'lifetime';

// Icon and modifier per state, from §6.5's table. Anything unrecognised falls
// back to the dated form rather than rendering without an icon.
$gatedmedia_states = array(
	'lifetime' => array( 'i-infinity', '' ),
	'dated'    => array( 'i-calendar', '' ),
	'soon'     => array( 'i-warning', 'gatedmedia-expiry--warning' ),
	'expired'  => array( 'i-blocked', 'gatedmedia-expiry--expired' ),
);

list( $gatedmedia_icon, $gatedmedia_modifier ) = $gatedmedia_states[ $gatedmedia_state ]
	?? $gatedmedia_states['dated'];

$gatedmedia_classes = array( 'gatedmedia-expiry' );

if ( '' !== $gatedmedia_modifier ) {
	$gatedmedia_classes[] = $gatedmedia_modifier;
}

if ( true === ( $attributes['chip'] ?? false ) ) {
	$gatedmedia_classes[] = 'gatedmedia-expiry--chip';
}
// Block-level host around an inline component, so it lands in the content
// column when placed on its own — a theme centres a top-level block with
// `margin-inline: auto`, which does nothing to an inline element.
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-inline-host' ) ) ); ?>>
	<span class="<?php echo esc_attr( implode( ' ', $gatedmedia_classes ) ); ?>">
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>
		<span><?php echo esc_html( $gatedmedia_label ); ?></span>
	</span>
</div>
