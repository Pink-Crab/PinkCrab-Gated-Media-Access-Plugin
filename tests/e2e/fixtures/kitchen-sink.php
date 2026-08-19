<?php
/**
 * Creates the page the component e2e tests run against.
 *
 * Every §6 component, in the states §6 documents, on an ordinary page — which
 * is the point. The account route wraps everything in the shell, so a suite
 * that only visits `/account/` proves nothing about a component standing on
 * its own. Three faults reached the browser that way.
 *
 * Run by tests/e2e/global-setup.js before the suite, and idempotent: it
 * updates the page if it is already there.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

/**
 * One block comment.
 *
 * JSON_UNESCAPED_UNICODE matters: without it "·" is written as a \u escape,
 * and the block comment carries the escape through to the page as literal
 * text — "PDF u00b7 4.2 MB".
 *
 * @param string               $name  Block name, without the namespace.
 * @param array<string, mixed> $attrs Attributes.
 * @param string               $inner Inner block markup.
 */
function gatedmedia_fixture_block( string $name, array $attrs = array(), string $inner = '' ): string {
	$json = array() === $attrs ? '' : ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_UNICODE );
	$full = 'gated-media-access/' . $name;

	return '' === $inner
		? sprintf( "<!-- wp:%s%s /-->\n", $full, $json )
		: sprintf( "<!-- wp:%1\$s%2\$s -->%3\$s<!-- /wp:%1\$s -->\n", $full, $json, $inner );
}

/**
 * A labelled group of specimens, with a rule beneath.
 *
 * @param string $label What is being shown.
 * @param string $body  The specimens.
 */
function gatedmedia_fixture_group( string $label, string $body ): string {
	return gatedmedia_fixture_block( 'section-heading', array( 'text' => $label ) ) . $body
		. "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n";
}

$content = '';

// §6.3 Buttons — every variant, plus the icon and full-width forms.
$content .= gatedmedia_fixture_group(
	'6.3 Buttons',
	gatedmedia_fixture_block( 'button', array( 'label' => 'Primary' ) )
	. gatedmedia_fixture_block( 'button', array( 'label' => 'Secondary', 'variant' => 'secondary' ) )
	. gatedmedia_fixture_block( 'button', array( 'label' => 'Text link', 'variant' => 'link', 'href' => '#' ) )
	. gatedmedia_fixture_block( 'button', array( 'label' => 'With icon', 'icon' => 'i-download', 'variant' => 'secondary' ) )
	. gatedmedia_fixture_block( 'button', array( 'label' => 'Full width', 'full' => true ) )
);

// §6.4 Notice — three kinds, and the dismissible form.
$content .= gatedmedia_fixture_group(
	'6.4 Notice',
	gatedmedia_fixture_block( 'notice', array( 'kind' => 'info', 'text' => 'Information. Your profile is incomplete.' ) )
	. gatedmedia_fixture_block( 'notice', array( 'kind' => 'error', 'text' => 'Error. That payment could not be taken.' ) )
	. gatedmedia_fixture_block( 'notice', array( 'kind' => 'success', 'text' => 'Success. Your details have been saved.' ) )
	. gatedmedia_fixture_block( 'notice', array( 'kind' => 'info', 'text' => 'Dismissible — carries a close button.', 'dismissible' => true ) )
);

// §6.5 Expiry — four states, and the chip form.
$content .= gatedmedia_fixture_group(
	'6.5 Expiry',
	gatedmedia_fixture_block( 'expiry', array( 'state' => 'lifetime', 'label' => 'Lifetime' ) )
	. gatedmedia_fixture_block( 'expiry', array( 'state' => 'dated', 'label' => 'Expires 12 March 2027' ) )
	. gatedmedia_fixture_block( 'expiry', array( 'state' => 'soon', 'label' => 'Expires in 3 days' ) )
	. gatedmedia_fixture_block( 'expiry', array( 'state' => 'expired', 'label' => 'Expired 1 May 2025' ) )
	. gatedmedia_fixture_block( 'expiry', array( 'state' => 'dated', 'label' => 'Chip form', 'chip' => true ) )
);

