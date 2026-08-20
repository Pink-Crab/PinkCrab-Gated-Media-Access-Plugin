<?php
/**
 * The restriction wiring.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Grouping content marks it restricted, grouping an attachment restricts its
 * file, removal undoes neither, and the marker stays out of term lists.
 *
 * The booted plugin's own Restriction instance is live on `set_object_terms`
 * throughout — these tests assign terms and watch what follows.
 *
 * @group integration
 */
class Test_Restriction extends WP_UnitTestCase {

	/** @testdox Applying a group term to a post applies the restricted marker automatically. */
	public function test_group_term_applies_the_marker(): void {
		$post_id  = self::factory()->post->create();
		$group_id = $this->make_group();

		wp_set_object_terms( $post_id, array( $group_id ), Access_Taxonomy::TAXONOMY );

		$this->assertContains( Restriction::MARKER_SLUG, $this->object_slugs( $post_id ) );
	}

	/** @testdox The marker term is created on first need, with the slug restricted. */
	public function test_marker_created_on_first_need(): void {
		$this->assertNull( ( new Restriction() )->marker() );

		wp_set_object_terms( self::factory()->post->create(), array( $this->make_group() ), Access_Taxonomy::TAXONOMY );

		$marker = ( new Restriction() )->marker();

		$this->assertNotNull( $marker );
		$this->assertSame( Restriction::MARKER_SLUG, $marker->slug );
	}

	/** @testdox Applying a group term to an attachment restricts its file through the dependency. */
	public function test_grouping_an_attachment_restricts_the_file(): void {
		$attachment_id = $this->make_uploaded_attachment();

		$this->assertFalse( rmfa_is_media_restricted( $attachment_id ) );

		wp_set_object_terms( $attachment_id, array( $this->make_group() ), Access_Taxonomy::TAXONOMY );

		$this->assertTrue( rmfa_is_media_restricted( $attachment_id ) );
		$this->assertContains( Restriction::MARKER_SLUG, $this->object_slugs( $attachment_id ) );
	}

	/** @testdox Removing the group term leaves both the marker and the file restriction in place. */
	public function test_removal_leaves_marker_and_restriction(): void {
		$attachment_id = $this->make_uploaded_attachment();
		$group_id      = $this->make_group();

		wp_set_object_terms( $attachment_id, array( $group_id ), Access_Taxonomy::TAXONOMY );
		wp_remove_object_terms( $attachment_id, $group_id, Access_Taxonomy::TAXONOMY );

		$slugs = $this->object_slugs( $attachment_id );

		$this->assertNotContains( 'gold', $slugs );
		$this->assertContains( Restriction::MARKER_SLUG, $slugs );
		$this->assertTrue( rmfa_is_media_restricted( $attachment_id ) );
	}

	/** @testdox The marker is hidden from term lists, unless a caller asks for it by flag. */
	public function test_marker_hidden_from_term_lists(): void {
		wp_set_object_terms( self::factory()->post->create(), array( $this->make_group() ), Access_Taxonomy::TAXONOMY );

		$listed = get_terms(
			array(
				'taxonomy'   => Access_Taxonomy::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'slugs',
			)
		);

		$this->assertContains( 'gold', $listed );
		$this->assertNotContains( Restriction::MARKER_SLUG, $listed );

		$unhidden = get_terms(
			array(
				'taxonomy'                   => Access_Taxonomy::TAXONOMY,
				'hide_empty'                 => false,
				'fields'                     => 'slugs',
				Restriction::INCLUDE_MARKER  => true,
			)
		);

		$this->assertContains( Restriction::MARKER_SLUG, $unhidden );
	}

	/** @testdox Term lists in other taxonomies are left alone. */
	public function test_other_taxonomies_untouched(): void {
		wp_set_object_terms( self::factory()->post->create(), array( $this->make_group() ), Access_Taxonomy::TAXONOMY );
		$category_id = self::factory()->category->create( array( 'name' => 'News' ) );

		$listed = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);

		$this->assertContains( $category_id, $listed );
	}

	/** @testdox Applying the marker by hand, alone, sticks and triggers nothing else. */
	public function test_marker_applied_by_hand(): void {
		$post_id   = self::factory()->post->create();
		$marker_id = ( new Restriction() )->ensure_marker();

		wp_set_object_terms( $post_id, array( $marker_id ), Access_Taxonomy::TAXONOMY );

		$this->assertSame( array( Restriction::MARKER_SLUG ), $this->object_slugs( $post_id ) );
	}

	/**
	 * A group term to hang content on.
	 */
	private function make_group(): int {
		$term = wp_insert_term( 'Gold', Access_Taxonomy::TAXONOMY );

		if ( is_wp_error( $term ) ) {
			$existing = get_term_by( 'slug', 'gold', Access_Taxonomy::TAXONOMY );

			return false === $existing ? 0 : (int) $existing->term_id;
		}

		return (int) $term['term_id'];
	}

	/**
	 * An attachment with a real file inside the uploads directory, so the
	 * dependency's move-to-protected genuinely runs.
	 */
	private function make_uploaded_attachment(): int {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . '/restriction-' . wp_generate_password( 8, false ) . '.txt';

		file_put_contents( $path, 'restricted file contents' );

		$attachment_id = self::factory()->attachment->create_object(
			$path,
			0,
			array( 'post_mime_type' => 'text/plain' )
		);

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'  => basename( $path ),
				'sizes' => array(),
			)
		);

		return $attachment_id;
	}

	/**
	 * Every slug on the object, marker included.
	 *
	 * @param int $object_id The post or attachment.
	 * @return array<string>
	 */
	private function object_slugs( int $object_id ): array {
		$slugs = wp_get_object_terms(
			array( $object_id ),
			Access_Taxonomy::TAXONOMY,
			array(
				'fields'                    => 'slugs',
				Restriction::INCLUDE_MARKER => true,
			)
		);

		return is_array( $slugs ) ? $slugs : array();
	}
}
