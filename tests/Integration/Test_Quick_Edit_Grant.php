<?php
/**
 * Granting access from quick edit.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Quick_Edit_Grant;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The Access column counts holders, its quick edit box grants, and the grant
 * itself is the writer's — an inline save without our nonced fields does
 * nothing at all.
 *
 * @group integration
 */
class Test_Quick_Edit_Grant extends WP_UnitTestCase {

	private Quick_Edit_Grant $quick_edit;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer     = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->quick_edit = new Quick_Edit_Grant( $this->writer );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		unset( $_POST[ Quick_Edit_Grant::NONCE ], $_POST['gatedmedia_qe_user'], $_POST['gatedmedia_qe_duration'] );
		parent::tear_down();
	}

	/** @testdox The column lands on posts and pages, not attachments. */
	public function test_the_column_and_its_types(): void {
		$this->assertSame( array( 'post', 'page' ), $this->quick_edit->list_types() );
		$this->assertArrayHasKey( Quick_Edit_Grant::COLUMN, $this->quick_edit->column( array( 'title' => 'Title' ) ) );
	}

	/** @testdox The column counts direct holders, and shows a dash for none. */
	public function test_column_counts_holders(): void {
		$post_id = self::factory()->post->create();

		ob_start();
		$this->quick_edit->render_column( Quick_Edit_Grant::COLUMN, $post_id );
		$empty = trim( (string) ob_get_clean() );

		$this->assertSame( '—', $empty );

		$this->assertIsInt( $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' ) );

		ob_start();
		$this->quick_edit->render_column( Quick_Edit_Grant::COLUMN, $post_id );
		$counted = trim( (string) ob_get_clean() );

		$this->assertSame( '1 holder', $counted );
	}

	/** @testdox The quick edit box renders the nonced grant fields for the capable, and nothing otherwise. */
	public function test_quick_edit_box_is_capability_gated(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		$this->quick_edit->render_quick_edit( Quick_Edit_Grant::COLUMN, 'post' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'gatedmedia_qe_user', $html );
		$this->assertStringContainsString( 'gatedmedia_qe_duration', $html );
		$this->assertStringContainsString( Quick_Edit_Grant::NONCE, $html );

		wp_set_current_user( $this->user_id );

		ob_start();
		$this->quick_edit->render_quick_edit( Quick_Edit_Grant::COLUMN, 'post' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/** @testdox A nonced inline save with a user picked grants through the writer, stamped admin. */
	public function test_save_grants_through_the_writer(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$_POST[ Quick_Edit_Grant::NONCE ] = wp_create_nonce( Quick_Edit_Grant::NONCE );
		$_POST['gatedmedia_qe_user']      = (string) $this->user_id;
		$_POST['gatedmedia_qe_duration']  = '30';

		$this->quick_edit->save( $post_id );

		$records = $this->records_for( $post_id );
		$this->assertCount( 1, $records );
		$this->assertSame( 'admin', get_post_meta( $records[0], Access_Writer::META_SOURCE, true ) );
		$this->assertNotSame( '', get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true ) );
	}

	/** @testdox No user picked, no grant; no nonce, nothing at all. */
	public function test_save_without_a_pick_or_nonce_writes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$_POST[ Quick_Edit_Grant::NONCE ] = wp_create_nonce( Quick_Edit_Grant::NONCE );
		$_POST['gatedmedia_qe_user']      = '0';
		$this->quick_edit->save( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );

		unset( $_POST[ Quick_Edit_Grant::NONCE ] );
		$_POST['gatedmedia_qe_user'] = (string) $this->user_id;
		$this->quick_edit->save( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/**
	 * The access records pointing at one post.
	 *
	 * @param int $post_id The item.
	 * @return array<int, int>
	 */
	private function records_for( int $post_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => Post_Types::STATUS_ACTIVE,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test assertion.
				'meta_query'     => array(
					array(
						'key'   => Access_Writer::META_ITEM_ID,
						'value' => (string) $post_id,
					),
				),
			)
		);

		return array_map( 'intval', $ids );
	}
}
