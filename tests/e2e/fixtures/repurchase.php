<?php
/**
 * A product the admin already holds, for the buy-again specs.
 *
 * One post, granted to the admin for good, and a priced product granting just that post. The specs set its buy-again setting themselves; this puts it back to the default on every run.
 *
 * Run by tests/e2e/global-setup.js before the suite, and idempotent: existing fixtures are reused rather than duplicated.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;

$gatedmedia_admin = get_user_by( 'login', 'admin' );

if ( ! $gatedmedia_admin instanceof WP_User ) {
	echo "Repurchase fixture failed: no admin\n";
	return;
}

$gatedmedia_note    = get_page_by_path( 'e2e-repurchase-note', OBJECT, 'post' );
$gatedmedia_note_id = $gatedmedia_note instanceof WP_Post
	? $gatedmedia_note->ID
	: (int) wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_name'    => 'e2e-repurchase-note',
			'post_title'   => 'The renewal note',
			'post_content' => 'Bought once, held for good.',
		)
	);

$gatedmedia_product    = get_page_by_path( 'e2e-repurchase-product', OBJECT, Post_Types::PRODUCT );
$gatedmedia_product_id = $gatedmedia_product instanceof WP_Post
	? $gatedmedia_product->ID
	: (int) wp_insert_post(
		array(
			'post_type'    => Post_Types::PRODUCT,
			'post_status'  => 'publish',
			'post_name'    => 'e2e-repurchase-product',
			'post_title'   => 'The renewal bundle',
			// The block delimiter is written in, as the shop fixture explains, or the page renders nothing.
			'post_content' => '<!-- wp:gated-media-access/product-details {"lock":{"move":true,"remove":true}} /-->',
		)
	);

update_post_meta( $gatedmedia_product_id, Product_Meta::META_PRICE, 900 );
update_post_meta( $gatedmedia_product_id, Product_Meta::META_CURRENCY, 'GBP' );
update_post_meta( $gatedmedia_product_id, Product_Meta::META_VISIBILITY, 'listed' );
delete_post_meta( $gatedmedia_product_id, Product_Meta::META_ITEMS );
add_post_meta( $gatedmedia_product_id, Product_Meta::META_ITEMS, 'post:' . $gatedmedia_note_id );
delete_post_meta( $gatedmedia_product_id, Product_Meta::META_REPURCHASE );

$gatedmedia_taxonomy = new Access_Taxonomy();

if ( ! ( new Resolver( $gatedmedia_taxonomy ) )->can_see( $gatedmedia_admin->ID, 'post', (string) $gatedmedia_note_id ) ) {
	$gatedmedia_granted = ( new Access_Writer( new Access_Validator( $gatedmedia_taxonomy ), new Access_Lookup() ) )
		->grant( $gatedmedia_admin->ID, 'post', (string) $gatedmedia_note_id, null, 'admin', 'e2e-repurchase' );

	if ( is_wp_error( $gatedmedia_granted ) ) {
		echo 'Repurchase fixture failed: ' . esc_html( $gatedmedia_granted->get_error_message() ) . "\n";
		return;
	}
}

echo 'Fixture ready: ' . esc_url_raw( (string) get_permalink( $gatedmedia_product_id ) ) . "\n";
echo 'GATEDMEDIA_REPURCHASE_URL=' . esc_url_raw( (string) get_permalink( $gatedmedia_product_id ) ) . "\n";
echo 'GATEDMEDIA_REPURCHASE_ID=' . (int) $gatedmedia_product_id . "\n";
