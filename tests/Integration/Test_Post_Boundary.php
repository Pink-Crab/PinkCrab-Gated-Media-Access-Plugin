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
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Post_Boundary;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Restriction;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * A restricted post is a hard 404 and absent from every listing for anyone without access, visible everywhere for a holder, and unrestricted content is untouched.
 *
 * Listing exclusion runs through the booted plugin's own pre_get_posts hook. The refusal is on `wp`, so go_to() fires it; the direct calls below assert the method on its own, and calling it twice changes nothing.
 *
 * @group integration
 */
class Test_Post_Boundary extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The framework unregisters every meta key after each test, so re-register.
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

	/**
	 * @testdox A single post feed serves nothing for a restricted post.
	 *
	 * The 404 flags are not a refusal on their own: set_404() keeps is_feed and drops is_comment_feed, so do_feed() loads the posts feed, and template-loader.php runs that before it ever looks at is_404.
	 */
	public function test_feed_serves_nothing_for_a_restricted_post(): void {
		$post_id = $this->make_restricted_post(
			array(
				'post_title'   => 'Xyzzy secret handbook',
				'post_content' => 'The secret is in the second drawer.',
			)
		);

		wp_set_current_user( 0 );

		$this->go_to( '/?p=' . $post_id . '&feed=rss2' );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_404() );
		$this->assertTrue( is_feed() );

		ob_start();
		do_feed();
		$feed = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'The secret is in the second drawer.', $feed );
		$this->assertStringNotContainsString( 'Xyzzy secret handbook', $feed );
	}

	/**
	 * @testdox A holder asking for the same feed still gets their post.
	 *
	 * Emptying the loop must not reach a holder. Their request is never refused, so is_comment_feed survives and the comments feed answers, which names the post in its channel title.
	 */
	public function test_feed_still_serves_a_holder(): void {
		$post_id = $this->make_restricted_post( array( 'post_title' => 'Xyzzy secret handbook' ) );

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		wp_set_current_user( $this->user_id );

		$this->go_to( '/?p=' . $post_id . '&feed=rss2' );
		$this->boundary()->refuse_singular();

		global $wp_query;

		ob_start();
		do_feed();
		$feed = (string) ob_get_clean();

		$this->assertFalse( is_404() );
		$this->assertSame( 1, $wp_query->post_count );
		$this->assertStringContainsString( 'Comments on: Xyzzy secret handbook', $feed );
	}

	/**
	 * @testdox A single post feed serves nothing for a restricted post with no template layer.
	 *
	 * template-loader.php fires template_redirect only when wp_using_themes(), but serves feeds either way, so a refusal hung on that hook never runs where WordPress is loaded with WP_USE_THEMES off. go_to() is that request: WP::main() and no template loader. `withoutcomments` is what keeps it a posts feed, the one that prints the body.
	 */
	public function test_feed_serves_nothing_without_the_template_layer(): void {
		$post_id = $this->make_restricted_post(
			array(
				'post_title'   => 'Xyzzy secret handbook',
				'post_content' => 'The secret is in the second drawer.',
			)
		);

		wp_set_current_user( 0 );

		$this->go_to( '/?p=' . $post_id . '&feed=rss2&withoutcomments=1' );

		ob_start();
		do_feed();
		$feed = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'The secret is in the second drawer.', $feed );
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

	/**
	 * @testdox The oEmbed endpoint answers for a blocked post as it does for one that does not exist.
	 *
	 * Core's oEmbed route builds its own response from the post and never runs `rest_prepare_{$type}`, so the REST refusal never saw it. It answered 200 with the title and author of a post that 404s on every other surface, which made it an existence oracle too.
	 */
	public function test_oembed_refuses_a_blocked_post(): void {
		$restricted_id = $this->make_restricted_post( array( 'post_title' => 'Xyzzy secret handbook' ) );

		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$request->set_param( 'url', get_permalink( $restricted_id ) );

		$response = rest_do_request( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertStringNotContainsString( 'Xyzzy secret handbook', (string) wp_json_encode( $response->get_data() ) );
	}

	/** @testdox A holder's oEmbed request is answered as it always was. */
	public function test_oembed_serves_a_holder(): void {
		$restricted_id = $this->make_restricted_post( array( 'post_title' => 'Xyzzy secret handbook' ) );

		$holder_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->writer->grant( $holder_id, 'post', (string) $restricted_id, null, 'admin' );

		wp_set_current_user( $holder_id );

		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$request->set_param( 'url', get_permalink( $restricted_id ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
	}

	/** @testdox An unrestricted post's oEmbed response is untouched. */
	public function test_oembed_leaves_an_unrestricted_post_alone(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Ordinary post' ) );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
		$request->set_param( 'url', get_permalink( $post_id ) );

		$this->assertSame( 200, rest_do_request( $request )->get_status() );
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
	 * A published post carrying a group term, restricted through the real wiring rather than by hand.
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
	 * @testdox Refusing a singular cancels core's canonical redirect.
	 *
	 * Setting the 404 is not enough: redirect_canonical reads `p` off it and 301s to the pretty slug, telling the guesser the post is there and what it is called.
	 */
	public function test_refusal_cancels_the_canonical_redirect(): void {
		$post_id = $this->make_restricted_post();

		wp_set_current_user( $this->user_id );

		$this->go_to( '/?p=' . $post_id );
		$this->boundary()->refuse_singular();

		$this->assertTrue( is_404() );
		$this->assertFalse( apply_filters( 'redirect_canonical', home_url( '/a-slug/' ), home_url( '/?p=' . $post_id ) ) );
	}

	/**
	 * @testdox An admin-ajax listing still excludes restricted posts from a visitor.
	 *
	 * is_admin() is true for admin-ajax.php, `wp_ajax_nopriv_*` handlers included.
	 */
	public function test_admin_ajax_still_excludes_for_a_visitor(): void {
		$post_id = $this->make_restricted_post();

		wp_set_current_user( $this->user_id );
		set_current_screen( 'edit.php' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$ids = $this->queried_ids( array( 'post_type' => 'post' ) );

		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );

		$this->assertNotContains( $post_id, $ids );
	}

	/**
	 * @testdox An admin-ajax listing for someone who can grant access is untouched.
	 *
	 * Picker_Search is exactly this: an admin-ajax search for restricted items.
	 */
	public function test_admin_ajax_is_untouched_for_a_granter(): void {
		$post_id = $this->make_restricted_post();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit.php' );
		add_filter( 'wp_doing_ajax', '__return_true' );

		$ids = $this->queried_ids( array( 'post_type' => 'post' ) );

		remove_filter( 'wp_doing_ajax', '__return_true' );
		set_current_screen( 'front' );

		$this->assertContains( $post_id, $ids );
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
	 * A fresh boundary, called directly, because template_redirect drags canonical redirects along.
	 */
	private function boundary(): Post_Boundary {
		return new Post_Boundary( new Resolver( new Access_Taxonomy() ), new Restriction() );
	}
}
