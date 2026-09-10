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
use PinkCrab\Gated_Access\Registration\Capabilities;

/**
 * The taxonomy exists on the right types, its object-type filter works, and it is invisible to the front while exposed to the editor.
 *
 * @group integration
 */
class Test_Access_Taxonomy extends WP_UnitTestCase {

	/**
	 * Re-registers with the default types, or a filtered list leaks into later tests.
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

	/** @testdox Core's free-tagging surfaces are all off, leaving the item metabox as the assignment surface. */
	public function test_editor_surfaces_are_off(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );
		// The REST editor panel, the classic tag box and the quick edit field each gave a free-tagging way to mint groups.
		$this->assertFalse( $taxonomy->show_in_rest );
		$this->assertFalse( $taxonomy->meta_box_cb );
		$this->assertFalse( $taxonomy->show_in_quick_edit );
		// The Groups screens themselves stay.
		$this->assertTrue( $taxonomy->show_ui );
	}

	/**
	 * @testdox Administering a group takes the plugin's own capabilities, not manage_categories.
	 *
	 * edit-tags.php?taxonomy=gatedmedia_access is reachable whatever the menu shows, and core gates it on these four capabilities alone. Mapped to manage_categories they were held by every Editor, who could rename or delete a group, and deleting one takes with it the term meta every access record names.
	 */
	public function test_term_capabilities_are_the_plugins_own(): void {
		$taxonomy = get_taxonomy( Access_Taxonomy::TAXONOMY );

		$this->assertNotFalse( $taxonomy );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( current_user_can( $taxonomy->cap->manage_terms ) );
		$this->assertFalse( current_user_can( $taxonomy->cap->edit_terms ) );
		$this->assertFalse( current_user_can( $taxonomy->cap->delete_terms ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( current_user_can( $taxonomy->cap->manage_terms ) );
		$this->assertTrue( current_user_can( $taxonomy->cap->delete_terms ) );

		$this->assertSame( Capabilities::manage_settings(), $taxonomy->cap->manage_terms );
		$this->assertSame( Capabilities::manage_settings(), $taxonomy->cap->edit_terms );
		$this->assertSame( Capabilities::manage_settings(), $taxonomy->cap->delete_terms );
		// Attaching a group to an item is the same act as granting access to it.
		$this->assertSame( Capabilities::give_access(), $taxonomy->cap->assign_terms );
	}

	/**
	 * @testdox The taxonomy and the access post type share the name gatedmedia_access, and both resolve.
	 *
	 * The shared string was a deliberate choice, and this pins that the two registries genuinely hold one entry each under it.
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

	/** @testdox REST cannot create a group: no terms route exists, and the guard 403s regardless. */
	public function test_rest_cannot_create_groups(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Booting core's own REST server for the test.

		$request = new \WP_REST_Request( 'POST', '/wp/v2/' . Access_Taxonomy::TAXONOMY );
		$request->set_param( 'name', 'Sneaky REST Group' );

		$response = rest_do_request( $request );

		// show_in_rest false means no route at all, and the guard would 403 one if it came back.
		$this->assertContains( $response->get_status(), array( 403, 404 ) );
		$this->assertSame( array(), get_terms( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'hide_empty' => false, 'name' => 'Sneaky REST Group' ) ) );
	}

	/**
	 * Asks contents() from the Groups screen's side of the fence.
	 *
	 * Putting anything in a group restricts it, and `Post_Boundary` then drops every restricted post into `post__not_in` on front-of-site queries, contents()' own included.
	 *
	 * These tests ask the administrator's question, "what does this group hold", so they ask from an admin screen where the boundary stands aside.
	 *
	 * @param int $term_id The group.
	 * @return array<int, int>
	 */
	private function group_contents( int $term_id ): array {
		set_current_screen( 'edit-tags.php' );

		$taxonomy = new Access_Taxonomy();

		return $taxonomy->contents( $taxonomy->uuid_for( $term_id ) );
	}

	/**
	 * Makes a group and hands back its UUID.
	 *
	 * @param string $name The group's name.
	 */
	private function make_group( string $name ): string {
		$term = wp_insert_term( $name, Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		return ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );
	}

	/**
	 * Puts an object in a group.
	 *
	 * @param int $object_id The post, page or attachment.
	 * @param int $term_id   The group.
	 */
	private function add_to_group( int $object_id, int $term_id ): void {
		wp_set_object_terms( $object_id, array( $term_id ), Access_Taxonomy::TAXONOMY, true );
	}

	/** @testdox contents() lists a published post held by the group. */
	public function test_contents_lists_a_published_post(): void {
		$term = wp_insert_term( 'Holds A Post', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$post_id = self::factory()->post->create();
		$this->add_to_group( $post_id, $term['term_id'] );

		$this->assertSame( array( $post_id ), $this->group_contents( $term['term_id'] ) );
	}

	/**
	 * @testdox contents() lists an attachment held by the group, whose status is inherit rather than publish.
	 *
	 * The regression guard for the named statuses: on the default `publish`, every file in a group disappears here while the term's count still counts it.
	 */
	public function test_contents_lists_an_attachment(): void {
		$term = wp_insert_term( 'Holds A File', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$attachment_id = self::factory()->attachment->create();
		$this->add_to_group( $attachment_id, $term['term_id'] );

		// The stored column, not get_post_status(), which resolves an unattached file to publish.
		$this->assertSame( 'inherit', get_post( $attachment_id )->post_status );

		$this->assertSame(
			array( $attachment_id ),
			$this->group_contents( $term['term_id'] ),
			'an attachment in the group was dropped from contents()'
		);
	}

	/** @testdox contents() lists a page held by the group. */
	public function test_contents_lists_a_page(): void {
		$term = wp_insert_term( 'Holds A Page', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->add_to_group( $page_id, $term['term_id'] );

		$this->assertSame( array( $page_id ), $this->group_contents( $term['term_id'] ) );
	}

	/** @testdox contents() lists a post, a page and an attachment together when a group holds all three. */
	public function test_contents_lists_a_mixed_group(): void {
		$term = wp_insert_term( 'Holds Everything', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$post_id       = self::factory()->post->create();
		$page_id       = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$attachment_id = self::factory()->attachment->create();

		foreach ( array( $post_id, $page_id, $attachment_id ) as $object_id ) {
			$this->add_to_group( $object_id, $term['term_id'] );
		}

		$contents = $this->group_contents( $term['term_id'] );

		$this->assertCount( 3, $contents );
		$this->assertEqualsCanonicalizing( array( $post_id, $page_id, $attachment_id ), $contents );
	}

	/** @testdox contents() returns nothing for a UUID no group holds. */
	public function test_contents_of_an_unknown_uuid_is_empty(): void {
		set_current_screen( 'edit-tags.php' );

		self::factory()->post->create();

		$this->assertSame( array(), ( new Access_Taxonomy() )->contents( wp_generate_uuid4() ) );
	}

	/** @testdox contents() returns nothing for a group holding nothing. */
	public function test_contents_of_an_empty_group_is_empty(): void {
		set_current_screen( 'edit-tags.php' );

		$uuid = $this->make_group( 'Holds Nothing' );

		// Content exists, it is just not in this group.
		self::factory()->post->create();
		self::factory()->attachment->create();

		$this->assertSame( array(), ( new Access_Taxonomy() )->contents( $uuid ) );
	}

	/** @testdox contents() leaves out a draft in the group, since only publish and inherit are named. */
	public function test_contents_excludes_a_draft(): void {
		$term = wp_insert_term( 'Holds A Draft', Access_Taxonomy::TAXONOMY );
		$this->assertIsArray( $term );

		$draft_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$published_id = self::factory()->post->create();

		$this->add_to_group( $draft_id, $term['term_id'] );
		$this->add_to_group( $published_id, $term['term_id'] );

		$contents = $this->group_contents( $term['term_id'] );

		$this->assertSame( array( $published_id ), $contents );
		$this->assertNotContains( $draft_id, $contents );
	}
}
