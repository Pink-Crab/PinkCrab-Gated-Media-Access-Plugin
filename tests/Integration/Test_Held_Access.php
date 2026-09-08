<?php
/**
 * The account views' data.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Sweep;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The filters hand the views the shapes their blocks declare: granted access appears in the right list with the right keys, and a signed-out user gets the defaults untouched.
 *
 * @group integration
 */
class Test_Held_Access extends WP_UnitTestCase {

	private const MY_ACCESS_DEFAULTS = array(
		'groups' => array(),
		'posts'  => array(),
		'files'  => array(),
	);

	private const FILES_DEFAULTS = array(
		'available'   => array(),
		'downloading' => array(),
		'past'        => array(),
	);

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $this->user_id );
	}

	/**
	 * A group holding the given objects, granted to the user.
	 *
	 * @param array<int, int> $object_ids The contents.
	 * @param int|null        $duration   Days, or null for lifetime.
	 */
	private function granted_group( array $object_ids, ?int $duration ): string {
		$term = wp_insert_term( 'View Group ' . wp_rand(), Access_Taxonomy::TAXONOMY );

		foreach ( $object_ids as $object_id ) {
			wp_set_object_terms( $object_id, array( $term['term_id'] ), Access_Taxonomy::TAXONOMY, true );
		}

		$uuid = ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );

		$this->writer->grant( $this->user_id, 'group', $uuid, $duration, 'admin' );

		return $uuid;
	}

	/** @testdox Signed out, both filters return their defaults untouched. */
	public function test_signed_out_gets_the_defaults(): void {
		wp_set_current_user( 0 );

		$this->assertSame( self::MY_ACCESS_DEFAULTS, apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS ) );
		$this->assertSame( self::FILES_DEFAULTS, apply_filters( 'gatedmedia_files_data', self::FILES_DEFAULTS ) );
	}

	/** @testdox Held groups, posts and files each land in their own My Access list, shaped for the blocks. */
	public function test_my_access_lists_what_is_held(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Members Post' ) );
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'Report', 'post_mime_type' => 'application/pdf' ) );

		$this->granted_group( array( $post_id ), 30 );
		$this->writer->grant( $this->user_id, 'post', (string) $post_id, 3, 'admin' );
		$this->writer->grant( $this->user_id, 'file', (string) $file_id, null, 'admin' );

		$data = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS );

		$this->assertCount( 1, $data['groups'] );
		$this->assertCount( 1, $data['posts'] );
		$this->assertCount( 1, $data['files'] );

		$this->assertSame( 'dated', $data['groups'][0]['expiry_state'] );
		$this->assertSame( '1 item', $data['groups'][0]['meta'] );

		$this->assertSame( 'soon', $data['posts'][0]['expiry_state'] );
		$this->assertSame( 'Members Post', $data['posts'][0]['title'] );
		$this->assertSame( get_permalink( $post_id ), $data['posts'][0]['href'] );

		$this->assertSame( 'lifetime', $data['files'][0]['expiry_state'] );
		$this->assertSame( 'Report', $data['files'][0]['title'] );
		$this->assertStringContainsString( 'Lifetime', (string) $data['files'][0]['meta'] );
	}

	/** @testdox Files inside a held group are available for download alongside direct grants. */
	public function test_available_includes_group_contents(): void {
		$direct_file = self::factory()->attachment->create( array( 'post_title' => 'Direct File' ) );
		$group_file  = self::factory()->attachment->create( array( 'post_title' => 'Group File' ) );

		$this->granted_group( array( $group_file ), null );
		$this->writer->grant( $this->user_id, 'file', (string) $direct_file, 30, 'admin' );

		$data   = apply_filters( 'gatedmedia_files_data', self::FILES_DEFAULTS );
		$titles = array_column( $data['available'], 'title' );

		$this->assertCount( 2, $data['available'] );
		$this->assertContains( 'Direct File', $titles );
		$this->assertContains( 'Group File', $titles );
		$this->assertSame( array(), $data['downloading'] );
		$this->assertSame( array(), $data['past'] );
	}

	/** @testdox An expired file grant moves to past, unless the file is still reachable another way. */
	public function test_past_holds_what_ran_out(): void {
		$gone_file = self::factory()->attachment->create( array( 'post_title' => 'Gone File' ) );
		$kept_file = self::factory()->attachment->create( array( 'post_title' => 'Kept File' ) );

		$gone = $this->writer->grant( $this->user_id, 'file', (string) $gone_file, 30, 'admin' );
		$kept = $this->writer->grant( $this->user_id, 'file', (string) $kept_file, 30, 'admin' );

		update_post_meta( $gone, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		update_post_meta( $kept, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		// Kept stays reachable through a live group.
		$this->granted_group( array( $kept_file ), null );

		$data = apply_filters( 'gatedmedia_files_data', self::FILES_DEFAULTS );

		$this->assertSame( array( 'Gone File' ), array_column( $data['past'], 'title' ) );
		$this->assertSame( array( 'Kept File' ), array_column( $data['available'], 'title' ) );
		$this->assertArrayNotHasKey( 'expiry_state', $data['past'][0] );
	}

	/**
	 * `Sweep` moves an expired record's status, which is what the past list reads, and backdating the meta alone never catches this.
	 *
	 * @testdox Past access survives the nightly sweep, rather than emptying once it has run.
	 */
	public function test_past_survives_the_sweep(): void {
		$gone_file = self::factory()->attachment->create( array( 'post_title' => 'Gone File' ) );

		$gone = $this->writer->grant( $this->user_id, 'file', (string) $gone_file, 30, 'admin' );

		update_post_meta( $gone, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$this->assertSame( 1, ( new Sweep( $this->writer ) )->run(), 'the sweep expires the record' );

		$data = apply_filters( 'gatedmedia_files_data', self::FILES_DEFAULTS );

		$this->assertSame( array( 'Gone File' ), array_column( $data['past'], 'title' ) );
	}

	/** @testdox A held group row links to the group rather than sitting dead. */
	public function test_group_row_links_to_the_group(): void {
		$uuid = $this->granted_group( array( self::factory()->post->create() ), null );

		$data = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS );

		$this->assertStringContainsString( '/my-access/' . $uuid . '/', $data['groups'][0]['href'] );
	}

	/**
	 * Everything in the group is listed, not just the one it was created with.
	 *
	 * **Not asserted here: that a post added mid-request appears immediately.** `Resolver::allowed_for()` memoises the allowed set per shared instance, so within one request the answer is fixed and the access query filter hides anything added since.
	 *
	 * Across requests, which is how a person uses the page, the contents are live, so an assertion to the contrary would test the memo.
	 *
	 * @testdox Opening a held group lists everything it holds.
	 */
	public function test_group_detail_lists_its_contents(): void {
		$first  = self::factory()->post->create( array( 'post_title' => 'The briefing' ) );
		$second = self::factory()->post->create( array( 'post_title' => 'The addendum' ) );

		$uuid = $this->granted_group( array( $first, $second ), null );

		$detail = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail'];
		$titles = array_column( $detail['items'], 'title' );

		$this->assertContains( 'The briefing', $titles );
		$this->assertContains( 'The addendum', $titles );
		$this->assertSame( $uuid, $detail['uuid'] );
	}

	/** @testdox A group's files are listed, despite an attachment's status being inherit. */
	public function test_group_detail_includes_files(): void {
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'The spreadsheet' ) );
		$uuid    = $this->granted_group( array( $file_id ), null );

		$detail = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail'];

		$this->assertSame( array( 'The spreadsheet' ), array_column( $detail['items'], 'title' ) );
	}

	/** @testdox A group nobody gave you reads exactly like one that does not exist. */
	public function test_group_detail_refuses_what_is_not_held(): void {
		$term = wp_insert_term( 'Not Yours ' . wp_rand(), Access_Taxonomy::TAXONOMY );
		$uuid = ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );

		$this->assertNull( apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail'] );
		$this->assertNull( apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), 'no-such-uuid' )['detail'] );
	}

	/**
	 * The rows carry a kind as their meta line, and nothing asserted it, so a mixed group could have called every row the same thing and still passed.
	 *
	 * @testdox An opened group says which of its rows are files and which are posts.
	 */
	public function test_group_detail_labels_each_kind(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The briefing' ) );
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'The spreadsheet' ) );

		$uuid = $this->granted_group( array( $post_id, $file_id ), null );

		$items = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail']['items'];
		$kinds = array_combine( array_column( $items, 'title' ), array_column( $items, 'meta' ) );

		$this->assertSame( 'Post', $kinds['The briefing'] );
		$this->assertSame( 'File', $kinds['The spreadsheet'] );
	}

	/** @testdox Every row in an opened group links to the thing itself. */
	public function test_group_detail_rows_link_to_their_item(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The briefing' ) );
		$uuid    = $this->granted_group( array( $post_id ), null );

		$items = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail']['items'];

		$this->assertSame( get_permalink( $post_id ), $items[0]['href'] );
	}

	/** @testdox A held group holding nothing opens and says so, rather than failing to open. */
	public function test_an_empty_group_still_opens(): void {
		$uuid = $this->granted_group( array(), null );

		$detail = apply_filters( 'gatedmedia_my_access_data', self::MY_ACCESS_DEFAULTS + array( 'detail' => null ), $uuid )['detail'];

		$this->assertNotNull( $detail, 'the group is held, so it opens' );
		$this->assertSame( array(), $detail['items'] );
	}
}
