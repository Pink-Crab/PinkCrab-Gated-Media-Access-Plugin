<?php
/**
 * The resolver.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Both of the brief's questions, answered from the records alone: direct
 * grants, live group expansion, expiry with no sweep, revocation, the final
 * filter, and the no-second-query guarantee.
 *
 * @group integration
 */
class Test_Resolver extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Grant_Validator( new Access_Taxonomy() ) );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_user_can_access' );

		parent::tear_down();
	}

	/**
	 * A resolver with nothing memoised — group membership changes mid-test
	 * need a fresh answer, exactly as a new request would get one.
	 */
	private function resolver(): Resolver {
		return new Resolver( new Access_Taxonomy() );
	}

	/**
	 * A group with a UUID, containing the given objects.
	 *
	 * @param array<int, int> $object_ids What the group holds.
	 * @return array{0: int, 1: string} Term ID and UUID.
	 */
	private function group_with( array $object_ids ): array {
		$term = wp_insert_term( 'Resolver Group ' . wp_rand(), Access_Taxonomy::TAXONOMY );

		foreach ( $object_ids as $object_id ) {
			wp_set_object_terms( $object_id, array( $term['term_id'] ), Access_Taxonomy::TAXONOMY, true );
		}

		return array( $term['term_id'], ( new Access_Taxonomy() )->uuid_for( $term['term_id'] ) );
	}

	/** @testdox Direct file, post and group grants all answer true; the unheld answer false. */
	public function test_direct_grants_resolve(): void {
		$post_id       = self::factory()->post->create();
		$other_post    = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create();
		list( , $uuid ) = $this->group_with( array() );

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin' );
		$this->writer->grant( $this->user_id, 'file', (string) $attachment_id, null, 'admin' );
		$this->writer->grant( $this->user_id, 'group', $uuid, 30, 'admin' );

		$resolver = $this->resolver();

		$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $post_id ) );
		$this->assertTrue( $resolver->can_see( $this->user_id, 'file', (string) $attachment_id ) );
		$this->assertTrue( $resolver->can_see( $this->user_id, 'group', $uuid ) );
		$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $other_post ) );
	}

	/** @testdox Group access is live: contents moved in appear, contents moved out disappear. */
	public function test_group_access_follows_the_contents(): void {
		$in_from_the_start = self::factory()->post->create();
		$added_later       = self::factory()->post->create();
		$file_inside       = self::factory()->attachment->create();

		list( $term_id, $uuid ) = $this->group_with( array( $in_from_the_start, $file_inside ) );

		$this->writer->grant( $this->user_id, 'group', $uuid, null, 'admin' );

		$resolver = $this->resolver();

		$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $in_from_the_start ) );
		$this->assertTrue( $resolver->can_see( $this->user_id, 'file', (string) $file_inside ) );
		$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $added_later ) );

		wp_set_object_terms( $added_later, array( $term_id ), Access_Taxonomy::TAXONOMY, true );
		wp_remove_object_terms( $in_from_the_start, array( $term_id ), Access_Taxonomy::TAXONOMY );

		$resolver = $this->resolver();

		$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $added_later ) );
		$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $in_from_the_start ) );
	}

	/** @testdox An expired record answers false with no sweep having run — the status is still active. */
	public function test_expiry_needs_no_sweep(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin' );

		update_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$this->assertFalse( $this->resolver()->can_see( $this->user_id, 'post', (string) $post_id ) );
	}

	/** @testdox A revoked record answers false. */
	public function test_revoked_answers_false(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		$this->writer->revoke( $access_id );

		$this->assertFalse( $this->resolver()->can_see( $this->user_id, 'post', (string) $post_id ) );
	}

	/** @testdox The gatedmedia_user_can_access filter is the last word, in both directions. */
	public function test_the_filter_overrides_both_ways(): void {
		$held     = self::factory()->post->create();
		$not_held = self::factory()->post->create();

		$this->writer->grant( $this->user_id, 'post', (string) $held, null, 'admin' );

		add_filter( 'gatedmedia_user_can_access', '__return_true' );
		$this->assertTrue( $this->resolver()->can_see( $this->user_id, 'post', (string) $not_held ) );

		remove_all_filters( 'gatedmedia_user_can_access' );

		add_filter( 'gatedmedia_user_can_access', '__return_false' );
		$this->assertFalse( $this->resolver()->can_see( $this->user_id, 'post', (string) $held ) );
	}

	/** @testdox The second ask costs zero queries — the allowed items are built once per user. */
	public function test_the_second_ask_is_free(): void {
		$post_id = self::factory()->post->create();
		$file_id = self::factory()->attachment->create();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin' );

		$resolver = $this->resolver();
		$resolver->can_see( $this->user_id, 'post', (string) $post_id );

		$queries_before = get_num_queries();

		$resolver->can_see( $this->user_id, 'file', (string) $file_id );
		$resolver->can_see( $this->user_id, 'post', (string) $post_id );
		$resolver->allowed_for( $this->user_id );

		$this->assertSame( $queries_before, get_num_queries() );
	}

	/** @testdox Signed out holds nothing. */
	public function test_signed_out_holds_nothing(): void {
		$post_id = self::factory()->post->create();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		$this->assertFalse( $this->resolver()->can_see( 0, 'post', (string) $post_id ) );
	}
}
