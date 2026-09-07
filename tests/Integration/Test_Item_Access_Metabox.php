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
use PinkCrab\Gated_Access\Registration\Post_Types;

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

	/** @testdox A direct holder renders with their expiry and a nonced revoke link through the revoke action. */
	public function test_renders_direct_holders_with_revoke_links(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		$this->assertIsInt( $access_id );

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'Dave Holder', $html );
		$this->assertStringContainsString( 'Lifetime', $html );
		$this->assertStringContainsString( 'gatedmedia_revoke_access', $html );
		$this->assertStringContainsString( 'access=' . $access_id, $html );
		$this->assertStringContainsString( 'Revoke', $html );
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

	/** @testdox The inline grant renders: user picker, days, and the nonce its save is checked against. */
	public function test_inline_grant_renders(): void {
		$post_id = self::factory()->post->create();

		$html = $this->render( $post_id );

		$this->assertStringContainsString( 'gatedmedia-inline-grant', $html );
		$this->assertStringContainsString( 'data-gatedmedia-picker="gatedmedia_search_users"', $html );
		$this->assertStringContainsString( 'gatedmedia-inline-grant-days', $html );
		$this->assertStringContainsString( Item_Access_Metabox::SAVE_NONCE, $html );
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
		$this->assertStringContainsString( sprintf( 'name="%s"', Item_Access_Metabox::FIELD_GROUP ), $html );
		// The out-group is not listed as a membership.
		$this->assertStringNotContainsString( 'group=' . $out_uuid, $html );
		// The restricted marker is never listed as a group.
		$this->assertStringNotContainsString( 'restricted', $html );
	}

	/**
	 * @testdox Changing an item's groups needs the right to edit that item.
	 *
	 * Adding a group applies the restricted marker and, for an attachment,
	 * physically moves the file — so holding manage_categories alone was
	 * enough to restrict content the person cannot edit.
	 */
	public function test_apply_group_needs_edit_post(): void {
		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Board' ) );
		$uuid    = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$editor = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_role( 'subscriber' )->add_cap( 'manage_categories' );

		wp_set_current_user( $editor );

		$this->assertFalse( $this->metabox->apply_group( $post_id, $uuid, 'add' ) );
		$this->assertNotContains( $term->slug, $this->object_slugs( $post_id ) );

		get_role( 'subscriber' )->remove_cap( 'manage_categories' );
	}

	/** @testdox Adding to a group runs the restriction behaviours; removing leaves the restriction in place. */
	public function test_apply_group_add_and_remove(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get( array( 'taxonomy' => Access_Taxonomy::TAXONOMY, 'name' => 'Members' ) );
		$uuid    = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$this->assertFalse( $this->metabox->apply_group( $post_id, wp_generate_uuid4(), 'add' ) );
		$this->assertFalse( $this->metabox->apply_group( $post_id, $uuid, 'obliterate' ) );
		$this->assertFalse( $this->metabox->apply_group( 999999, $uuid, 'add' ) );
	}

	/**
	 * @testdox An inline grant is staged in the form, not fired the moment it is pressed.
	 *
	 * The button set window.location to an admin-post URL, so pressing it left
	 * the editor mid-edit: whatever had been typed and not saved was lost, and
	 * the access was written against a post the administrator might then never
	 * save. The box carries its own fields inside the editor's form instead.
	 */
	public function test_the_grant_is_staged_in_the_form(): void {
		$post_id = self::factory()->post->create();
		$html    = $this->render( $post_id );

		$this->assertStringContainsString(
			sprintf( 'name="%s"', Item_Access_Metabox::FIELD_USER ),
			$html
		);
		$this->assertStringContainsString(
			sprintf( 'name="%s"', Item_Access_Metabox::FIELD_DAYS ),
			$html
		);
		$this->assertStringContainsString( Item_Access_Metabox::SAVE_NONCE, $html );

		// Nothing may navigate away from the editor any more.
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'data-gatedmedia-url', $html );
	}

	/** @testdox Rendering the box grants nothing by itself. */
	public function test_rendering_grants_nothing(): void {
		$post_id = self::factory()->post->create();

		$this->render( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/** @testdox Saving the post applies the staged grant. */
	public function test_saving_applies_the_staged_grant(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, $this->user_id, '30' );

		$this->metabox->save_item( $post_id );

		$records = $this->records_for( $post_id );

		$this->assertCount( 1, $records );
		$this->assertSame(
			$this->user_id,
			(int) get_post_field( 'post_author', $records[0] )
		);
	}

	/** @testdox An empty days box stages lifetime access, as the description says. */
	public function test_an_empty_days_box_grants_lifetime(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, $this->user_id, '' );
		$this->metabox->save_item( $post_id );

		$records = $this->records_for( $post_id );

		$this->assertCount( 1, $records );
		$this->assertSame(
			'',
			(string) get_post_meta( $records[0], Access_Writer::META_EXPIRES_AT, true )
		);
	}

	/** @testdox A save carrying no staged user grants nothing. */
	public function test_a_save_without_a_user_grants_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, 0, '30' );
		$this->metabox->save_item( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/** @testdox A save with no nonce grants nothing, whoever is signed in. */
	public function test_a_save_without_the_nonce_grants_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, $this->user_id, '30' );
		unset( $_POST[ Item_Access_Metabox::SAVE_NONCE ] );

		$this->metabox->save_item( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/** @testdox A save by somebody who may not give access grants nothing. */
	public function test_a_save_without_the_capability_grants_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, $this->user_id, '30' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->metabox->save_item( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/**
	 * @testdox An autosave grants nothing: it is not the administrator pressing Update.
	 *
	 * In its own process: DOING_AUTOSAVE is a constant, and a constant defined
	 * here would stay defined for every test after it, so every later save
	 * would return at the first line and pass for the wrong reason.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_autosave_grants_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();

		$this->stage_grant( $post_id, $this->user_id, '30' );

		define( 'DOING_AUTOSAVE', true );
		$this->metabox->save_item( $post_id );

		$this->assertSame( array(), $this->records_for( $post_id ) );
	}

	/** @testdox Adding to a group is staged in the form too, not fired on the press. */
	public function test_the_group_add_is_staged_in_the_form(): void {
		$post_id = self::factory()->post->create();
		$html    = $this->render( $post_id );

		$this->assertStringContainsString(
			sprintf( 'name="%s"', Item_Access_Metabox::FIELD_GROUP ),
			$html
		);
	}

	/** @testdox Saving the post puts the item in the staged group. */
	public function test_saving_applies_the_staged_group(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get(
			array(
				'taxonomy' => Access_Taxonomy::TAXONOMY,
				'name'     => 'Briefings',
			)
		);
		$uuid = ( new Access_Taxonomy() )->uuid_for( $term->term_id );

		$this->stage_grant( $post_id, 0, '' );
		$_POST[ Item_Access_Metabox::FIELD_GROUP ] = $uuid;

		$this->metabox->save_item( $post_id );

		$this->assertContains( $term->slug, $this->object_slugs( $post_id ) );
	}

	/** @testdox A save with no staged group leaves the item's groups alone. */
	public function test_a_save_without_a_group_changes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create();
		$term    = self::factory()->term->create_and_get(
			array(
				'taxonomy' => Access_Taxonomy::TAXONOMY,
				'name'     => 'Left alone',
			)
		);

		wp_set_object_terms( $post_id, array( $term->term_id ), Access_Taxonomy::TAXONOMY );

		$this->stage_grant( $post_id, 0, '' );
		$this->metabox->save_item( $post_id );

		$this->assertContains( $term->slug, $this->object_slugs( $post_id ) );
	}

	/**
	 * Puts one pending grant into the request, as the metabox's own fields do.
	 *
	 * @param int    $post_id The item being edited.
	 * @param int    $user_id Who is to gain access, 0 for nobody chosen.
	 * @param string $days    Days, or '' for lifetime.
	 */
	private function stage_grant( int $post_id, int $user_id, string $days ): void {
		$_POST[ Item_Access_Metabox::SAVE_NONCE ] = wp_create_nonce( Item_Access_Metabox::SAVE_ACTION . '_' . $post_id );
		$_POST[ Item_Access_Metabox::FIELD_USER ] = (string) $user_id;
		$_POST[ Item_Access_Metabox::FIELD_DAYS ] = $days;
	}

	/**
	 * The access records pointing at one item.
	 *
	 * @param int $post_id The item.
	 * @return array<int, int>
	 */
	private function records_for( int $post_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => Post_Types::ACCESS,
					// Named, never 'any': the access statuses are excluded
					// from search, so 'any' does not see them.
					'post_status'    => array(
						Post_Types::STATUS_ACTIVE,
						Post_Types::STATUS_EXPIRED,
						Post_Types::STATUS_REVOKED,
					),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test assertion.
						array(
							'key'   => Access_Writer::META_ITEM_ID,
							'value' => (string) $post_id,
						),
					),
				)
			)
		);
	}

	/**
	 * @testdox A holder's link is named Revoke, the same as on the Access list.
	 *
	 * Both links go to Revoke_Action, whose effect is whatever the
	 * revoke_behaviour setting says, up to deleting the record outright.
	 * "Remove" beside a name reads as taking that person off a list, and the
	 * settings page calls the whole concept "Revoking access".
	 */
	public function test_the_holder_link_is_named_revoke(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Dale Holder',
			)
		);
		$post_id = self::factory()->post->create();

		$this->assertIsInt( $this->writer->grant( $user_id, 'post', (string) $post_id, 30, 'admin' ) );

		$html = $this->render( $post_id );

		$this->assertStringContainsString( '>Revoke</a>', $html );
		$this->assertStringNotContainsString( '>Remove</a>', $html );
	}

	/**
	 * @testdox The inline grant's user box and days box are both labelled.
	 *
	 * The metabox gave the picker and the days field a placeholder and nothing
	 * else, so both were announced as unlabelled edit fields — and the days
	 * field carried no id at all, which no label could have targeted.
	 */
	public function test_the_inline_grant_controls_are_labelled(): void {
		$post_id = self::factory()->post->create();
		$html    = $this->render( $post_id );

		foreach ( array(
			'gatedmedia_metabox_user_' . $post_id . '_search',
			'gatedmedia_metabox_days_' . $post_id,
		) as $id ) {
			$this->assertMatchesRegularExpression(
				sprintf( '/<label[^>]*\bfor="%s"[^>]*>/', preg_quote( $id, '/' ) ),
				$html,
				sprintf( 'No label targets %s.', $id )
			);
		}

		// The days field has to exist under that id for the label to mean
		// anything.
		$this->assertStringContainsString(
			sprintf( 'id="gatedmedia_metabox_days_%d"', $post_id ),
			$html
		);
	}

	/** @testdox Two items on one screen do not share the days field's id. */
	public function test_the_days_field_id_is_per_item(): void {
		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();

		$this->assertStringContainsString(
			sprintf( 'id="gatedmedia_metabox_days_%d"', $first ),
			$this->render( $first )
		);
		$this->assertStringContainsString(
			sprintf( 'id="gatedmedia_metabox_days_%d"', $second ),
			$this->render( $second )
		);
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
