<?php
/**
 * Creates what the round 7 e2e specs buy, hold and look at.
 *
 * A priced product, a free one, a group the admin holds, and a completed
 * payment against the priced product — enough for the product page's states,
 * the orders list, one order's detail and a group's contents to be walked in a
 * real browser.
 *
 * **The block delimiter is written into the product's content deliberately.**
 * `Product_Route::ensure_block()` only injects it into the REST response the
 * editor loads, so it reaches `post_content` when a person saves the product.
 * A product created here has never been near the editor, and without the
 * delimiter `do_blocks()` has nothing to render — the page would come back
 * blank and the specs would be testing an empty div.
 *
 * Run by tests/e2e/global-setup.js before the suite, and idempotent: existing
 * fixtures are reused rather than duplicated.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Payments\Payment;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * A product, created once and reused.
 *
 * @param string $slug   Its post_name, so the fixture can find it again.
 * @param string $title  Its title.
 * @param int    $price  Minor units; 0 for a free product.
 * @param array  $items  `type:identifier` strings the product grants.
 * @return int The product's ID.
 */
function gatedmedia_shop_product( string $slug, string $title, int $price, array $items ): int {
	$existing = get_page_by_path( $slug, OBJECT, Post_Types::PRODUCT );

	$product_id = $existing instanceof WP_Post
		? $existing->ID
		: (int) wp_insert_post(
			array(
				'post_type'    => Post_Types::PRODUCT,
				'post_status'  => 'publish',
				'post_name'    => $slug,
				'post_title'   => $title,
				// See the note above: without this the page renders nothing.
				'post_content' => '<!-- wp:gated-media-access/product-details {"lock":{"move":true,"remove":true}} /-->'
					. "\n\n"
					. '<!-- wp:paragraph --><p>Everything the quarter produced, in one place.</p><!-- /wp:paragraph -->',
			)
		);

	update_post_meta( $product_id, Product_Meta::META_PRICE, $price );
	update_post_meta( $product_id, Product_Meta::META_CURRENCY, 'GBP' );
	update_post_meta( $product_id, Product_Meta::META_VISIBILITY, 'listed' );

	delete_post_meta( $product_id, Product_Meta::META_ITEMS );

	foreach ( $items as $item ) {
		add_post_meta( $product_id, Product_Meta::META_ITEMS, $item );
	}

	// Mints the uuid the product's only URL is built from.
	Uuid::ensure( 'post', $product_id );

	return $product_id;
}

// The thing both products grant.
$shop_post    = get_page_by_path( 'e2e-shop-post', OBJECT, 'post' );
$shop_post_id = $shop_post instanceof WP_Post
	? $shop_post->ID
	: (int) wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_name'    => 'e2e-shop-post',
			'post_title'   => 'The quarterly briefing',
			'post_content' => 'Members only.',
		)
	);

// The order's own item. It must be something nothing else grants: a grant for
// an item the person already holds on a live dated record is *stacked* onto
// that record (`Access_Writer::stack_onto_live()`) rather than written as a new
// one, so the order would create no record carrying its reference and "Access
// this created" would be empty. Sharing a post here made the specs pass on a
// clean database and fail on a developer's.
$order_post    = get_page_by_path( 'e2e-order-post', OBJECT, 'post' );
$order_post_id = $order_post instanceof WP_Post
	? $order_post->ID
	: (int) wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_name'    => 'e2e-order-post',
			'post_title'   => 'The Q3 dataset',
			'post_content' => 'Bought, not given.',
		)
	);

$paid_id = gatedmedia_shop_product( 'e2e-paid-product', 'Q3 market report bundle', 4900, array( 'post:' . $order_post_id ) );
$free_id = gatedmedia_shop_product( 'e2e-free-product', 'The standing invitation', 0, array( 'post:' . $shop_post_id ) );

// A product granting something nobody is given, so its page always shows the
// buy form. The paid product above is bought by the order below, which makes
// its page read "you already have this" — correct, and useless for testing the
// form.
$unheld_post    = get_page_by_path( 'e2e-unheld-post', OBJECT, 'post' );
$unheld_post_id = $unheld_post instanceof WP_Post
	? $unheld_post->ID
	: (int) wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_name'    => 'e2e-unheld-post',
			'post_title'   => 'The unsold annexe',
			'post_content' => 'Nobody has this.',
		)
	);