// §6.6 Status pill — all five values.
$pills = '';
foreach ( array( 'complete', 'refunded', 'active', 'expired', 'revoked' ) as $value ) {
	$pills .= gatedmedia_fixture_block( 'status-pill', array( 'value' => $value ) );
}
$content .= gatedmedia_fixture_group( '6.6 Status pill', $pills );

// §6.7 Price — the four forms.
$content .= gatedmedia_fixture_group(
	'6.7 Price, inline',
	gatedmedia_fixture_block( 'price', array( 'amount' => 4900 ) )
	. gatedmedia_fixture_block( 'price', array( 'amount' => 3675, 'original' => 4900 ) )
	. gatedmedia_fixture_block( 'price', array( 'amount' => 0 ) )
	. gatedmedia_fixture_block( 'price', array( 'notApplicable' => true ) )
);

// §6.13 Price block — including Free, which must never be an amount.
$content .= gatedmedia_fixture_group(
	'6.13 Price block',
	gatedmedia_fixture_block( 'price-block', array( 'amount' => 4900, 'term' => 'One year' ) )
	. gatedmedia_fixture_block( 'price-block', array( 'amount' => 3675, 'original' => 4900, 'term' => 'One year' ) )
	. gatedmedia_fixture_block( 'price-block', array( 'amount' => 0, 'term' => 'Lifetime' ) )
);

// §6.8 Field — including the invalid state, which the tests assert on.
$content .= gatedmedia_fixture_group(
	'6.8 Form field',
	gatedmedia_fixture_block( 'field', array( 'name' => 'sink-name', 'label' => 'First name', 'value' => 'Glynn' ) )
	. gatedmedia_fixture_block( 'field', array( 'name' => 'sink-help', 'label' => 'Email', 'value' => 'a@b.com', 'message' => 'We never share this.' ) )
	. gatedmedia_fixture_block( 'field', array( 'name' => 'sink-bad', 'label' => 'Postcode', 'value' => 'XX', 'error' => 'That postcode is not valid.' ) )
	. gatedmedia_fixture_block( 'field', array( 'name' => 'sink-off', 'label' => 'Account email', 'value' => 'you@example.com', 'disabled' => true, 'message' => 'Cannot be changed here.' ) )
	. gatedmedia_fixture_block( 'field', array( 'name' => 'sink-area', 'label' => 'Notes', 'multiline' => true ) )
);

// §6.2 Row — four states, and the aside composed from other components.
$content .= gatedmedia_fixture_group(
	'6.2 Row',
	gatedmedia_fixture_block(
		'row',
		array( 'title' => 'Annual report.pdf', 'meta' => 'PDF · 4.2 MB' ),
		gatedmedia_fixture_block( 'expiry', array( 'state' => 'lifetime', 'label' => 'Lifetime' ) )
	)
	. gatedmedia_fixture_block(
		'row',
		array( 'title' => 'Q3 deck.zip', 'meta' => 'ZIP · 18 MB' ),
		gatedmedia_fixture_block( 'expiry', array( 'state' => 'soon', 'label' => 'Expires in 3 days' ) )
		. gatedmedia_fixture_block( 'button', array( 'label' => 'Download', 'variant' => 'secondary', 'icon' => 'i-download' ) )
	)
	. gatedmedia_fixture_block( 'row', array( 'title' => 'Removed file.pdf', 'state' => 'unavailable' ) )
	. gatedmedia_fixture_block( 'row', array( 'state' => 'loading' ) )
	. gatedmedia_fixture_block(
		'row',
		array( 'title' => 'Order #1042', 'meta' => '4 March 2026', 'variant' => 'order' ),
		gatedmedia_fixture_block( 'price', array( 'amount' => 3675, 'original' => 4900 ) )
		. gatedmedia_fixture_block( 'status-pill', array( 'value' => 'complete' ) )
	)
);

