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

	/** @testdox Core's free-tagging surfaces are all off — the item metabox is the assignment surface. */
	public function test_editor_surfaces_are_off(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		// Round 4: the editor panel (REST), the classic tag box and the
		// quick edit field all gave a free-tagging way to mint groups.
		$this->assertFalse( $taxonomy->show_in_rest );
		$this->assertFalse( $taxonomy->meta_box_cb );
		$this->assertFalse( $taxonomy->show_in_quick_edit );
		// The Groups screens themselves stay.
		$this->assertTrue( $taxonomy->show_ui );
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

	/** @testdox A newly created group gets a UUID, with no UI involved. */
	public function test_a_created_term_gets_a_uuid(): void {
		$term = wp_insert_term( 'Quarterly Reports', Access_Taxonomy::TAXONOMY );

		$this->assertIsArray( $term );

		$uuid = (string) get_term_meta( $term['term_id'], Access_Taxonomy::UUID_META, true );

		$this->assertTrue( wp_is_uuid( $uuid ), 'no UUID was minted on creation' );
	}

	/** @testdox find_group() round-trips a term's UUID back to the term. */
	public function test_find_group_round_trips(): void {
		$term = wp_insert_term( 'Board Papers', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$taxonomy = new Access_Taxonomy();
		$uuid     = $taxonomy->uuid_for( $term['term_id'] );
		$found    = $taxonomy->find_group( $uuid );

		$this->assertNotNull( $found );
		$this->assertSame( $term['term_id'], $found->term_id );
		$this->assertNull( $taxonomy->find_group( wp_generate_uuid4() ) );
	}

	/** @testdox A term with no UUID is backfilled the first time uuid_for() asks. */
	public function test_uuid_for_backfills(): void {
		$term = wp_insert_term( 'Legacy Group', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		delete_term_meta( $term['term_id'], Access_Taxonomy::UUID_META );

		$uuid = ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );

		$this->assertTrue( wp_is_uuid( $uuid ) );
		$this->assertSame( $uuid, get_term_meta( $term['term_id'], Access_Taxonomy::UUID_META, true ) );
	}

	/** @testdox An editor save cannot create a group; assigning an existing one still works. */
	public function test_editor_saves_cannot_create_groups(): void {
		$existing = wp_insert_term( 'Made On The Groups Screen', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $existing );

		foreach ( array( 'inline-save', 'editpost', 'bulk-edit' ) as $origin ) {
			$_POST['action'] = $origin;

			$refused = wp_insert_term( 'Sneaky Group ' . $origin, Access_Taxonomy::TAXONOMY );

			$this->assertInstanceOf( \WP_Error::class, $refused, $origin );
			$this->assertSame( 'gatedmedia_group_creation_forbidden', $refused->get_error_code(), $origin );
		}

		// Assigning the existing group from those saves is untouched.
		$_POST['action'] = 'inline-save';
		$post_id         = self::factory()->post->create();
		$assigned        = wp_set_object_terms( $post_id, array( $existing['term_id'] ), Access_Taxonomy::TAXONOMY );
		unset( $_POST['action'] );

		$this->assertIsArray( $assigned );
		$this->assertNotEmpty( $assigned );

		// Other taxonomies are left alone entirely.
		$_POST['action'] = 'inline-save';
		$category        = wp_insert_term( 'Ordinary Category', 'category' );
		unset( $_POST['action'] );

		$this->assertIsArray( $category );
	}

	/** @testdox REST cannot create a group — no terms route exists, and the guard 403s regardless. */
	public function test_rest_cannot_create_groups(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Booting core's own REST server for the test.

		$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Access_Taxonomy::TAXONOMY );
		$request->set_param( 'name', 'Sneaky REST Group' );

		$response = rest_do_request( $request );

		// show_in_rest false means no route at all (404); the
		// rest_request_before_callbacks guard would 403 one if it ever
		// came back. Either way: refused, and nothing written.
		$this->assertContains( $response->get_status(), array( 403, 404 ) );
		$this->assertSame( array(), get_terms( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'hide_empty' => false, 'name' => 'Sneaky REST Group' ) ) );
	}
}
