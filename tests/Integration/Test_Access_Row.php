<?php
/**
 * One held thing, as a row.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Access_Row;

/**
 * Every row is built from something that may no longer be there. An access
 * record outlives the post it points at, and the three builders each answer
 * null rather than draw a row for a thing that has gone.
 *
 * **The refusals are the point.** Nothing had ever run the deleted post, the
 * unpublished post, the missing group or the id that is not an attachment, so
 * "no row" was a promise the class made and nobody had checked.
 *
 * @group integration
 */
class Test_Access_Row extends WP_UnitTestCase {

	private Access_Row $rows;

	private Access_Taxonomy $taxonomy;

	public function set_up(): void {
		parent::set_up();

		$this->taxonomy = new Access_Taxonomy();
		$this->rows     = new Access_Row( $this->taxonomy );
	}

	public function tear_down(): void {
		// Two of the file tests put a real file in uploads.
		$this->remove_added_uploads();

		parent::tear_down();
	}

	/**
	 * A group, by the identity access records actually point at.
	 *
	 * @param string $name The group's name.
	 */
	private function group_uuid( string $name ): string {
		$term = wp_insert_term( $name, Access_Taxonomy::TAXONOMY );

		return $this->taxonomy->uuid_for( $term['term_id'] );
	}

	/** @testdox A held group is named by its term, counted by what it holds, and opens the group's own page. */
	public function test_group_row(): void {
		$uuid = $this->group_uuid( 'Analyst briefings' );
		$term = $this->taxonomy->find_group( $uuid );

		foreach ( self::factory()->post->create_many( 2 ) as $post_id ) {
			wp_set_object_terms( $post_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY, true );
		}

		$row = $this->rows->group( $uuid, null );

		$this->assertSame( 'Analyst briefings', $row['title'] );
		$this->assertSame( '2 items', $row['meta'] );
		$this->assertSame( home_url( '/account/my-access/' . $uuid . '/' ), $row['href'] );
		$this->assertSame( '', $row['action_label'] );
		$this->assertSame( 'lifetime', $row['expiry_state'] );
	}

	/** @testdox An empty group still says how much of nothing it holds. */
	public function test_empty_group_counts_zero(): void {
		$row = $this->rows->group( $this->group_uuid( 'Nothing yet' ), null );

		$this->assertSame( '0 items', $row['meta'] );
	}

	/** @testdox A group whose term has gone draws no row at all. */
	public function test_missing_group_is_no_row(): void {
		$this->assertNull( $this->rows->group( 'not-a-real-uuid', null ) );
	}

	/** @testdox A held post is named by its title and links to itself, with View as the thing to do. */
	public function test_post_row(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The briefing' ) );

		$row = $this->rows->post( $post_id, null );

		$this->assertSame( 'The briefing', $row['title'] );
		$this->assertSame( '', $row['meta'] );
		$this->assertSame( get_permalink( $post_id ), $row['href'] );
		$this->assertSame( 'View', $row['action_label'] );
		$this->assertSame( 'lifetime', $row['expiry_state'] );
	}

	/** @testdox A post that has been deleted draws no row, however long the access still runs. */
	public function test_deleted_post_is_no_row(): void {
		$this->assertNull( $this->rows->post( 999999, null ) );
	}

	/** @testdox A post pulled back to draft draws no row, even though the access record survives. */
	public function test_unpublished_post_is_no_row(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->assertNull( $this->rows->post( $post_id, null ) );
	}

	/** @testdox A file is named by its title over its type and size, and downloads. */
	public function test_file_row(): void {
		$upload = wp_upload_bits( 'quarterly-report.pdf', null, str_repeat( 'x', 2048 ) );

		$file_id = self::factory()->attachment->create(
			array(
				'post_title'     => 'Quarterly report',
				'post_mime_type' => 'application/pdf',
				'file'           => $upload['file'],
			)
		);

		$row = $this->rows->file( $file_id, null );

		$this->assertSame( 'Quarterly report', $row['title'] );
		$this->assertSame( 'PDF · 2 KB', $row['meta'] );
		$this->assertSame( wp_get_attachment_url( $file_id ), $row['href'] );
		$this->assertSame( 'Download', $row['action_label'] );
	}

	/** @testdox A file whose bytes are missing from disk keeps its row and simply says nothing about size. */
	public function test_file_row_without_a_file_on_disk(): void {
		$file_id = self::factory()->attachment->create(
			array(
				'post_title'     => 'Quarterly report',
				'post_mime_type' => 'application/pdf',
				'file'           => 'gated/never-written.pdf',
			)
		);

		$row = $this->rows->file( $file_id, null );

		$this->assertSame( 'PDF', $row['meta'] );
	}

	/** @testdox An untitled file is named by its filename rather than going blank. */
	public function test_untitled_file_falls_back_to_the_filename(): void {
		$upload = wp_upload_bits( 'annual-review.pdf', null, 'x' );

		$file_id = self::factory()->attachment->create(
			array(
				'post_title'     => '',
				'post_mime_type' => 'application/pdf',
				'file'           => $upload['file'],
			)
		);

		$row = $this->rows->file( $file_id, null );

		$this->assertSame( 'annual-review.pdf', $row['title'] );
	}

	/** @testdox An id that turns out to be a post, or nothing, is not a file and draws no row. */
	public function test_what_is_not_an_attachment_is_no_row(): void {
		$this->assertNull( $this->rows->file( self::factory()->post->create(), null ) );
		$this->assertNull( $this->rows->file( 999999, null ) );
	}

	/** @testdox A meta line keeps only the parts that are there, separated by a dot. */
	public function test_joined_drops_what_is_missing(): void {
		$this->assertSame( 'PDF · 2 KB', Access_Row::joined( array( 'PDF', '2 KB' ) ) );
		$this->assertSame( 'PDF', Access_Row::joined( array( 'PDF', '' ) ) );
		$this->assertSame( '2 KB', Access_Row::joined( array( '', '2 KB' ) ) );
		$this->assertSame( '', Access_Row::joined( array( '', '' ) ) );
		$this->assertSame( '', Access_Row::joined( array() ) );
	}
}
