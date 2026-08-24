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
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * The status is a trigger, not a second access model: setting it applies the
 * marker term, so everything restriction already means comes with it.
 *
 * What is genuinely new is the addressing, and that is what most of these
 * assert — the UUID is the only road in, and the slug is closed to **everyone**
 * including holders, which is what separates this from an ordinary restricted
 * post.
 *
 * The refusal is a 404 and never a redirect: bouncing a slug request to the
 * real UUID would make the slug an oracle for discovering it.
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

	/** @testdox The status is registered, and is the one that belongs to content rather than to a record. */
	public function test_the_status_is_registered(): void {
		$status = get_post_status_object( Post_Types::STATUS_GATED );

		$this->assertNotNull( $status );
		$this->assertTrue( $status->exclude_from_search );
	}

	/** @testdox Setting the status applies the marker term, so it is restricted by the same thing everything else is. */
	public function test_the_status_applies_the_marker(): void {
		[ $post_id ] = $this->make_gated_post();

		// The marker is hidden from every term list via list_terms_exclusions;
		// a reader that genuinely needs it opts in (Restriction's docblock).
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
	 * @testdox The slug is a 404 for a holder too — the UUID is the only road in.
	 *
	 * The assertion that matters most here. An ordinary restricted post keeps
	 * its permalink and is merely refused to people without access; this one
	 * closes the slug to everybody.
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

	/** @testdox An ordinary published post is untouched — it keeps its slug and its permalink. */
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

	/** @testdox A segment filter returning rubbish falls back rather than pointing links at another host. */
	public function test_a_bad_segment_falls_back(): void {
		add_filter( 'gatedmedia_gated_path', static fn(): array => array( 'nope' ) );

		$this->assertSame( 'gated', Gated_Post_Route::segment() );
	}
}
