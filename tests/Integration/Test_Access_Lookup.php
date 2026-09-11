<?php
/**
 * Finding access records.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Every lookup was a meta query naming two to four keys, and WordPress indexes `meta_key` but not `meta_value`, so each one was a join the database could not use an index for.
 *
 * A record now carries the two pairs joined on write, `source|reference` and `item_type|item_id`, and each lookup matches one of those instead. The parts stay as their own meta, because the record is read by them everywhere else.
 *
 * @group integration
 */
class Test_Access_Lookup extends WP_UnitTestCase {

	private Access_Writer $writer;

	private Access_Lookup $lookup;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->lookup  = new Access_Lookup();
		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), $this->lookup );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The framework unregisters every meta key after each test, so re-register.
		$this->writer->register_meta();
	}

	/** @testdox A record carries both lookup keys, each holding its pair joined. */
	public function test_a_record_carries_both_lookup_keys(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'stripe', 'ref-123' );

		$this->assertIsInt( $access_id );
		$this->assertSame( 'stripe|ref-123', get_post_meta( $access_id, Access_Writer::META_REF_KEY, true ) );
		$this->assertSame( 'post|' . $post_id, get_post_meta( $access_id, Access_Writer::META_ITEM_KEY, true ) );
	}

	/**
	 * @testdox Every lookup finds the record by its keys alone.
	 *
	 * The four separate meta rows are deleted first, so anything still querying them finds nothing. That is the whole point: one indexed key per lookup instead of two to four joins.
	 */
	public function test_the_lookups_use_the_keys_alone(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'stripe', 'ref-123' );

		foreach ( array( Access_Writer::META_SOURCE, Access_Writer::META_REFERENCE, Access_Writer::META_ITEM_TYPE, Access_Writer::META_ITEM_ID ) as $meta ) {
			delete_post_meta( (int) $access_id, $meta );
		}

		$this->assertSame( $access_id, $this->lookup->find_by_reference( 'stripe', 'ref-123' ) );
		$this->assertSame( $access_id, $this->lookup->find_by_reference( 'stripe', 'ref-123', 'post', (string) $post_id ) );
		$this->assertContains( $access_id, $this->lookup->records_for_reference( 'stripe', 'ref-123' ) );
		$this->assertContains( $this->user_id, $this->lookup->holders_of( 'post', (string) $post_id ) );
		$this->assertContains( $access_id, $this->lookup->records_for_item( $this->user_id, 'post', (string) $post_id ) );
	}

	/** @testdox A different reference is not mistaken for this one. */
	public function test_another_reference_is_not_matched(): void {
		$post_id = self::factory()->post->create();

		$this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'stripe', 'ref-123' );

		$this->assertNull( $this->lookup->find_by_reference( 'stripe', 'ref-124' ) );
		$this->assertNull( $this->lookup->find_by_reference( 'admin', 'ref-123' ) );
	}

	/**
	 * @testdox A reference carrying the separator cannot be read as another pair.
	 *
	 * `stripe` + `a|b` and `stripe|a` + `b` join to the same string, so the separator has to be one a reference cannot contain, or one payment's refund reaches another's records.
	 */
	public function test_a_reference_holding_the_separator_does_not_collide(): void {
		$post_id = self::factory()->post->create();

		$first  = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'stripe', 'a|b' );
		$second = $this->writer->grant( $this->user_id, 'file', (string) self::factory()->attachment->create_object( 'x.txt', 0, array( 'post_mime_type' => 'text/plain' ) ), null, 'stripe|a', 'b' );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertNotSame( $first, $second );
		$this->assertSame( $first, $this->lookup->find_by_reference( 'stripe', 'a|b' ) );
		$this->assertSame( $second, $this->lookup->find_by_reference( 'stripe|a', 'b' ) );
	}

	/** @testdox Stacking onto a live record still finds it, which is what stops a second payment writing a duplicate. */
	public function test_stacking_still_finds_the_live_record(): void {
		$post_id = self::factory()->post->create();

		$first  = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'stripe', 'ref-1' );
		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'stripe', 'ref-2' );

		$this->assertSame( $first, $second, 'the second payment stacked onto the first record' );
	}
}
