<?php
/**
 * The Groups screen.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use Exception;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Admin\Group_Actions;
use PinkCrab\Gated_Access\Admin\Groups_Page;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * A group holds content and people hold the group, and until this screen nothing could answer either question from the group's side.
 *
 * The two that carry the most: `holders_of()` answers "who has this" without walking every user, and adding an item goes through the same `wp_set_object_terms` the item's own panel uses, so `Restriction`'s marker still applies.
 *
 * @group integration
 */
class Test_Groups_Page extends WP_UnitTestCase {

	private Access_Writer $writer;

	private Access_Lookup $lookup;

	private Restriction $restriction;

	private Groups_Page $page;

	private Group_Actions $actions;

	/** Where the last redirect was aimed. */
	private string $captured = '';

	public function set_up(): void {
		parent::set_up();

		$this->lookup      = new Access_Lookup();
		$this->writer      = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), $this->lookup );
		$this->restriction = new Restriction();
		$this->page        = new Groups_Page( $this->lookup, $this->restriction, new Access_Taxonomy() );
		$this->actions     = new Group_Actions( new Access_Taxonomy() );

		$this->writer->register_meta();
		$this->captured = '';

		// The handlers exit after redirecting, which would take the runner with them.
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->captured = (string) $location;

				throw new Exception( 'redirected' );
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Runs something that redirects and hands back where it aimed.
	 *
	 * @param callable $run The thing that redirects.
	 */
	private function capture( callable $run ): string {
		try {
			$run();
		} catch ( Exception $e ) {
			return $this->captured;
		}

		$this->fail( 'Expected a redirect and got none.' );
	}

	/**
	 * An administrator, posting with a valid nonce.
	 *
	 * @param string               $action The action being posted.
	 * @param array<string, mixed> $fields The payload.
	 */
	private function post_as_admin( string $action, array $fields ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$_POST             = $fields;
		$_POST['_wpnonce'] = wp_create_nonce( $action );
		$_REQUEST          = $_POST;
	}

	/**
	 * A group, and its UUID.
	 *
	 * @param string $name What to call it.
	 * @return array{0: int, 1: string}
	 */
	private function make_group( string $name ): array {
		$created = wp_insert_term( $name, Access_Taxonomy::TAXONOMY );
		$term_id = (int) $created['term_id'];

		return array( $term_id, Uuid::ensure( 'term', $term_id ) );
	}

	/** @testdox Who holds a group can be answered from the group, without walking every user. */
	public function test_holders_of_answers_from_the_group(): void {
		[ , $uuid ] = $this->make_group( 'Holders' );

		$one = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$two = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->grant( $one, 'group', $uuid, null, 'admin' );
		$this->writer->grant( $two, 'group', $uuid, null, 'admin' );

		$holders = $this->lookup->holders_of( 'group', $uuid );

		sort( $holders );
		$expected = array( $one, $two );
		sort( $expected );

		$this->assertSame( $expected, $holders );
	}

	/** @testdox Somebody granted the same group twice is one holder, not two. */
	public function test_holders_are_unique(): void {
		[ , $uuid ] = $this->make_group( 'Twice' );

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->grant( $user, 'group', $uuid, null, 'admin' );
		$this->writer->grant( $user, 'group', $uuid, null, 'admin', 'second' );

		$this->assertSame( array( $user ), $this->lookup->holders_of( 'group', $uuid ) );
	}

	/** @testdox A revoked record is not a holder, and a screen listing them would be lying. */
	public function test_a_revoked_record_is_not_a_holder(): void {
		[ , $uuid ] = $this->make_group( 'Revoked' );

		$user      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$access_id = $this->writer->grant( $user, 'group', $uuid, null, 'admin' );

		$this->assertSame( array( $user ), $this->lookup->holders_of( 'group', $uuid ) );

		$this->writer->revoke( (int) $access_id );

		$this->assertSame( array(), $this->lookup->holders_of( 'group', $uuid ) );
	}

	/** @testdox A group with nobody on it answers with nobody rather than everybody. */
	public function test_an_unheld_group_has_no_holders(): void {
		[ , $uuid ] = $this->make_group( 'Nobody' );

		$this->assertSame( array(), $this->lookup->holders_of( 'group', $uuid ) );
	}

	/** @testdox Creating a group from the page makes one and says so. */
	public function test_creating_a_group(): void {
		$this->post_as_admin( Group_Actions::CREATE_ACTION, array( 'group_name' => 'Made here' ) );

		$url = $this->capture( array( $this->actions, 'handle_create' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=created', $url );
		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'name', 'Made here', Access_Taxonomy::TAXONOMY ) );
	}

	/** @testdox A group with no name is refused rather than created blank. */
	public function test_a_nameless_group_is_refused(): void {
		$this->post_as_admin( Group_Actions::CREATE_ACTION, array( 'group_name' => '' ) );

		$url = $this->capture( array( $this->actions, 'handle_create' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=empty', $url );
	}

	/** @testdox Renaming a group renames it, which core's screens were the only way to do. */
	public function test_renaming_a_group(): void {
		[ $term_id, $uuid ] = $this->make_group( 'Before' );

		$this->post_as_admin(
			Group_Actions::SAVE_ACTION,
			array(
				'group'             => $uuid,
				'group_name'        => 'After',
				'group_description' => 'Why it exists.',
			)
		);

		$url = $this->capture( array( $this->actions, 'handle_save' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=saved', $url );

		$term = get_term( $term_id, Access_Taxonomy::TAXONOMY );

		$this->assertSame( 'After', $term->name );
		$this->assertSame( 'Why it exists.', $term->description );
	}

	/**
	 * @testdox Adding an item from the group puts it in, and the marker follows.
	 *
	 * The marker is the point: adding from this end must mean what adding from the item's own panel means, or a group filled here would hold unrestricted content.
	 */
	public function test_adding_an_item_marks_it_restricted(): void {
		[ $term_id, $uuid ] = $this->make_group( 'Filling' );

		$post_id = self::factory()->post->create();

		$this->post_as_admin(
			Group_Actions::ITEM_ACTION,
			array(
				'group' => $uuid,
				'item'  => (string) $post_id,
				'op'    => 'add',
			)
		);

		$url = $this->capture( array( $this->actions, 'handle_item' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=added', $url );

		$in = get_objects_in_term( $term_id, Access_Taxonomy::TAXONOMY );
		$this->assertContains( (string) $post_id, array_map( 'strval', (array) $in ) );

		$slugs = wp_get_object_terms(
			$post_id,
			Access_Taxonomy::TAXONOMY,
			array(
				'fields'                    => 'slugs',
				Restriction::INCLUDE_MARKER => true,
			)
		);

		$this->assertContains( Restriction::MARKER_SLUG, $slugs );
	}

	/** @testdox Taking an item out takes it out. */
	public function test_removing_an_item(): void {
		[ $term_id, $uuid ] = $this->make_group( 'Emptying' );

		$post_id = self::factory()->post->create();
		wp_set_object_terms( $post_id, array( $term_id ), Access_Taxonomy::TAXONOMY, true );

		$this->post_as_admin(
			Group_Actions::ITEM_ACTION,
			array(
				'group' => $uuid,
				'item'  => (string) $post_id,
				'op'    => 'remove',
			)
		);

		$url = $this->capture( array( $this->actions, 'handle_item' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=removed', $url );

		$in = array_map( 'strval', (array) get_objects_in_term( $term_id, Access_Taxonomy::TAXONOMY ) );
		$this->assertNotContains( (string) $post_id, $in );
	}

	/** @testdox An item that is not there is refused rather than half-applied. */
	public function test_an_unknown_item_is_refused(): void {
		[ , $uuid ] = $this->make_group( 'Nothing' );

		$this->post_as_admin(
			Group_Actions::ITEM_ACTION,
			array(
				'group' => $uuid,
				'item'  => '999999',
				'op'    => 'add',
			)
		);

		$url = $this->capture( array( $this->actions, 'handle_item' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=no-item', $url );
	}

	/** @testdox A group nobody can find is refused, so a bad UUID writes nothing. */
	public function test_an_unknown_group_is_refused(): void {
		$post_id = self::factory()->post->create();

		$this->post_as_admin(
			Group_Actions::ITEM_ACTION,
			array(
				'group' => '11111111-2222-3333-4444-555555555555',
				'item'  => (string) $post_id,
				'op'    => 'add',
			)
		);

		$url = $this->capture( array( $this->actions, 'handle_item' ) );

		$this->assertStringContainsString( 'gatedmedia_notice=no-item', $url );
	}

	/** @testdox Each group has a URL of its own, carrying the mode and the group. */
	public function test_a_group_has_its_own_url(): void {
		$url = Groups_Page::url_for( 'abc-123' );

		$this->assertStringContainsString( 'page=' . Groups_Page::PAGE_SLUG, $url );
		$this->assertStringContainsString( 'mode=' . Groups_Page::MODE_EDIT, $url );
		$this->assertStringContainsString( 'group=abc-123', $url );
	}
}
