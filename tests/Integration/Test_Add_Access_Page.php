<?php
/**
 * The Add Access form page.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Add_Access_Page;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The form's fields become one writer grant — the only admin door into a
 * record — and the page sits behind the give-access capability.
 *
 * @group integration
 */
class Test_Add_Access_Page extends WP_UnitTestCase {

	private Add_Access_Page $page;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy     = new Access_Taxonomy();
		$this->writer = new Access_Writer( new Grant_Validator( $taxonomy ) );
		$this->page   = new Add_Access_Page( $this->writer, $taxonomy );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** @testdox A post grant from the form writes the record through the writer, stamped admin and created-by. */
	public function test_creates_a_post_grant(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create();

		$access_id = $this->page->create(
			array(
				'user'      => $this->user_id,
				'item_type' => 'post',
				'group'     => '',
				'item_id'   => (string) $post_id,
				'duration'  => '30',
			)
		);

		$this->assertIsInt( $access_id );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $access_id ) );
		$this->assertSame( 'admin', get_post_meta( $access_id, Access_Writer::META_SOURCE, true ) );
		$this->assertSame( (string) $admin_id, get_post_meta( $access_id, Access_Writer::META_CREATED_BY, true ) );
	}

	/** @testdox A group grant takes the group field, not the ID field. */
	public function test_creates_a_group_grant_from_the_group_field(): void {
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		$uuid = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$access_id = $this->page->create(
			array(
				'user'      => $this->user_id,
				'item_type' => 'group',
				'group'     => $uuid,
				'item_id'   => '999999',
				'duration'  => '',
			)
		);

		$this->assertIsInt( $access_id );
		$this->assertSame( 'group', get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true ) );
		$this->assertSame( $uuid, get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true ) );
	}

	/** @testdox An empty duration is a lifetime grant. */
	public function test_empty_duration_is_lifetime(): void {
		$post_id = self::factory()->post->create();

		$access_id = $this->page->create(
			array(
				'user'      => $this->user_id,
				'item_type' => 'post',
				'group'     => '',
				'item_id'   => (string) $post_id,
				'duration'  => '',
			)
		);

		$this->assertIsInt( $access_id );
		$this->assertSame( '', get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true ) );
	}

	/** @testdox The writer's refusal comes back whole — nothing is written for bad input. */
	public function test_invalid_input_is_refused(): void {
		$refused = $this->page->create(
			array(
				'user'      => 0,
				'item_type' => 'post',
				'group'     => '',
				'item_id'   => '1',
				'duration'  => '',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'gatedmedia_invalid_user', $refused->get_error_code() );
	}

	/** @testdox The page registers under the plugin menu behind the give-access capability. */
	public function test_page_is_registered_and_gated(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->page->register_page();

		$this->assertNotFalse( menu_page_url( Add_Access_Page::PAGE_SLUG, false ) );

		global $submenu;
		$entries = array_values( array_filter( $submenu['gated-media-access'] ?? array(), static fn ( array $entry ): bool => Add_Access_Page::PAGE_SLUG === $entry[2] ) );

		$this->assertCount( 1, $entries );
		$this->assertSame( Capabilities::GIVE_ACCESS, $entries[0][1] );
	}

	/** @testdox The form renders the four fields, nonced, posting to admin-post. */
	public function test_form_renders_the_fields(): void {
		self::factory()->term->create( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'gatedmedia_add_access', $html );
		// wp_dropdown_users() emits single-quoted attributes.
		$this->assertStringContainsString( "name='gatedmedia_user'", $html );
		$this->assertStringContainsString( 'name="gatedmedia_item_type"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_group"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_item_id"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_duration"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		$this->assertStringContainsString( 'Members', $html );
	}

	/** @testdox The metabox's link pre-fills the form: type selected, item filled. */
	public function test_prefill_from_the_metabox_link(): void {
		$_GET['gatedmedia_type'] = 'file';
		$_GET['gatedmedia_item'] = '123';

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		unset( $_GET['gatedmedia_type'], $_GET['gatedmedia_item'] );

		$this->assertMatchesRegularExpression( '/value="file"\s+selected=\'selected\'/', $html );
		$this->assertStringContainsString( 'value="123"', $html );
	}

	/** @testdox The outcome notices render from the redirect flags. */
	public function test_notices_render_from_flags(): void {
		$_GET['gatedmedia_granted'] = '1';
		ob_start();
		$this->page->render_notices();
		$granted = (string) ob_get_clean();
		unset( $_GET['gatedmedia_granted'] );

		$this->assertStringContainsString( 'notice-success', $granted );
		$this->assertStringContainsString( 'Access granted.', $granted );

		$_GET['gatedmedia_revoked'] = '1';
		ob_start();
		$this->page->render_notices();
		$revoked = (string) ob_get_clean();
		unset( $_GET['gatedmedia_revoked'] );

		$this->assertStringContainsString( 'Access revoked.', $revoked );
	}
}
