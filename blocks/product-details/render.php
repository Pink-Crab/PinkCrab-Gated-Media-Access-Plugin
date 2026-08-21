<?php
/**
 * §7.6 Product — the page a visitor reaches at `/{segment}/{uuid}`.
 *
 * The block is locked into every product's content (`Post_Types`' template,
 * healed by `Product_Route::ensure_block()`), so the product's own single
 * template renders this through `the_content` — there is no separate route or
 * page for a product, only its post.
 *
 * **The title is the theme's.** The post is rendered as itself, so the theme
 * has already printed the product's title as the page heading; printing our
 * own would put two h1s on the page, which §2 conflict 4 settled against.
 *
 * The buy control is a form posting to `admin-post.php` — `Checkout_Action`
 * takes it from there, with the coupon riding along in the same submit. The
 * state decides what is offered; `Checkout` decides what may actually happen,
 * and is asked again on submit. Nothing here grants anything.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Products\Product_View_Data;
use PinkCrab\Gated_Access\Support\Block;

defined( 'ABSPATH' ) || exit;

// Editor-side this block draws its own form; only the front end composes §7.6.
if ( is_admin() ) {
	return;
}

$gatedmedia_product_id = get_the_ID();

if ( false === $gatedmedia_product_id ) {
	return;
}

/**
 * Supplied by Product_View_Data; the defaults are what an unknown product
 * renders, which is nothing at all.
 *
 * @var array<string, mixed> $gatedmedia_data
 */
$gatedmedia_data = apply_filters(
	'gatedmedia_product_data',
	array(
		'product_id' => 0,
		'state'      => '',
		'items'      => array(),
		'price'      => 0,
		'currency'   => 'GBP',
		'term'       => '',
		'nonce'      => '',
		'action_url' => '',
		'error'      => '',
	),
	$gatedmedia_product_id
);

$gatedmedia_state = (string) ( $gatedmedia_data['state'] ?? '' );

if ( '' === $gatedmedia_state ) {
	return;
}

$gatedmedia_body = '';

// A refused checkout comes back here carrying its reason (§6.4).
if ( '' !== (string) ( $gatedmedia_data['error'] ?? '' ) ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'error',
			'text' => (string) $gatedmedia_data['error'],
		)
	);
}

// What you get (§6.12), fenced by hairlines as the design draws it — the
// product's own description sits above the first one.
$gatedmedia_items = is_array( $gatedmedia_data['items'] ?? null ) ? $gatedmedia_data['items'] : array();

if ( array() !== $gatedmedia_items ) {
	$gatedmedia_body .= '<hr class="gatedmedia-rule" />'
		. '<section class="gatedmedia-section">'
		. Block::render(
			'gated-media-access/section-heading',
			array( 'text' => __( 'What you get', 'gated-media-access' ) )
		)
		. Block::render(
			'gated-media-access/contents',
			array( 'items' => $gatedmedia_items )
		)
		. '</section>'
		. '<hr class="gatedmedia-rule" />';
}

// The price (§6.13). A held or lapsed page still shows what it costs.
$gatedmedia_body .= Block::render(
	'gated-media-access/price-block',
	array(
		'amount'        => (int) ( $gatedmedia_data['price'] ?? 0 ),
		'currency'      => (string) ( $gatedmedia_data['currency'] ?? 'GBP' ),
		'term'          => (string) ( $gatedmedia_data['term'] ?? '' ),
		'notApplicable' => Product_View_Data::STATE_HELD === $gatedmedia_state,
	)
);

// What every buy form carries: the action Checkout_Action answers to, its
// nonce, and which product is being bought.
$gatedmedia_fields = sprintf(
	'<input type="hidden" name="action" value="%s" /><input type="hidden" name="_wpnonce" value="%s" /><input type="hidden" name="gatedmedia_product" value="%d" />',
	esc_attr( \PinkCrab\Gated_Access\Payments\Checkout_Action::ACTION ),
	esc_attr( (string) ( $gatedmedia_data['nonce'] ?? '' ) ),
	(int) $gatedmedia_product_id
);

// -----------------------------------------------------------------------
// The action, per state. Only `free` and `paid` offer a control that buys;
// the rest explain why there is nothing to press.
// -----------------------------------------------------------------------
if ( Product_View_Data::STATE_HELD === $gatedmedia_state ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'success',
			'text' => __( 'You already have this.', 'gated-media-access' ),
		)
	) . Block::render(
		'gated-media-access/button',
		array(
			'label'   => __( 'View your access', 'gated-media-access' ),
			'href'    => home_url( '/account/my-access/' ),
			'variant' => 'secondary',
		)
	);
} elseif ( Product_View_Data::STATE_INELIGIBLE === $gatedmedia_state ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'text' => __( 'This is only available to invited email addresses. If you were sent an invitation, sign in with that address.', 'gated-media-access' ),
		)
	);
} elseif ( Product_View_Data::STATE_SIGNED_OUT === $gatedmedia_state ) {
	// The buy form posts signed out too — Checkout_Action's nopriv mirror
	// sends them through wp-login and back to finish. So the control is real
	// rather than a link that loses the product on the way.
	$gatedmedia_signed_out = Block::render(
		'gated-media-access/button',
		array(
			'label' => __( 'Create an account to continue', 'gated-media-access' ),
			'type'  => 'submit',
			'full'  => true,
		)
	) . Block::render(
		'gated-media-access/button',
		array(
			'label'   => __( 'Already have an account? Sign in', 'gated-media-access' ),
			'href'    => wp_login_url( (string) get_permalink( $gatedmedia_product_id ) ),
			'variant' => 'secondary',
			'full'    => true,
		)
	);

	$gatedmedia_body .= sprintf(
		'<form class="gatedmedia-buy" method="post" action="%s">%s%s</form>',
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields,
		$gatedmedia_signed_out
	);
} else {
	$gatedmedia_free = Product_View_Data::STATE_FREE === $gatedmedia_state;

	$gatedmedia_lapsed_notice = Product_View_Data::STATE_LAPSED === $gatedmedia_state
		? Block::render(
			'gated-media-access/notice',
			array(
				'kind' => 'info',
				'text' => __( 'Your access to this has ended.', 'gated-media-access' ),
			)
		)
		: '';

	$gatedmedia_coupon = $gatedmedia_free
		? ''
		: Block::render(
			'gated-media-access/coupon',
			array(
				'label'      => __( 'Coupon code', 'gated-media-access' ),
				'applyLabel' => __( 'Apply', 'gated-media-access' ),
			)
		);

	$gatedmedia_body .= $gatedmedia_lapsed_notice . sprintf(
		'<form class="gatedmedia-buy" method="post" action="%s">%s%s%s</form>',
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields,
		$gatedmedia_coupon,
		Block::render(
			'gated-media-access/button',
			array(
				'label' => $gatedmedia_free
					? __( 'Join', 'gated-media-access' )
					: __( 'Get access', 'gated-media-access' ),
				'type'  => 'submit',
				'full'  => true,
			)
		)
	);
}
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => 'gatedmedia gatedmedia-view gatedmedia-view--product' ) ) ); ?>>
	<?php echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it. ?>
</div>
