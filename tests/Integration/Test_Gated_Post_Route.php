<?php
/**
 * Content reachable only at its UUID.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Gated_Post_Route;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Admin\Gated_Post_State;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * The status is a trigger, not a second access model: setting it applies the marker term, so everything restriction already means comes with it.
 *
 * The addressing is what is new, and what most of these assert: the UUID is the only road in, and the slug is closed to **everyone**, holders included.
 *
 * The refusal is a 404 and never a redirect, or the slug becomes an oracle for discovering the UUID.
 *
 * @group integration
 */
class Test_Gated_Post_Route extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rewrite;

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->register_meta();

		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();
	}

	public function tear_down(): void {
		global $wp_rewrite;

		remove_all_filters( 'gatedmedia_gated_path' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();

		parent::tear_down();
	}

	/**
	 * A published post moved to the gated status, as an editor would.
	 *
	 * @return array{0: int, 1: string} The post id and its UUID.
	 */
	private function make_gated_post(): array {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => Post_Types::STATUS_GATED,
			)
		);

		return array( $post_id, Uuid::ensure( 'post', $post_id ) );
	}

	private function resolver(): Resolver {
		return new Resolver( new Access_Taxonomy() );
	}

	/**
	 * A group holding the given post, granted to the user.
	 *
	 * @param int $post_id What the group holds.
	 * @return string The group's UUID.
	 */
	private function granted_group_holding( int $post_id ): string {
		$term = wp_insert_term( 'Gated Group ' . wp_rand(), Access_Taxonomy::TAXONOMY );

		wp_set_object_terms( $post_id, array( $term['term_id'] ), Access_Taxonomy::TAXONOMY, true );

		$uuid = ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );

		$this->writer->grant( $this->user_id, 'group', $uuid, null, 'admin' );

		return $uuid;
	}

	/**
	 * Every reader admitting only `publish` drops the post, 404ing the group holder at the one URL meant to work.
	 *
	 * @testdox A gated post inside a granted group is reachable at its UUID.
	 */
	public function test_a_group_holder_reaches_a_gated_post(): void {
		[ $post_id, $uuid ] = $this->make_gated_post();

		$this->granted_group_holding( $post_id );

		wp_set_current_user( $this->user_id );

		$this->assertTrue( $this->resolver()->can_see( $this->user_id, 'post', (string) $post_id ), 'the group holds it' );

		$this->go_to( home_url( '/gated/' . $uuid . '/' ) );

		$this->assertFalse( is_404() );
		$this->assertSame( $post_id, (int) get_queried_object_id() );
	}

	/** @testdox An opened group lists the gated posts it holds, rather than counting them and omitting them. */
	public function test_an_opened_group_lists_its_gated_posts(): void {
		[ $post_id ] = $this->make_gated_post();

		$group_uuid = $this->granted_group_holding( $post_id );

		// Opened by the holder: to anyone else the marker keeps it out of every query.
		wp_set_current_user( $this->user_id );

		$this->assertContains( $post_id, ( new Access_Taxonomy() )->contents( $group_uuid ) );
	}

	/** @testdox A directly granted gated post appears in My Access. */
	public function test_a_gated_post_appears_in_my_access(): void {
		[ $post_id ] = $this->make_gated_post();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		wp_set_current_user( $this->user_id );

		$data = apply_filters(
			'gatedmedia_my_access_data',
			array(
				'groups' => array(),
				'posts'  => array(),
				'files'  => array(),
			)
		);

		$this->assertCount( 1, $data['posts'] );
	}

	/** @testdox The status is registered, and is the one that belongs to content rather than to a record. */
	public function test_the_status_is_registered(): void {
		$status = get_post_status_object( Post_Types::STATUS_GATED );

		$this->assertNotNull( $status );
		$this->assertTrue( $status->exclude_from_search );
	}

	/** @testdox Setting the status applies the marker term, so it is restricted by the same thing everything else is. */
	public function test_the_status_applies_the_marker(): void {
		[ $post_id ] = $this->make_gated_post();

		// The marker is hidden from every term list, so a reader that needs it opts in.
		$slugs = wp_get_object_terms(
			$post_id,
			Access_Taxonomy::TAXONOMY,
			array(
				'fields'                    => 'slugs',
				Restriction::INCLUDE_MARKER => true,
			)
		);

		$this->assertContains( Restriction::MARKER_SLUG, $slugs );
	}

	/** @testdox Setting the status mints a UUID, and the permalink becomes that UUID URL. */
	public function test_the_permalink_is_the_uuid_url(): void {
		[ $post_id, $uuid ] = $this->make_gated_post();

		$this->assertNotSame( '', $uuid );
		$this->assertSame( home_url( '/gated/' . $uuid . '/' ), get_permalink( $post_id ) );
	}

	/** @testdox A holder reaches it at its UUID. */
	public function test_a_holder_reaches_it_by_uuid(): void {
		[ $post_id, $uuid ] = $this->make_gated_post();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->go_to( home_url( '/gated/' . $uuid . '/' ) );

		$this->assertFalse( is_404() );
		$this->assertSame( $post_id, (int) get_queried_object_id() );
	}

	/**
	 * @testdox The slug is a 404 for a holder too, because the UUID is the only road in.
	 *
	 * An ordinary restricted post keeps its permalink and is merely refused. This one closes the slug to everybody.
	 */
	public function test_the_slug_is_a_404_even_for_a_holder(): void {
		[ $post_id ] = $this->make_gated_post();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$post = get_post( $post_id );

		$this->go_to( home_url( '/' . $post->post_name . '/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox A bare ?p= is a 404 as well, so the slug is not the only door that was shut. */
	public function test_the_id_query_is_a_404(): void {
		[ $post_id ] = $this->make_gated_post();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->go_to( home_url( '/?p=' . $post_id ) );

		$this->assertTrue( is_404() );
	}

	/**
	 * @testdox A gated child page's slug is a 404 too, path and all.
	 *
	 * A hierarchical page arrives as pagename=parent/child, and a post_name lookup can never match a two-segment path, so the refusal was skipped entirely.
	 */
	public function test_a_gated_child_page_slug_is_a_404(): void {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'handbook',
			)
		);

		$child_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_name'   => 'salaries',
				'post_parent' => $parent_id,
			)
		);

		wp_update_post(
			array(
				'ID'          => $child_id,
				'post_status' => Post_Types::STATUS_GATED,
			)
		);

		$this->writer->grant( $this->user_id, 'post', (string) $child_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->go_to( home_url( '/handbook/salaries/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox A UUID nobody holds still resolves to the post, and the boundary is what refuses it. */
	public function test_the_route_resolves_and_the_boundary_refuses(): void {
		[ $post_id, $uuid ] = $this->make_gated_post();

		wp_set_current_user( $this->user_id );

		// Not granted: the resolver is the thing that says no, and it does.
		$this->assertFalse( $this->resolver()->can_see( $this->user_id, 'post', (string) $post_id ) );

		$this->go_to( home_url( '/gated/' . $uuid . '/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox A UUID that places no post is a 404 rather than the blog listing. */
	public function test_an_unknown_uuid_is_a_404(): void {
		$this->go_to( home_url( '/gated/11111111-2222-3333-4444-555555555555/' ) );

		$this->assertTrue( is_404() );
	}

	/** @testdox An ordinary published post is untouched, keeping its slug and its permalink. */
	public function test_an_ordinary_post_is_left_alone(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$post    = get_post( $post_id );

		$this->assertSame( home_url( '/' . $post->post_name . '/' ), get_permalink( $post_id ) );

		$this->go_to( home_url( '/' . $post->post_name . '/' ) );

		$this->assertFalse( is_404() );
		$this->assertSame( $post_id, (int) get_queried_object_id() );
	}

	/** @testdox Renaming the segment moves the URL with it. */
	public function test_the_segment_filter_moves_the_url(): void {
		add_filter( 'gatedmedia_gated_path', static fn(): string => 'members-only' );

		[ $post_id, $uuid ] = $this->make_gated_post();

		$this->assertSame( home_url( '/members-only/' . $uuid . '/' ), get_permalink( $post_id ) );
	}

	/**
	 * @testdox The block editor can set the status: a REST save carries it through and the marker follows.
	 *
	 * The plugin ships its own status control, but the saving is core's over REST, and this proves that route: a status failing either the schema enum or `handle_status_param()` would silently become a draft.
	 */
	public function test_a_rest_save_sets_the_status(): void {
		$editor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $editor );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		// The REST server is not stood up for us; routes 404 without this.
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'status' => Post_Types::STATUS_GATED ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( Post_Types::STATUS_GATED, get_post_status( $post_id ) );

		$slugs = wp_get_object_terms(
			$post_id,
			Access_Taxonomy::TAXONOMY,
			array(
				'fields'                    => 'slugs',
				Restriction::INCLUDE_MARKER => true,
			)
		);

		$this->assertContains( Restriction::MARKER_SLUG, $slugs );
	}

	/**
	 * @testdox The admin list names a gated post, so a row does not read as an ordinary published one.
	 *
	 * Called directly, because the filter attaches only under `is_admin()`, decided at boot long before this runs.
	 */
	public function test_the_admin_list_names_it(): void {
		[ $post_id ] = $this->make_gated_post();

		$states = ( new Gated_Post_State() )->add_state( array(), get_post( $post_id ) );

		$this->assertContains( 'Gated access', $states );
	}

	/** @testdox An ordinary post gets no such label. */
	public function test_an_ordinary_post_gets_no_label(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$states = ( new Gated_Post_State() )->add_state( array(), get_post( $post_id ) );

		$this->assertNotContains( 'Gated access', $states );
	}

	/** @testdox A segment filter returning rubbish falls back rather than pointing links at another host. */
	public function test_a_bad_segment_falls_back(): void {
		add_filter( 'gatedmedia_gated_path', static fn(): array => array( 'nope' ) );

		$this->assertSame( 'gated', Gated_Post_Route::segment() );
	}
}
