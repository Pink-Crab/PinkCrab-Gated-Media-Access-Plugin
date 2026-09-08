<?php
/**
 * Notice: a bordered, tinted box.
 *
 * Tinted rather than border-only, because a border-only box in a page already built from hairlines does not read as a distinct thing.
 *
 * Three kinds, each with its own icon, border and fill. The error kind also colours its text.
 *
 * **The dismiss button is rendered only when the notice is dismissible**, and that is load-bearing: forced profile completion is defined by being the notice you cannot dismiss. The JS attaches to whatever close buttons exist, so an undismissible notice is undismissible by having no button rather than by a flag.
 *
 * Inner blocks fill the body where a notice contains something like a text link, and the `text` attribute is the shorthand for the common case.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_text = isset( $attributes['text'] ) ? (string) $attributes['text'] : '';
$gatedmedia_body = '' !== trim( $content ) ? $content : '';

if ( '' === $gatedmedia_text && '' === $gatedmedia_body ) {
	return;
}

$gatedmedia_kind = isset( $attributes['kind'] ) ? (string) $attributes['kind'] : 'info';

// Icon and modifier per kind.
$gatedmedia_kinds = array(
	'info'    => array( 'i-info', '' ),
	'error'   => array( 'i-error', 'gatedmedia-notice--error' ),
	'success' => array( 'i-success', 'gatedmedia-notice--success' ),
);

list( $gatedmedia_icon, $gatedmedia_modifier ) = $gatedmedia_kinds[ $gatedmedia_kind ]
	?? $gatedmedia_kinds['info'];

if ( '' !== (string) ( $attributes['icon'] ?? '' ) ) {
	$gatedmedia_icon = (string) $attributes['icon'];
}

$gatedmedia_classes = 'gatedmedia-notice' . ( '' !== $gatedmedia_modifier ? ' ' . $gatedmedia_modifier : '' );
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $gatedmedia_classes ) ) ); ?>>
	<svg class="gatedmedia-icon gatedmedia-notice__icon" aria-hidden="true" focusable="false"><use href="#<?php echo esc_attr( $gatedmedia_icon ); ?>"></use></svg>

	<div class="gatedmedia-notice__body">
		<?php
		if ( '' !== $gatedmedia_body ) {
			echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inner block output, already escaped by the blocks that produced it.
		} else {
			echo esc_html( $gatedmedia_text );
		}
		?>
	</div>

	<?php if ( true === ( $attributes['dismissible'] ?? false ) ) : ?>
	<button
		type="button"
		class="gatedmedia-notice__dismiss"
		aria-label="<?php esc_attr_e( 'Dismiss', 'gated-media-access' ); ?>"
	>&times;</button>
	<?php endif; ?>
</div>
