<?php
/**
 * The per-item access metabox.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Item_Access_Metabox;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The item's edit screen shows who holds it directly, removable through the
 * revoke action, with adding one click away.
 *
 * @group integration
 */
class Test_Item_Access_Metabox extends WP_UnitTestCase {

	private Item_Access_Metabox $metabox;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy      = new Access_Taxonomy();
		$this->writer  = new Access_Writer( new Access_Validator( $taxonomy ), new Access_Lookup() );
		$this->metabox = new Item_Access_Metabox( $taxonomy, $this->writer );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Dave Holder',
			)
		);
	}

	/** @testdox With the capability, the metabox registers for every restrictable type; without, for none. */
	public function test_registers_behind_the_capability(): void {
		global $wp_meta_boxes;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->metabox->register_metabox();

		foreach ( array( 'post', 'page', 'attachment' ) as $type ) {
			$this->assertArrayHasKey( 'gatedmedia_item_access', $wp_meta_boxes[ $type ]['side']['default'] ?? array(), $type );
		}

		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting test state.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->metabox->register_metabox();

		$this->assertSame( array(), $wp_meta_boxes );
	}

	/** @testdox A direct holder renders with their expiry and a nonced remove link through the revoke action. */
	public function test_renders_direct_holders_with_remove_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		$this->assertIsInt( $access_id );

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'Dave Holder', $html );
		$this->assertStringContainsString( 'Lifetime', $html );
		$this->assertStringContainsString( 'gatedmedia_revoke_access', $html );
		$this->assertStringContainsString( 'access=' . $access_id, $html );
		$this->assertStringContainsString( 'Remove', $html );
	}

	/** @testdox A user holding the item only through a group is not a direct holder, and is not listed. */
	public function test_group_holders_are_not_listed(): void {
		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		wp_set_object_terms( $post_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY );

		$uuid = ( new Access_Taxonomy() )->uuid_for( $term->term_id );
		$this->assertIsInt( $this->writer->grant( $this->user_id, 'group', $uuid, null, 'admin' ) );

		$html = $this->render( $post_id );

		$this->assertStringNotContainsString( 'Dave Holder', $html );
		$this->assertStringContainsString( 'Nobody holds direct access', $html );
	}

	/** @testdox An attachment's metabox reads file records, and an inline grant on it writes a file record. */
	public function test_attachment_reads_and_grants_file_records(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertIsInt( $this->writer->grant( $this->user_id, 'file', (string) $attachment_id, 30, 'admin' ) );

		$this->assertStringContainsString( 'Dave Holder', $this->render( $attachment_id ) );

		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertTrue( $this->metabox->apply_grant( $attachment_id, $other, 0 ) );

		$records = get_posts(
			array(
				'post_type'      => \PinkCrab\Gated_Access\Registration\Post_Types::ACCESS,
				'post_status'    => \PinkCrab\Gated_Access\Registration\Post_Types::STATUS_ACTIVE,
				'author'         => $other,
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->assertCount( 1, $records );
		$this->assertSame( 'file', get_post_meta( (int) $records[0], Access_Writer::META_ITEM_TYPE, true ) );
	}

	/** @testdox The inline grant renders: user picker, days, a nonced grant button. */
	public function test_inline_grant_renders(): void {
		$post_id = self::factory()->post->create();

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'gatedmedia-inline-grant', $html );
		$this->assertStringContainsString( 'data-gatedmedia-picker="gatedmedia_search_users"', $html );
		$this->assertStringContainsString( 'gatedmedia-grant-access', $html );
		$this->assertStringContainsString( 'gatedmedia_item_grant', $html );
		$this->assertStringContainsString( 'item=' . $post_id, $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	/** @testdox An inline grant for nobody writes nothing. */
	public function test_inline_grant_refuses_no_user(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertFalse( $this->metabox->apply_grant( self::factory()->post->create(), 0, 0 ) );
	}

	/** @testdox The metabox lists the item's groups with remove links, and offers the others through the picker. */
	public function test_groups_render_with_remove_and_picker(): void {
		$post_id = self::factory()->post->create();
		$in      = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		$out     = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Staff' ) );
		wp_set_object_terms( $post_id, array( $in->term_id ), Access_Taxonomy::TAXONOMY );

		$taxonomy = new Access_Taxonomy();
		$in_uuid  = $taxonomy->uuid_for( $in->term_id );
		$out_uuid = $taxonomy->uuid_for( $out->term_id );

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'Members', $html );
		$this->assertStringContainsString( 'op=remove', $html );
		$this->assertStringContainsString( 'group=' . $in_uuid, $html );
		// The group picker is the same searchable pattern as every other.
		$this->assertStringContainsString( 'data-gatedmedia-picker="gatedmedia_search_groups"', $html );
		$this->assertStringContainsString( 'gatedmedia-add-to-group', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		// The out-group is not listed as a membership.
		$this->assertStringNotContainsString( 'group=' . $out_uuid, $html );
		// The restricted marker is never listed as a group.
		$this->assertStringNotContainsString( 'restricted', $html );
	}

	/** @testdox Adding to a group runs the restriction behaviours; removing leaves the restriction in place. */
	public function test_apply_group_add_and_remove(): void {
		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		$uuid    = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$this->assertTrue( $this->metabox->apply_group( $post_id, $uuid, 'add' ) );

		$slugs = $this->object_slugs( $post_id );
		$this->assertContains( $term->slug, $slugs );
		// The marker arrived with the group — Restriction's behaviour.
		$this->assertContains( 'restricted', $slugs );

		$this->assertTrue( $this->metabox->apply_group( $post_id, $uuid, 'remove' ) );

		$slugs = $this->object_slugs( $post_id );
		$this->assertNotContains( $term->slug, $slugs );
		// Removal never unrestricts — that stays a deliberate act.
		$this->assertContains( 'restricted', $slugs );
	}

	/**
	 * The object's term slugs, marker included — it is hidden from default
	 * term queries by design.
	 *
	 * @param int $object_id The post or attachment.
	 * @return array<int, string>
	 */
	private function object_slugs( int $object_id ): array {
		$slugs = wp_get_object_terms(
			array( $object_id ),
			Access_Taxonomy::TAXONOMY,
			array(
				'fields'                     => 'slugs',
				\PinkCrab\Gated_Access\Access\Restriction::INCLUDE_MARKER => true,
			)
		);

		return is_array( $slugs ) ? $slugs : array();
	}

	/** @testdox An unknown group or op applies nothing. */
	public function test_apply_group_refuses_bad_input(): void {
		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		$uuid    = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$this->assertFalse( $this->metabox->apply_group( $post_id, wp_generate_uuid4(), 'add' ) );
		$this->assertFalse( $this->metabox->apply_group( $post_id, $uuid, 'obliterate' ) );
		$this->assertFalse( $this->metabox->apply_group( 999999, $uuid, 'add' ) );
	}

	/**
	 * What the metabox renders for one item.
	 *
	 * @param int $post_id The post or attachment.
	 */
	private function render( int $post_id ): string {
		ob_start();
		$this->metabox->render( get_post( $post_id ) );

		return (string) ob_get_clean();
	}
}
