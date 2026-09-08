<?php
/**
 * What an item is called.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Item_Label;

/**
 * Items are stored as `type:identifier`, and a product's contents and an order's snapshot both have to turn those back into words.
 *
 * **The fallbacks are the point.** An order outlives the thing it bought, so it still has to account for a deleted post or a removed group, and "A file" and "An item" were wording nobody had ever seen.
 *
 * @group integration
 */
class Test_Item_Label extends WP_UnitTestCase {

	private Item_Label $labels;

	private Access_Taxonomy $taxonomy;

	public function set_up(): void {
		parent::set_up();

		$this->taxonomy = new Access_Taxonomy();
		$this->labels   = new Item_Label( $this->taxonomy );
	}

	/** @testdox A post is named by its title. */
	public function test_post_title(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The briefing' ) );

		$this->assertSame( 'The briefing', $this->labels->text( 'post', (string) $post_id ) );
	}

	/** @testdox A file is named by its title. */
	public function test_file_title(): void {
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'The spreadsheet' ) );

		$this->assertSame( 'The spreadsheet', $this->labels->text( 'file', (string) $file_id ) );
	}

	/** @testdox A group is named by its term. */
	public function test_group_name(): void {
		$term = wp_insert_term( 'Analyst briefings', Access_Taxonomy::TAXONOMY );
		$uuid = $this->taxonomy->uuid_for( $term['term_id'] );

		$this->assertSame( 'Analyst briefings', $this->labels->text( 'group', $uuid ) );
	}

	/** @testdox A deleted post still accounts for itself rather than vanishing from the list. */
	public function test_deleted_post_keeps_a_line(): void {
		$this->assertSame( 'An item', $this->labels->text( 'post', '999999' ) );
	}

	/** @testdox A deleted file says it was a file. */
	public function test_deleted_file_keeps_its_kind(): void {
		$this->assertSame( 'A file', $this->labels->text( 'file', '999999' ) );
	}

	/** @testdox A group that no longer exists says it was a group. */
	public function test_missing_group_keeps_its_kind(): void {
		$this->assertSame( 'A group', $this->labels->text( 'group', 'not-a-real-uuid' ) );
	}

	/** @testdox Nothing at all is named nothing, so the caller can skip the row. */
	public function test_empty_input_is_empty(): void {
		$this->assertSame( '', $this->labels->text( '', '12' ) );
		$this->assertSame( '', $this->labels->text( 'post', '' ) );
	}

	/** @testdox Each kind has its own icon, and an unknown kind draws none. */
	public function test_icons(): void {
		$this->assertSame( 'i-groups', $this->labels->icon( 'group' ) );
		$this->assertSame( 'i-article', $this->labels->icon( 'post' ) );
		$this->assertSame( 'i-doc', $this->labels->icon( 'file' ) );
		$this->assertSame( '', $this->labels->icon( 'something-else' ) );
	}

	/** @testdox A stored entry splits into its kind and its identifier. */
	public function test_split(): void {
		$this->assertSame( array( 'post', '42' ), Item_Label::split( 'post:42' ) );
		$this->assertSame( array( 'group', 'abc-def' ), Item_Label::split( 'group:abc-def' ) );
	}

	/** @testdox A malformed entry splits into something the caller can reject. */
	public function test_split_of_rubbish(): void {
		$this->assertSame( array( 'post', '' ), Item_Label::split( 'post' ) );
		$this->assertSame( array( '', '' ), Item_Label::split( '' ) );

		// Only the first colon separates, so an identifier carrying one survives intact.
		$this->assertSame( array( 'file', '12:34' ), Item_Label::split( 'file:12:34' ) );
	}
}
