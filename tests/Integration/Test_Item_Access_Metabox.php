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
use PinkCrab\Gated_Access\Access\Grant_Validator;
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

		$this->metabox = new Item_Access_Metabox();
		$this->writer  = new Access_Writer( new Grant_Validator( new Access_Taxonomy() ) );

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

	/** @testdox An attachment's metabox reads file records, and its add link carries type file. */
	public function test_attachment_reads_file_records(): void {
		$attachment_id = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		$this->assertIsInt( $this->writer->grant( $this->user_id, 'file', (string) $attachment_id, 30, 'admin' ) );

		$html = $this->render( $attachment_id );

		$this->assertStringContainsString( 'Dave Holder', $html );
		$this->assertStringContainsString( 'gatedmedia_type=file', $html );
		$this->assertStringContainsString( 'gatedmedia_item=' . $attachment_id, $html );
	}

	/** @testdox The add link lands on the Add Access page pre-filled with this item. */
	public function test_add_link_prefills_the_form(): void {
		$post_id = self::factory()->post->create();

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'page=gatedmedia-add-access', $html );
		$this->assertStringContainsString( 'gatedmedia_type=post', $html );
		$this->assertStringContainsString( 'gatedmedia_item=' . $post_id, $html );
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
