<?php
/**
 * The Files block's available and past lists.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Account\Downloadable_Files;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\Access_Row;

/**
 * Past access is history, and history must not keep reading the present. A
 * lapsed group record used to be expanded through the group's membership as
 * it stands today, so files added after the access ran out were listed to
 * someone the resolver would refuse (§28).
 *
 * @group integration
 */
class Test_Downloadable_Files extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->register_meta();
	}

	/** @testdox A file granted directly and then lapsed is still listed as past. */
	public function test_a_lapsed_file_is_past(): void {
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'Last year handbook' ) );

		$access_id = $this->writer->grant( $this->user_id, 'file', (string) $file_id, 30, 'admin' );

		$this->writer->set_expiry( (int) $access_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		wp_set_current_user( $this->user_id );

		$this->assertContains( 'Last year handbook', $this->past_titles() );
	}

	/** @testdox A lapsed group does not list what the group holds now. */
	public function test_a_lapsed_group_does_not_leak_current_contents(): void {
		$group = wp_insert_term( 'Reports', Access_Taxonomy::TAXONOMY );
		$term  = get_term( (int) $group['term_id'], Access_Taxonomy::TAXONOMY );
		$uuid  = \PinkCrab\Gated_Access\Support\Uuid::ensure( 'term', $term->term_id );

		$added_later = self::factory()->attachment->create( array( 'post_title' => 'Q4 board pack' ) );
		wp_set_object_terms( $added_later, array( $term->term_id ), Access_Taxonomy::TAXONOMY );

		$access_id = $this->writer->grant( $this->user_id, 'group', $uuid, 30, 'admin' );

		$this->writer->set_expiry( (int) $access_id, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		wp_set_current_user( $this->user_id );

		$this->assertNotContains( 'Q4 board pack', $this->past_titles() );
	}

	/**
	 * The titles the past list carries.
	 *
	 * @return array<int, string>
	 */
	private function past_titles(): array {
		$files = new Downloadable_Files(
			new Resolver( new Access_Taxonomy() ),
			new Access_Row( new Access_Taxonomy() )
		);

		$data = $files->files( array( 'available' => array(), 'downloading' => array(), 'past' => array() ) );

		return array_map( static fn ( array $row ): string => (string) ( $row['title'] ?? '' ), $data['past'] );
	}
}
