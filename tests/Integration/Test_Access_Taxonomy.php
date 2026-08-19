<?php
/**
 * The access taxonomy registration.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The taxonomy exists on the right types, its object-type filter works, and it
 * is invisible to the front while exposed to the editor.
 *
 * @group integration
 */
class Test_Access_Taxonomy extends WP_UnitTestCase {

	/**
	 * Re-registers with the default types, or a filtered list would leak into
	 * later tests.
	 */
	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_access_object_types' );
		( new Access_Taxonomy() )->register();

		parent::tear_down();
	}

	/** @testdox The access taxonomy is registered against post, page and attachment. */
	public function test_registered_against_the_default_object_types(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertSame( array( 'post', 'page', 'attachment' ), $taxonomy->object_type );
	}

	/** @testdox The gatedmedia_access_object_types filter genuinely changes the registered list. */
	public function test_the_object_types_filter_is_honoured(): void {
		add_filter(
			'gatedmedia_access_object_types',
			static fn (): array => array( 'post' )
		);

		( new Access_Taxonomy() )->register();

		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertSame( array( 'post' ), $taxonomy->object_type );
	}

	/** @testdox The taxonomy is not publicly queryable. */
	public function test_not_publicly_queryable(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertFalse( $taxonomy->publicly_queryable );
	}

	/** @testdox The taxonomy is exposed over REST, for the block editor's panel. */
	public function test_show_in_rest(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		$this->assertTrue( $taxonomy->show_in_rest );
	}

	/**
	 * @testdox The taxonomy and the access post type share the name gatedmedia_access, and both resolve.
	 *
	 * The shared string was a deliberate choice; this pins that the two
	 * registries genuinely hold one entry each under it.
	 */
	public function test_shares_its_name_with_the_access_post_type(): void {
		$this->assertTrue( post_type_exists( Access_Taxonomy::TAXONOMY ) );
		$this->assertTrue( taxonomy_exists( Access_Taxonomy::TAXONOMY ) );
	}
}
