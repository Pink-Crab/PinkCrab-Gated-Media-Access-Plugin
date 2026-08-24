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

use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Money;

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
 * Supplied by Product_Offer; the defaults are what an unknown product
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

// An applied coupon, as Product_Offer priced it. Empty on every page that
// was not asked for one, which is the ordinary case.
$gatedmedia_coupon_data = is_array( $gatedmedia_data['coupon'] ?? null ) ? $gatedmedia_data['coupon'] : array();
$gatedmedia_applied     = true === ( $gatedmedia_coupon_data['applied'] ?? false );
$gatedmedia_price       = (int) ( $gatedmedia_data['price'] ?? 0 );
$gatedmedia_currency    = (string) ( $gatedmedia_data['currency'] ?? 'GBP' );

// The price (§6.13). A held or lapsed page still shows what it costs. With a
// coupon on it, the total is the price and the full price is struck through —
// which is what `original` is for.
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

// The buy form's id, so §6.15's pinned bar can submit it from outside. A
// button may name the form it submits, which keeps the bar working with no
// JavaScript and without a second copy of the hidden fields.
$gatedmedia_form_id = 'gatedmedia-buy-' . $gatedmedia_product_id;

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
	// Two controls, two destinations. Before round 9 both of these resolved to
	// the same `wp_login_url()` — one button said "create an account" and did
	// the same thing as the one beside it, on a site whose account route did
	// not exist at all.
	//
	// The submit still posts the buy form, so the product and the typed coupon
	// travel with it and `Checkout_Action::require_login()` decides which state
	// of §7.7 to open. Where this site does not create accounts on the front
	// end, it does not offer to.
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

	// -------------------------------------------------------------------
	// §6.14 — the coupon is its own GET form, not part of the buy form.
	//
	// Apply used to be a submit inside the buy form, so pressing it went to
	// Stripe at full price. It now reloads this page with the code in the
	// query; Product_Offer prices it and the state below is drawn from that.
	// A form cannot nest inside a form, which is why it sits outside rather
	// than carrying a second action.
	//
	// Nothing here is trusted: the code rides the buy submit as a hidden
	// field and Checkout judges it again before a penny moves.
	// -------------------------------------------------------------------
	$gatedmedia_page_url = (string) ( $gatedmedia_data['page_url'] ?? '' );
	$gatedmedia_code     = (string) ( $gatedmedia_coupon_data['code'] ?? '' );

	$gatedmedia_coupon = '';

	if ( ! $gatedmedia_free ) {
		// A GET form throws away whatever query string its action carries, so
		// on plain permalinks the product itself would be lost. Put those args
		// back as hidden fields; on pretty permalinks there are none.
		$gatedmedia_query  = (string) wp_parse_url( $gatedmedia_page_url, PHP_URL_QUERY );
		$gatedmedia_hidden = '';
		$gatedmedia_args   = array();

		if ( '' !== $gatedmedia_query ) {
			parse_str( $gatedmedia_query, $gatedmedia_args );

			foreach ( $gatedmedia_args as $gatedmedia_key => $gatedmedia_value ) {
				$gatedmedia_hidden .= sprintf(
					'<input type="hidden" name="%s" value="%s" />',
					esc_attr( strval( $gatedmedia_key ) ),
					// An `a[]=` style arg parses to an array; it has no place
					// in a product permalink and is dropped rather than guessed.
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

	// The applied code travels with the purchase. Only when it actually
	// priced this page — a rejected code must not be smuggled to checkout.
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
// -----------------------------------------------------------------------------
// §6.15 — the pinned bar, narrow only, and only where there is something to
// press. Held and ineligible offer no purchase, so they get no bar: CSS hides
// it at wide, and this decides whether it exists at all.
//
// §6.15 is explicit that it must not appear on account views. Nothing but the
// composer can enforce that, and this is the only view that composes it.
// -----------------------------------------------------------------------------
// §6.15 pins the *priced* buy action: the price is in the button's own label,
// which is the whole shape of it. So a product with a price to pay gets the
// bar, and the states that have no price to show do not — signed out, where
// the action is making an account, and free, where "Join" would simply repeat
// the button already on screen under the same name.
$gatedmedia_bar = '';

if ( $gatedmedia_price > 0
	&& in_array( $gatedmedia_state, array( Product_Offer::STATE_PAID, Product_Offer::STATE_LAPSED ), true ) ) {
	$gatedmedia_bar = Block::render(
		'gated-media-access/action-bar',
		array(
			'label' => sprintf(
				/* translators: %s: the price, already formatted. */
				__( 'Get access — %s', 'gated-media-access' ),
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
