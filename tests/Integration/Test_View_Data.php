<?php
/**
 * The account views' data.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The filters hand the views the shapes their blocks declare, straight from
 * the boot-registered service — granted access appears in the right list with
 * the right keys, and a signed-out user gets the defaults untouched.
 *
 * @group integration
 */
class Test_View_Data extends WP_UnitTestCase {

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

	/** @testdox An expired file grant moves to past — unless the file is still reachable another way. */
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
}
