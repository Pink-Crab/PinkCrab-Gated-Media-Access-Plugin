<?php
/**
 * §6.14 Coupon field — a labelled input paired with an action.
 *
 * Applied is the state most easily got wrong: §6.14 says the pair is
 * **replaced** by a confirmation line — check icon, the code and what it took
 * off, and a Remove text link on the right. Not annotated, not disabled with a
 * tick beside it. Replaced.
 *
 * Rejected takes the invalid treatment from §6.8, which the field block owns,
 * so passing `error` through is the whole of it.
 *
 * Wide puts input and Apply side by side; narrow stacks them full width. That
 * is CSS on `__row`, not two renderings.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

$gatedmedia_code = isset( $attributes['code'] ) ? (string) $attributes['code'] : '';

// -----------------------------------------------------------------------------
// Applied — the input and its button are gone, not decorated.
// -----------------------------------------------------------------------------
if ( true === ( $attributes['applied'] ?? false ) && '' !== $gatedmedia_code ) {
	$gatedmedia_discount = isset( $attributes['discount'] ) ? (string) $attributes['discount'] : '';

	$gatedmedia_remove = do_blocks(
		sprintf(
			'<!-- wp:gated-media-access/button %s /-->',
			(string) wp_json_encode(
				array(
					'label'   => __( 'Remove', 'gated-media-access' ),
					'href'    => isset( $attributes['removeHref'] ) ? (string) $attributes['removeHref'] : '',
					'variant' => 'link',
				)
			)
		)
	);
	?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-coupon' ) ) ); ?>>
	<p class="gatedmedia-coupon__applied">
		<svg class="gatedmedia-icon gatedmedia-icon--small" aria-hidden="true" focusable="false"><use href="#i-success"></use></svg>
		<span>
			<?php
			echo esc_html(
				'' !== $gatedmedia_discount
					/* translators: 1: coupon code, 2: what it took off, e.g. £12.25. */
					? sprintf( __( '%1$s applied — %2$s off', 'gated-media-access' ), $gatedmedia_code, $gatedmedia_discount )
					/* translators: %s: coupon code. */
					: sprintf( __( '%s applied', 'gated-media-access' ), $gatedmedia_code )
			);
			?>
		</span>
		<?php echo $gatedmedia_remove; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block. ?>
	</p>
</div>
	<?php
	return;
}

$gatedmedia_label = isset( $attributes['label'] ) && '' !== $attributes['label']
	? (string) $attributes['label']
	: __( 'Coupon code', 'gated-media-access' );

$gatedmedia_apply = isset( $attributes['applyLabel'] ) && '' !== $attributes['applyLabel']
	? (string) $attributes['applyLabel']
	: __( 'Apply', 'gated-media-access' );

$gatedmedia_field = do_blocks(
	sprintf(
		'<!-- wp:gated-media-access/field %s /-->',
		(string) wp_json_encode(
			array(
				'name'  => 'gatedmedia-coupon',
				'label' => $gatedmedia_label,
				'value' => $gatedmedia_code,
				'error' => isset( $attributes['error'] ) ? (string) $attributes['error'] : '',
			)
		)
	)
);

$gatedmedia_button = do_blocks(
	sprintf(
		'<!-- wp:gated-media-access/button %s /-->',
		(string) wp_json_encode(
			array(
				'label'   => $gatedmedia_apply,
				'variant' => 'secondary',
				'type'    => 'submit',
			)
		)
	)
);
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia-coupon' ) ) ); ?>>
	<div class="gatedmedia-coupon__row">
		<?php
		echo $gatedmedia_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the field block.
		echo $gatedmedia_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the button block.
		?>
	</div>
</div>
