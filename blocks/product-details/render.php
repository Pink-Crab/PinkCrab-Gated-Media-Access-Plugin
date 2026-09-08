<?php
/**
 * The product page, reached at `/{segment}/{uuid}`.
 *
 * Locked into every product's content and healed by `Product_Route::ensure_block()`, so the product's own template renders it through `the_content`.
 *
 * The title is the theme's: the post renders as itself, and a second h1 here would be one too many.
 *
 * The buy control posts to `admin-post.php` for `Checkout_Action`, coupon and all. Nothing here grants anything; `Checkout` is asked again on submit.
 *
 * @package PinkCrab\Gated_Access
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      The block instance.
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Money;

defined( 'ABSPATH' ) || exit;

// Editor-side the block draws its own form; only the front end composes this.
if ( is_admin() ) {
	return;
}

$gatedmedia_product_id = get_the_ID();

if ( false === $gatedmedia_product_id ) {
	return;
}

/**
 * Supplied by Product_Offer. The defaults render nothing.
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
		'coupon'     => array(),
		'page_url'   => '',
	),
	$gatedmedia_product_id
);

$gatedmedia_state = (string) ( $gatedmedia_data['state'] ?? '' );

if ( '' === $gatedmedia_state ) {
	return;
}

$gatedmedia_body = '';

// A refused checkout comes back here carrying its reason.
if ( '' !== (string) ( $gatedmedia_data['error'] ?? '' ) ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'error',
			'text' => (string) $gatedmedia_data['error'],
		)
	);
}

// What you get, fenced by hairlines, with the description above the first one.
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

// An applied coupon, as Product_Offer priced it. Usually empty.
$gatedmedia_coupon_data = is_array( $gatedmedia_data['coupon'] ?? null ) ? $gatedmedia_data['coupon'] : array();
$gatedmedia_applied     = true === ( $gatedmedia_coupon_data['applied'] ?? false );
$gatedmedia_price       = (int) ( $gatedmedia_data['price'] ?? 0 );
$gatedmedia_currency    = (string) ( $gatedmedia_data['currency'] ?? 'GBP' );

// The price. Held and lapsed still show it; a coupon strikes the full price through as `original`.
$gatedmedia_body .= Block::render(
	'gated-media-access/price-block',
	array(
		'amount'        => $gatedmedia_applied ? (int) $gatedmedia_coupon_data['total'] : $gatedmedia_price,
		'original'      => $gatedmedia_applied ? $gatedmedia_price : 0,
		'currency'      => $gatedmedia_currency,
		'term'          => (string) ( $gatedmedia_data['term'] ?? '' ),
		'notApplicable' => Product_Offer::STATE_HELD === $gatedmedia_state,
	)
);

// The buy form's id, so the pinned bar can submit it from outside with no JavaScript.
$gatedmedia_form_id = 'gatedmedia-buy-' . $gatedmedia_product_id;

// What every buy form carries: the action, its nonce, and the product.
$gatedmedia_fields = sprintf(
	'<input type="hidden" name="action" value="%s" /><input type="hidden" name="_wpnonce" value="%s" /><input type="hidden" name="gatedmedia_product" value="%d" />',
	esc_attr( \PinkCrab\Gated_Access\Payments\Checkout_Action::ACTION ),
	esc_attr( (string) ( $gatedmedia_data['nonce'] ?? '' ) ),
	(int) $gatedmedia_product_id
);

// The action, per state. Only `free` and `paid` offer a control that buys.
if ( Product_Offer::STATE_HELD === $gatedmedia_state ) {
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
			'href'    => Account_Url::section( 'my-access' ),
			'variant' => 'secondary',
		)
	);
} elseif ( Product_Offer::STATE_INELIGIBLE === $gatedmedia_state ) {
	$gatedmedia_body .= Block::render(
		'gated-media-access/notice',
		array(
			'kind' => 'info',
			'text' => __( 'This is only available to invited email addresses. If you were sent an invitation, sign in with that address.', 'gated-media-access' ),
		)
	);
} elseif ( Product_Offer::STATE_SIGNED_OUT === $gatedmedia_state ) {
	// Two controls, two destinations. The submit posts the buy form, so the product and coupon travel with it and `Checkout_Action::require_login()` picks the auth state.
	$gatedmedia_signup_offered = true === ( $gatedmedia_data['signup_offered'] ?? false );

	$gatedmedia_signed_out = Block::render(
		'gated-media-access/button',
		array(
			'label' => $gatedmedia_signup_offered
				? __( 'Create an account to continue', 'gated-media-access' )
				: __( 'Sign in to continue', 'gated-media-access' ),
			'type'  => 'submit',
			'full'  => true,
		)
	);

	if ( $gatedmedia_signup_offered ) {
		$gatedmedia_signed_out .= Block::render(
			'gated-media-access/button',
			array(
				'label'   => __( 'Already have an account? Sign in', 'gated-media-access' ),
				'href'    => Auth_Url::signin( (string) ( $gatedmedia_data['page_url'] ?? '' ) ),
				'variant' => 'secondary',
				'full'    => true,
			)
		);
	}

	$gatedmedia_body .= sprintf(
		'<form id="%s" class="gatedmedia-buy" method="post" action="%s">%s%s</form>',
		esc_attr( $gatedmedia_form_id ),
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields,
		$gatedmedia_signed_out
	);
} else {
	$gatedmedia_free = Product_Offer::STATE_FREE === $gatedmedia_state;

	$gatedmedia_lapsed_notice = Product_Offer::STATE_LAPSED === $gatedmedia_state
		? Block::render(
			'gated-media-access/notice',
			array(
				'kind' => 'info',
				'text' => __( 'Your access to this has ended.', 'gated-media-access' ),
			)
		)
		: '';

	// The coupon is its own GET form: Apply reloads the page with the code in the query and Product_Offer prices it. Checkout judges it again before a penny moves.
	$gatedmedia_page_url = (string) ( $gatedmedia_data['page_url'] ?? '' );
	$gatedmedia_code     = (string) ( $gatedmedia_coupon_data['code'] ?? '' );

	$gatedmedia_coupon = '';

	if ( ! $gatedmedia_free ) {
		// A GET form drops its action's query string, so plain permalinks lose the product. Put those args back as hidden fields.
		$gatedmedia_query  = (string) wp_parse_url( $gatedmedia_page_url, PHP_URL_QUERY );
		$gatedmedia_hidden = '';
		$gatedmedia_args   = array();

		if ( '' !== $gatedmedia_query ) {
			parse_str( $gatedmedia_query, $gatedmedia_args );

			foreach ( $gatedmedia_args as $gatedmedia_key => $gatedmedia_value ) {
				$gatedmedia_hidden .= sprintf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( strval( $gatedmedia_key ) ),
					// An `a[]=` arg parses to an array and is dropped, not guessed.
					esc_attr( is_scalar( $gatedmedia_value ) ? strval( $gatedmedia_value ) : '' )
				);
			}
		}

		$gatedmedia_coupon = sprintf(
			'<form class="gatedmedia-coupon-form" method="get" action="%s">%s%s</form>',
			esc_url( remove_query_arg( array_map( 'strval', array_keys( $gatedmedia_args ) ), $gatedmedia_page_url ) ),
			$gatedmedia_hidden,
			Block::render(
				'gated-media-access/coupon',
				array(
					'label'      => __( 'Coupon code', 'gated-media-access' ),
					'applyLabel' => __( 'Apply', 'gated-media-access' ),
					'code'       => $gatedmedia_code,
					'applied'    => $gatedmedia_applied,
					'discount'   => $gatedmedia_applied
						? Money::format( (int) $gatedmedia_coupon_data['discount'], $gatedmedia_currency )
						: '',
					'error'      => (string) ( $gatedmedia_coupon_data['error'] ?? '' ),
					// Remove is a link back to the page without the code.
					'removeHref' => $gatedmedia_page_url,
				)
			)
		);
	}

	// The code travels with the purchase only if it actually priced this page.
	$gatedmedia_carried = $gatedmedia_applied
		? sprintf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( \PinkCrab\Gated_Access\Payments\Checkout_Action::COUPON_FIELD ),
			esc_attr( $gatedmedia_code )
		)
		: '';

	$gatedmedia_body .= $gatedmedia_lapsed_notice . $gatedmedia_coupon . sprintf(
		'<form id="%s" class="gatedmedia-buy" method="post" action="%s">%s%s%s</form>',
		esc_attr( $gatedmedia_form_id ),
		esc_url( (string) ( $gatedmedia_data['action_url'] ?? '' ) ),
		$gatedmedia_fields,
		$gatedmedia_carried,
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
// The pinned bar carries the priced buy action, so only a product with a price to pay gets one. CSS hides it at wide; this decides whether it exists at all.
$gatedmedia_bar = '';

if ( $gatedmedia_price > 0
	&& in_array( $gatedmedia_state, array( Product_Offer::STATE_PAID, Product_Offer::STATE_LAPSED ), true ) ) {
	$gatedmedia_bar = Block::render(
		'gated-media-access/action-bar',
		array(
			'label' => sprintf(
				/* translators: %s: the price, already formatted. */
				__( 'Get access, %s', 'gated-media-access' ),
				Money::format(
					$gatedmedia_applied ? (int) $gatedmedia_coupon_data['total'] : $gatedmedia_price,
					$gatedmedia_currency
				)
			),
			'form'  => $gatedmedia_form_id,
		)
	);
}

$gatedmedia_wrapper = 'gatedmedia gatedmedia-view gatedmedia-view--product'
	. ( '' === $gatedmedia_bar ? '' : ' has-action-bar' );
?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes( array( 'class' => $gatedmedia_wrapper ) ) ); ?>>
	<?php
	echo $gatedmedia_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the blocks that produced it.
	echo $gatedmedia_bar;  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block output, escaped by the action-bar block.
	?>
</div>