// §6.1 Account nav — both variants; CSS shows one per width.
$items = array(
	array( 'label' => 'My Access', 'href' => '#', 'icon' => 'i-access', 'active' => true ),
	array( 'label' => 'Files', 'href' => '#', 'icon' => 'i-files' ),
	array( 'label' => 'Orders', 'href' => '#', 'icon' => 'i-orders' ),
	array( 'label' => 'Profile', 'href' => '#', 'icon' => 'i-profile' ),
);
$content .= gatedmedia_fixture_group(
	'6.1 Account nav',
	gatedmedia_fixture_block( 'account-nav', array( 'items' => $items, 'variant' => 'sidebar' ) )
	. gatedmedia_fixture_block( 'account-nav', array( 'items' => $items, 'variant' => 'tabs' ) )
);

// §6.11 Filter — search plus the type list, drawn as select and chips.
$content .= gatedmedia_fixture_group(
	'6.11 Filter',
	gatedmedia_fixture_block(
		'filter',
		array(
			'searchLabel' => 'Search files',
			'types'       => array(
				array( 'value' => 'all', 'label' => 'All' ),
				array( 'value' => 'pdf', 'label' => 'PDF' ),
				array( 'value' => 'video', 'label' => 'Video' ),
				array( 'value' => 'zip', 'label' => 'ZIP' ),
			),
			'active'      => 'all',
		)
	)
);

// §6.12 Contents list, with its frozen-on-date note.
$content .= gatedmedia_fixture_group(
	'6.12 Contents list',
	gatedmedia_fixture_block(
		'contents',
		array(
			'items' => array(
				array( 'icon' => 'i-doc', 'text' => '12 documents' ),
				array( 'icon' => 'i-video', 'text' => '3 videos' ),
				array( 'icon' => 'i-groups', 'text' => 'The Research group' ),
			),
			'note'  => 'Contents as they were on the order date.',
		)
	)
);

// §6.16 Summary — counts phrased on the server.
$content .= gatedmedia_fixture_group(
	'6.16 Summary',
	gatedmedia_fixture_block(
		'summary',
		array(
			'counts' => array(
				array( 'type' => 'file', 'count' => 12 ),
				array( 'type' => 'post', 'count' => 1 ),
			),
		)
	)
);

// §6.14 Coupon — waiting, rejected, applied.
$content .= gatedmedia_fixture_group(
	'6.14 Coupon field',
	gatedmedia_fixture_block( 'coupon', array() )
	. gatedmedia_fixture_block( 'coupon', array( 'code' => 'BAD', 'error' => 'That code is not recognised.' ) )
	. gatedmedia_fixture_block( 'coupon', array( 'code' => 'SAVE25', 'applied' => true, 'discount' => '£12.25', 'removeHref' => '#' ) )
);

// §6.10 Empty state.
$content .= gatedmedia_fixture_group(
	'6.10 Empty state',
	gatedmedia_fixture_block(
		'empty-state',
		array(
			'icon'    => 'i-files',
			'title'   => 'No files yet',
			'message' => 'Files you are given access to will appear here, ready to download.',
		)
	)
);

// §6.15 Pinned action bar — narrow only.
$content .= gatedmedia_fixture_group(
	'6.15 Pinned action bar',
	gatedmedia_fixture_block( 'action-bar', array( 'label' => 'Get access — £49.00', 'href' => '#' ) )
);

// §7 — the four section views, which draw the viewer's own record.
foreach ( array(
	'7.1 My Access' => 'my-access',
	'7.2 Files'     => 'files',
	'7.3 Orders'    => 'orders',
	'7.5 Profile'   => 'profile',
) as $label => $block ) {
	$content .= gatedmedia_fixture_group( $label . ' (section view)', gatedmedia_fixture_block( $block ) );
}

$existing = get_page_by_path( 'component-kitchen-sink', OBJECT, 'page' );

$args = array(
	'post_title'   => 'Component kitchen sink',
	'post_name'    => 'component-kitchen-sink',
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_content' => $content,
);

if ( $existing instanceof WP_Post ) {
	$args['ID'] = $existing->ID;
	$id         = wp_update_post( $args );
} else {
	$id = wp_insert_post( $args );
}

echo is_wp_error( $id )
	? 'FAILED: ' . $id->get_error_message() . "\n"
	: 'Fixture ready: ' . get_permalink( $id ) . "\n";