$buy_id = gatedmedia_shop_product( 'e2e-buyable-product', 'The unsold annexe bundle', 1500, array( 'post:' . $unheld_post_id ) );

// Somebody who holds nothing and has bought nothing, so the empty state has a
// place to be seen. The admin cannot serve: this fixture gives them a group and
// an order, which is the whole point of them.
$empty = get_user_by( 'login', 'e2e-empty' );

if ( ! $empty instanceof WP_User ) {
	wp_insert_user(
		array(
			'user_login' => 'e2e-empty',
			'user_pass'  => 'e2e-empty-password',
			'user_email' => 'e2e-empty@example.test',
			'role'       => 'subscriber',
		)
	);
}

// An administrator to own the order and the group.
$admin = get_user_by( 'login', 'admin' );

if ( ! $admin instanceof WP_User ) {
	return;
}

$taxonomy = new Access_Taxonomy();
$writer   = new Access_Writer( new Access_Validator( $taxonomy ), new Access_Lookup() );

// A group with something in it, held outright.
$group = get_term_by( 'name', 'E2E Briefings', Access_Taxonomy::TAXONOMY );

if ( ! $group instanceof WP_Term ) {
	$created = wp_insert_term( 'E2E Briefings', Access_Taxonomy::TAXONOMY );
	$group   = is_array( $created ) ? get_term( $created['term_id'], Access_Taxonomy::TAXONOMY ) : null;
}

if ( $group instanceof WP_Term ) {
	wp_set_object_terms( $shop_post_id, array( $group->term_id ), Access_Taxonomy::TAXONOMY, true );

	$group_uuid  = $taxonomy->uuid_for( $group->term_id );
	$held_group  = ( new Access_Lookup() )->records_for_item( $admin->ID, 'group', $group_uuid );

	if ( array() === $held_group ) {
		$granted = $writer->grant( $admin->ID, 'group', $group_uuid, null, 'admin', 'e2e-fixture' );

		if ( is_wp_error( $granted ) ) {
			echo 'FAILED granting the group: ' . $granted->get_error_message() . "\n";
		}
	}
} else {
	echo "FAILED creating the group term\n";
}

// One completed order, so Orders has a row and a detail page to open.
( new Payments_Schema() )->migrate();

$store = new Payment_Store();

// Scoped to this fixture's own product. A site being developed on already has
// payments against other products, and "has any payment at all" would skip the
// one the specs need — which it did, silently, leaving Orders looking fine and
// the specs failing on a row that was never created.
$existing = array_filter(
	$store->for_user( $admin->ID ),
	static fn ( Payment $payment ): bool => $payment->product_id === $paid_id
);

$payment = array() === $existing
	? $store->create_pending( $admin->ID, $paid_id, 4900, 'GBP', array( 'post:' . $order_post_id ) )
	: array_values( $existing )[0];

if ( $payment instanceof Payment ) {
	$store->mark_complete( $payment->uuid );

	// Repaired rather than assumed: an order left over from an earlier run of
	// this fixture may predate the item it now grants, and §7.4's "Access this
	// created" reads the records back by this exact source-and-reference pair.
	$created = ( new Access_Lookup() )->records_for_reference( 'stripe', $payment->uuid );

	if ( array() === $created ) {
		$granted = $writer->grant( $admin->ID, 'post', (string) $order_post_id, 365, 'stripe', $payment->uuid );

		if ( is_wp_error( $granted ) ) {
			echo 'FAILED granting the order: ' . $granted->get_error_message() . "\n";
		}
	}
}

// The specs read these off stdout rather than hardcoding a uuid that changes
// every time the fixture is rebuilt.
echo 'Fixture ready: ' . get_permalink( $paid_id ) . "\n";
echo 'GATEDMEDIA_PAID_URL=' . get_permalink( $paid_id ) . "\n";
echo 'GATEDMEDIA_FREE_URL=' . get_permalink( $free_id ) . "\n";
echo 'GATEDMEDIA_PAID_ID=' . $paid_id . "\n";
echo 'GATEDMEDIA_BUY_URL=' . get_permalink( $buy_id ) . "\n";
echo 'GATEDMEDIA_BUY_ID=' . $buy_id . "\n";
