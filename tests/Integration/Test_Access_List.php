<?php
/**
 * The Access list screen.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Query;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Access_List;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The list shows who has what from the record's own meta, offers no way to
 * edit one, and sorts where sorting is cheap.
 *
 * @group integration
 */
class Test_Access_List extends WP_UnitTestCase {

	private Access_List $list;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy     = new Access_Taxonomy();
		$this->list   = new Access_List( $taxonomy );
		$this->writer = new Access_Writer( new Grant_Validator( $taxonomy ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Dave Holder',
				'user_email'   => 'dave@example.test',
			)
		);
	}

	/** @testdox The columns are ours, whole — nothing of core's default set survives. */
	public function test_columns_are_replaced_whole(): void {
		$columns = $this->list->columns( array( 'cb' => '<input type="checkbox" />', 'title' => 'Title', 'date' => 'Date' ) );

		$this->assertSame(
			array( 'gatedmedia_holder', 'gatedmedia_item', 'gatedmedia_status', 'gatedmedia_expiry', 'gatedmedia_source' ),
			array_keys( $columns )
		);
	}

	/** @testdox The holder column shows the user's name and email. */
	public function test_holder_column(): void {
		$access_id = $this->grant_for_post();

		$cell = $this->render( 'gatedmedia_holder', $access_id );

		$this->assertStringContainsString( 'Dave Holder', $cell );
		$this->assertStringContainsString( 'dave@example.test', $cell );
	}

	/** @testdox The item column names the target: type and current title. */
	public function test_item_column_for_a_post(): void {
		$post_id   = self::factory()->post->create( array( 'post_title' => 'The Gated Report' ) );
		$access_id = $this->grant( 'post', (string) $post_id );

		$this->assertSame( 'Post: The Gated Report', $this->render( 'gatedmedia_item', $access_id ) );
	}

	/** @testdox The item column resolves a group UUID to the group's name. */
	public function test_item_column_for_a_group(): void {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => Access_Taxonomy::TAXONOMY,
				'name'     => 'Members',
			)
		);

		$uuid      = ( new Access_Taxonomy() )->uuid_for( $term->term_id );
		$access_id = $this->grant( 'group', $uuid );

		$this->assertSame( 'Group: Members', $this->render( 'gatedmedia_item', $access_id ) );
	}

	/** @testdox A record whose target is gone still renders, marked removed. */
	public function test_item_column_for_a_removed_target(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->grant( 'post', (string) $post_id );

		wp_delete_post( $post_id, true );

		$this->assertSame( 'Post (removed)', $this->render( 'gatedmedia_item', $access_id ) );
	}

	/** @testdox The status column shows the registered label. */
	public function test_status_column(): void {
		$access_id = $this->grant_for_post();

		$this->assertSame( 'Active', $this->render( 'gatedmedia_status', $access_id ) );
	}

	/** @testdox The expiry column reads Lifetime for a record with no expiry. */
	public function test_expiry_column_lifetime(): void {
		$access_id = $this->grant_for_post( null );

		$this->assertSame( 'Lifetime', $this->render( 'gatedmedia_expiry', $access_id ) );
	}

	/** @testdox The expiry column formats the stored date. */
	public function test_expiry_column_dated(): void {
		$access_id = $this->grant_for_post( 30 );

		$expires = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );
		$cell    = $this->render( 'gatedmedia_expiry', $access_id );

		$this->assertNotSame( 'Lifetime', $cell );
		$this->assertStringContainsString( gmdate( 'Y', (int) strtotime( $expires . ' +0000' ) ), $cell );
	}

	/** @testdox The source column shows the record's source meta. */
	public function test_source_column(): void {
		$access_id = $this->grant_for_post();

		$this->assertSame( 'admin', $this->render( 'gatedmedia_source', $access_id ) );
	}

	/** @testdox Access rows offer nothing without the capability; other types keep their actions. */
	public function test_row_actions_are_stripped_for_access_only(): void {
		$access_id = $this->grant_for_post();
		$actions   = array( 'edit' => 'Edit', 'trash' => 'Trash' );

		// No user is signed in, so no capability — and no actions.
		$this->assertSame( array(), $this->list->row_actions( $actions, get_post( $access_id ) ) );

		$plain_post = self::factory()->post->create_and_get();
		$this->assertSame( $actions, $this->list->row_actions( $actions, $plain_post ) );
	}

	/** @testdox With the capability, an active row offers revoke and nothing else; a withdrawn row offers nothing. */
	public function test_row_actions_offer_revoke_to_the_capable(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$access_id = $this->grant_for_post();
		$actions   = $this->list->row_actions( array( 'edit' => 'Edit' ), get_post( $access_id ) );

		$this->assertSame( array( 'gatedmedia_revoke' ), array_keys( $actions ) );
		$this->assertStringContainsString( 'gatedmedia_revoke_access', $actions['gatedmedia_revoke'] );
		$this->assertStringContainsString( 'access=' . $access_id, $actions['gatedmedia_revoke'] );
		$this->assertStringContainsString( '_wpnonce', $actions['gatedmedia_revoke'] );

		$this->writer->revoke( $access_id );
		$this->assertSame( array(), $this->list->row_actions( array(), get_post( $access_id ) ) );
	}

	/** @testdox Expiry and holder are sortable. */
	public function test_sortable_columns(): void {
		$sortable = $this->list->sortable_columns( array() );

		$this->assertSame( 'author', $sortable['gatedmedia_holder'] );
		$this->assertSame( 'gatedmedia_expiry', $sortable['gatedmedia_expiry'] );
	}

	/** @testdox Ordering by expiry becomes a meta sort on the list's main query, and touches nothing else. */
	public function test_shape_list_query_rewrites_the_orderby(): void {
		$query = new WP_Query();
		$query->set( 'post_type', Post_Types::ACCESS );
		$query->set( 'orderby', 'gatedmedia_expiry' );

		// The class only acts on the screen's main query.
		$GLOBALS['wp_the_query'] = $query;
		$this->list->shape_list_query( $query );
		unset( $GLOBALS['wp_the_query'] );

		$this->assertSame( Access_Writer::META_EXPIRES_AT, $query->get( 'meta_key' ) );
		$this->assertSame( 'meta_value', $query->get( 'orderby' ) );

		$other = new WP_Query();
		$other->set( 'post_type', 'post' );
		$other->set( 'orderby', 'gatedmedia_expiry' );

		$GLOBALS['wp_the_query'] = $other;
		$this->list->shape_list_query( $other );
		unset( $GLOBALS['wp_the_query'] );

		$this->assertSame( '', $other->get( 'meta_key' ) );
	}

	/** @testdox The All view names our statuses — an empty post_status would silently skip all three. */
	public function test_shape_list_query_names_the_statuses_for_all(): void {
		$query = new WP_Query();
		$query->set( 'post_type', Post_Types::ACCESS );

		$GLOBALS['wp_the_query'] = $query;
		$this->list->shape_list_query( $query );
		unset( $GLOBALS['wp_the_query'] );

		$this->assertSame(
			array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
			$query->get( 'post_status' )
		);

		// An explicit view — Active, say — is left exactly as asked.
		$active = new WP_Query();
		$active->set( 'post_type', Post_Types::ACCESS );
		$active->set( 'post_status', Post_Types::STATUS_ACTIVE );

		$GLOBALS['wp_the_query'] = $active;
		$this->list->shape_list_query( $active );
		unset( $GLOBALS['wp_the_query'] );

		$this->assertSame( Post_Types::STATUS_ACTIVE, $active->get( 'post_status' ) );
	}

	/**
	 * Grants access to a fresh post for the shared holder.
	 *
	 * @param int|null $duration_days Days, or null for lifetime.
	 */
	private function grant_for_post( ?int $duration_days = 30 ): int {
		$post_id = self::factory()->post->create();

		return $this->grant( 'post', (string) $post_id, $duration_days );
	}

	/**
	 * Grants through the writer — the only path that makes records.
	 *
	 * @param string   $item_type     One of file, post, group.
	 * @param string   $item_id       The target's identifier.
	 * @param int|null $duration_days Days, or null for lifetime.
	 */
	private function grant( string $item_type, string $item_id, ?int $duration_days = 30 ): int {
		$access_id = $this->writer->grant( $this->user_id, $item_type, $item_id, $duration_days, 'admin' );

		$this->assertIsInt( $access_id );

		return $access_id;
	}

	/**
	 * What one cell renders.
	 *
	 * @param string $column    The column key.
	 * @param int    $access_id The record.
	 */
	private function render( string $column, int $access_id ): string {
		ob_start();
		$this->list->render_column( $column, $access_id );

		return trim( (string) ob_get_clean() );
	}
}
