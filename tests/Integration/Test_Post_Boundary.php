<?php
/**
 * The post boundary.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Query;
use WP_REST_Request;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Access\Post_Boundary;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * A restricted post is a hard 404 and absent from every listing surface for
 * anyone without access, visible everywhere for a holder, and unrestricted
 * content is untouched.
 *
 * Listing exclusion runs through the booted plugin's own pre_get_posts hook;
 * the singular refusal is called directly (template_redirect in tests drags
 * canonical redirects along with it).
 *
 * @group integration
 */
class Test_Post_Boundary extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Grant_Validator( new Access_Taxonomy() ) );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so the boot-time registration is
		// gone by the time any test here runs.
		$this->writer->register_meta();
	}

	/** @testdox A holder sees the restricted post; it is not a 404 for them. */
	public function test_holder_sees_the_post(): void {
		$post_id = $this->make_restricted_post();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->go_to( '/?p=' . $post_id );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_singular() );
		$this->assertFalse( is_404() );
	}

	/** @testdox A signed-in non-holder gets a hard 404. */
	public function test_non_holder_gets_a_404(): void {
		$post_id = $this->make_restricted_post();

		wp_set_current_user( $this->user_id );

		$this->go_to( '/?p=' . $post_id );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_404() );
	}

	/** @testdox Signed out, a restricted post is a hard 404. */
	public function test_signed_out_gets_a_404(): void {
		$post_id = $this->make_restricted_post();

		wp_set_current_user( 0 );

		$this->go_to( '/?p=' . $post_id );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_404() );
	}

	/** @testdox An unrestricted post is untouched by the singular refusal. */
	public function test_unrestricted_post_untouched(): void {
		$post_id = self::factory()->post->create();

		wp_set_current_user( 0 );

		$this->go_to( '/?p=' . $post_id );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_singular() );
		$this->assertFalse( is_404() );
	}

	/** @testdox Listings hide a restricted post from a non-holder and keep it for a holder, with unrestricted posts untouched. */
	public function test_listing_exclusion(): void {
		$restricted_id   = $this->make_restricted_post();
		$unrestricted_id = self::factory()->post->create();

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->writer->grant( $holder_id, 'post', (string) $restricted_id, null, 'admin' );

		wp_set_current_user( $this->user_id );
		$seen = $this->queried_ids( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$this->assertNotContains( $restricted_id, $seen );
		$this->assertContains( $unrestricted_id, $seen );

		wp_set_current_user( $holder_id );
		$seen = $this->queried_ids( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$this->assertContains( $restricted_id, $seen );
		$this->assertContains( $unrestricted_id, $seen );
	}

	/** @testdox Search hides a restricted post from a non-holder and keeps it for a holder. */
	public function test_search_exclusion(): void {
		$restricted_id = $this->make_restricted_post( array( 'post_title' => 'Xyzzy secret handbook' ) );

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->writer->grant( $holder_id, 'post', (string) $restricted_id, null, 'admin' );

		wp_set_current_user( 0 );
		$this->assertNotContains( $restricted_id, $this->queried_ids( array( 's' => 'Xyzzy' ) ) );

		wp_set_current_user( $holder_id );
		$this->assertContains( $restricted_id, $this->queried_ids( array( 's' => 'Xyzzy' ) ) );
	}

	/** @testdox REST answers a non-holder with the same 404 as a post that does not exist, and serves a holder. */
	public function test_rest_single_item(): void {
		$restricted_id = $this->make_restricted_post();

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->writer->grant( $holder_id, 'post', (string) $restricted_id, null, 'admin' );

		wp_set_current_user( $this->user_id );
		$refused = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $restricted_id ) );

		$this->assertSame( 404, $refused->get_status() );
		$this->assertSame( 'rest_post_invalid_id', $refused->as_error()->get_error_code() );

		wp_set_current_user( $holder_id );
		$served = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $restricted_id ) );

		$this->assertSame( 200, $served->get_status() );
	}

	/** @testdox The REST collection omits a restricted post for a non-holder. */
	public function test_rest_collection(): void {
		$restricted_id = $this->make_restricted_post();

		wp_set_current_user( $this->user_id );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );

		$this->assertNotContains( $restricted_id, wp_list_pluck( (array) $response->get_data(), 'id' ) );
	}

	/** @testdox The posts sitemap omits a restricted post for the signed out and lists it for a holder. */
	public function test_sitemap_exclusion(): void {
		$restricted_id = $this->make_restricted_post();

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->writer->grant( $holder_id, 'post', (string) $restricted_id, null, 'admin' );

		wp_set_current_user( 0 );
		$this->assertNotContains( get_permalink( $restricted_id ), $this->sitemap_locations() );

		wp_set_current_user( $holder_id );
		$this->assertContains( get_permalink( $restricted_id ), $this->sitemap_locations() );
	}

	/**
	 * A published post carrying a group term — restricted through the round 1
	 * wiring, not by hand.
	 *
	 * @param array<string, mixed> $args Extra post fields.
	 */
	private function make_restricted_post( array $args = array() ): int {
		$post_id = self::factory()->post->create( $args );
		$group   = wp_insert_term( 'Gold', Access_Taxonomy::TAXONOMY );
		$term_id = is_wp_error( $group ) ? (int) get_term_by( 'slug', 'gold', Access_Taxonomy::TAXONOMY )->term_id : (int) $group['term_id'];

		wp_set_object_terms( $post_id, array( $term_id ), Access_Taxonomy::TAXONOMY );

		return $post_id;
	}

	/**
	 * IDs a front-of-site query returns, through the booted pre_get_posts hook.
	 *
	 * @param array<string, mixed> $args The query.
	 * @return array<int>
	 */
	private function queried_ids( array $args ): array {
		$query = new WP_Query( array_merge( $args, array( 'fields' => 'ids', 'posts_per_page' => -1 ) ) );

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Every URL in page one of the posts sitemap.
	 *
	 * @return array<string>
	 */
	private function sitemap_locations(): array {
		$providers = wp_get_sitemap_providers();

		return array_column( $providers['posts']->get_url_list( 1, 'post' ), 'loc' );
	}

	/**
	 * A fresh boundary — called directly, where firing template_redirect in
	 * a test would drag canonical redirects along with it.
	 */
	private function boundary(): Post_Boundary {
		return new Post_Boundary( new Resolver( new Access_Taxonomy() ), new Restriction() );
	}
}
