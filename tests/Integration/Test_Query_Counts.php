<?php
/**
 * What each read costs in queries.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Gated_Post_Route;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Account\Group_Contents;
use PinkCrab\Gated_Access\Admin\Quick_Edit_Grant;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * Every read here used to cost one query per row (§29). The assertions are
 * deliberately about *scaling* rather than an exact count: a read that costs
 * the same for ten items as for two is not an N+1, whatever the constant is.
 *
 * @group integration
 */
class Test_Query_Counts extends WP_UnitTestCase {

	private Access_Writer $writer;

	public function set_up(): void {
		parent::set_up();

		$this->writer = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );

		$this->writer->register_meta();
	}

	/** @testdox Resolving a held group costs the same for ten files as for two. */
	public function test_group_contents_does_not_scale(): void {
		$small = $this->cost_of_resolving_a_group_of( 2 );
		$large = $this->cost_of_resolving_a_group_of( 10 );

		$this->assertSame( $small, $large, 'resolving a group runs one query per member' );
	}

	/** @testdox Listing a group's contents costs the same for ten items as for two. */
	public function test_group_contents_block_does_not_scale(): void {
		// Discarded: the first measurement leaves restricted content behind,
		// which puts an exclusion on the next one's queries.
		$this->cost_of_listing_a_group_of( 1 );

		$small = $this->cost_of_listing_a_group_of( 2 );
		$large = $this->cost_of_listing_a_group_of( 10 );

		$this->assertSame( $small, $large, 'the group page runs one query per row' );
	}

	/** @testdox The Files page costs the same for ten files as for two. */
	public function test_the_files_page_does_not_scale(): void {
		$small = $this->cost_of_the_files_page_with( 2 );
		$large = $this->cost_of_the_files_page_with( 10 );

		$this->assertSame( $small, $large, 'the Files page runs one query per file' );
	}

	/** @testdox The Access column costs the same for ten rows as for two. */
	public function test_quick_edit_column_does_not_scale(): void {
		$small = $this->cost_of_rendering_rows( 2 );
		$large = $this->cost_of_rendering_rows( 10 );

		$this->assertSame( $small, $large, 'the Access column runs one query per row' );
	}

	/** @testdox Asking whether any of ten items was held before costs one query. */
	public function test_past_records_answers_for_every_item_at_once(): void {
		$user_id = self::factory()->user->create();
		$lookup  = new Access_Lookup();

		$entries = array();

		foreach ( range( 1, 10 ) as $unused ) {
			$entries[] = array( 'post', (string) self::factory()->post->create() );
		}

		$before = get_num_queries();
		$lookup->past_records_for_items( $user_id, $entries );
		$cost = get_num_queries() - $before;

		$this->assertLessThanOrEqual( 1, $cost );
	}

	/** @testdox A site with no gated post spends no query deciding a slug is not one. */
	public function test_the_gated_slug_lookup_is_free_when_nothing_is_gated(): void {
		$route = new Gated_Post_Route( new Restriction() );

		// The first call may look; every later one must not.
		$route->route_request( array( 'name' => 'warm-up' ) );

		$before = get_num_queries();
		$route->route_request( array( 'name' => 'hello-world' ) );

		$this->assertSame( 0, get_num_queries() - $before );
	}

	/**
	 * Queries spent resolving one user's held group of a given size.
	 *
	 * @param int $size How many attachments the group holds.
	 */
	private function cost_of_resolving_a_group_of( int $size ): int {
		$user_id = self::factory()->user->create();
		$uuid    = $this->group_of( $size );

		$this->writer->grant( $user_id, 'group', $uuid, null, 'admin' );

		wp_cache_flush();

		$before = get_num_queries();
		( new Resolver( new Access_Taxonomy() ) )->allowed_for( $user_id );

		return get_num_queries() - $before;
	}

	/**
	 * Queries spent drawing one group's contents list.
	 *
	 * @param int $size How many attachments the group holds.
	 */
	private function cost_of_listing_a_group_of( int $size ): int {
		$user_id = self::factory()->user->create();
		$uuid    = $this->group_of( $size );

		$this->writer->grant( $user_id, 'group', $uuid, null, 'admin' );
		wp_set_current_user( $user_id );

		$contents = new Group_Contents( new Resolver( new Access_Taxonomy() ), new Access_Taxonomy() );

		wp_cache_flush();

		$before = get_num_queries();
		$contents->detail( array( 'groups' => array() ), $uuid );

		return get_num_queries() - $before;
	}

	/**
	 * Queries spent drawing the account Files page.
	 *
	 * @param int $files How many files the person holds.
	 */
	private function cost_of_the_files_page_with( int $files ): int {
		$user_id = self::factory()->user->create();

		foreach ( range( 1, $files ) as $unused ) {
			$this->writer->grant( $user_id, 'file', (string) self::factory()->attachment->create(), null, 'admin' );
		}

		wp_set_current_user( $user_id );

		$page = new \PinkCrab\Gated_Access\Account\Downloadable_Files(
			new Resolver( new Access_Taxonomy() ),
			new \PinkCrab\Gated_Access\Support\Access_Row( new Access_Taxonomy() )
		);

		wp_cache_flush();

		$before = get_num_queries();
		$page->files( array( 'available' => array(), 'downloading' => array(), 'past' => array() ) );

		return get_num_queries() - $before;
	}

	/**
	 * Queries spent rendering the Access column for a page of rows.
	 *
	 * @param int $rows How many rows the list table shows.
	 */
	private function cost_of_rendering_rows( int $rows ): int {
		$column   = new Quick_Edit_Grant( $this->writer );
		$post_ids = array();

		foreach ( range( 1, $rows ) as $unused ) {
			$post_ids[] = self::factory()->post->create();
		}

		// A list table always draws the rows its own query found.
		$GLOBALS['wp_query'] = new \WP_Query(
			array(
				'post__in'       => $post_ids,
				'post_type'      => 'post',
				'posts_per_page' => -1,
			)
		);

		wp_cache_flush();

		$before = get_num_queries();

		foreach ( $post_ids as $post_id ) {
			ob_start();
			$column->render_column( Quick_Edit_Grant::COLUMN, $post_id );
			ob_end_clean();
		}

		return get_num_queries() - $before;
	}

	/**
	 * A group holding the given number of attachments.
	 *
	 * @param int $size How many attachments.
	 */
	private function group_of( int $size ): string {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY ) );

		foreach ( range( 1, $size ) as $unused ) {
			$file_id = self::factory()->attachment->create();
			wp_set_object_terms( $file_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY );
		}

		return Uuid::ensure( 'term', $term->term_id );
	}
}
