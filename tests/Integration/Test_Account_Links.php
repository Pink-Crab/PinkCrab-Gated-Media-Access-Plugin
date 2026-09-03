<?php
/**
 * Every place that builds a link into the account area.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Account\Account_Renderer;
use PinkCrab\Gated_Access\Account\Group_Contents;
use PinkCrab\Gated_Access\Account\Order_History;
use PinkCrab\Gated_Access\Account\Section_Registry;
use PinkCrab\Gated_Access\Payments\Payment_Store;
use PinkCrab\Gated_Access\Payments\Payments_Schema;
use PinkCrab\Gated_Access\Products\Product_Offer;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Access_Row;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * `Account_Url` is the single place a link into the account area is built, so
 * that switching the `account_route` setting off moves all of them at once.
 * The setting was added in PR #14 and only two call sites were told about it;
 * the rest went on pointing at `/account/`, which no longer answers — Stripe's
 * return page, both notification emails, and every nav and row link.
 *
 * These drive each producer with the route off and assert none of them still
 * name that route. The guard at the end is the one that stops it happening
 * again: nothing outside `Account_Url` may build the path itself.
 *
 * @group integration
 */
class Test_Account_Links extends WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Payments_Schema::OPTION_DB_VERSION );
		( new Payments_Schema() )->migrate();

		$this->user_id = self::factory()->user->create();

		wp_set_current_user( $this->user_id );
		add_filter( 'gatedmedia_account_route', '__return_false' );
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_account_route' );
		remove_all_filters( 'gatedmedia_account_url' );

		parent::tear_down();
	}

	/** @testdox With the route off, an order row and the Orders section link away from it. */
	public function test_order_history_links(): void {
		$store = new Payment_Store();
		$store->mark_complete( $store->create_pending( $this->user_id, 1, 100, 'GBP', array() )->uuid );

		$history = new Order_History( $store, new Access_Lookup(), new Item_Label( new Access_Taxonomy() ) );
		$data    = $history->orders( array() );

		$this->assertStringNotContainsString( '/account/', (string) wp_json_encode( $data ) );
	}

	/** @testdox With the route off, a held group's row links away from it. */
	public function test_access_row_group_link(): void {
		$taxonomy = new Access_Taxonomy();
		$taxonomy->register();

		$term = wp_insert_term( 'A Group', Access_Taxonomy::TAXONOMY );
		$uuid = $taxonomy->uuid_for( $term['term_id'] );

		$row = ( new Access_Row( $taxonomy ) )->group( $uuid, null );

		$this->assertIsArray( $row );
		$this->assertStringNotContainsString( '/account/', (string) $row['href'] );
	}

	/** @testdox With the route off, the group detail's way back links away from it. */
	public function test_group_contents_section_link(): void {
		$taxonomy = new Access_Taxonomy();
		$taxonomy->register();

		$data = ( new Group_Contents( new Resolver( $taxonomy ), $taxonomy ) )->detail( array(), 'some-group-uuid' );

		$this->assertStringNotContainsString( '/account/', (string) wp_json_encode( $data ) );
	}

	/** @testdox With the route off, the account nav links away from it. */
	public function test_account_nav_links(): void {
		$sections = ( new Section_Registry() )->all();
		$markup   = ( new Account_Renderer() )->markup( $sections->first(), $sections );

		$this->assertStringNotContainsString( '/account/', $markup );
	}

	/** @testdox With the route off, the product page's "View your access" button links away from it. */
	public function test_product_details_block_link(): void {
		$GLOBALS['post'] = get_post( self::factory()->post->create() );

		add_filter(
			'gatedmedia_product_data',
			static fn( array $data ): array => array_merge(
				$data,
				array(
					'product_id' => (int) $GLOBALS['post']->ID,
					'state'      => Product_Offer::STATE_HELD,
				)
			)
		);

		$html = Block::render( 'gated-media-access/product-details', array() );

		remove_all_filters( 'gatedmedia_product_data' );

		$this->assertStringContainsString( 'View your access', $html );
		$this->assertStringNotContainsString( '/account/', $html );
	}

	/** @testdox With the route off, a completed order's "Go to my access" button links away from it. */
	public function test_orders_block_detail_link(): void {
		add_filter(
			'gatedmedia_orders_data',
			static fn(): array => array(
				'orders' => array(),
				'detail' => array(
					'uuid'   => 'abc-123',
					'status' => 'complete',
					'date'   => '2026-01-01',
					// §7.8 only draws the payment-status block, and so the
					// button, when the buyer has just come back from Stripe.
					'is_new' => true,
				),
			)
		);

		$html = Block::render( 'gated-media-access/orders', array( 'detail' => 'abc-123' ) );

		remove_all_filters( 'gatedmedia_orders_data' );

		$this->assertStringContainsString( 'Go to my access', $html );
		$this->assertStringNotContainsString( '/account/', $html );
	}

	/**
	 * The emails hand-built `/{slug}/{section}/` and so survived the setting.
	 * Nothing may build that path but `Account_Url`, or the next call site
	 * repeats it.
	 *
	 * @testdox Nothing outside Account_Url builds an account path of its own.
	 */
	public function test_only_account_url_builds_the_path(): void {
		$offenders = array();

		foreach ( $this->php_files() as $file ) {
			$source = (string) file_get_contents( $file );

			// The shape both emails used: the account segment and a section
			// slug sprintf'd into a path, with nothing reading the setting.
			// Account_Route is the exception — it only runs while the route
			// is on, and its virtual post's guid must name the URL served.
			if ( str_contains( $source, "'/%s/%s/'" ) && ! str_ends_with( $file, 'Account_Route.php' ) ) {
				$offenders[] = $file;
			}
		}

		$this->assertSame( array(), $offenders, 'these build an account path instead of asking Account_Url for one' );
	}

	/**
	 * Every PHP file the plugin ships, bar the one class under test.
	 *
	 * @return array<int, string>
	 */
	private function php_files(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		foreach ( array( $root . '/src', $root . '/blocks' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );

			foreach ( $files as $file ) {
				if ( 'php' === $file->getExtension() && 'Account_Url.php' !== $file->getFilename() ) {
					$found[] = $file->getPathname();
				}
			}
		}

		return $found;
	}
}
