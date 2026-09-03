<?php
/**
 * What happens to access when the person holding it is deleted.
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
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * Access is personal and is not inherited. Deleting a user takes their access
 * with them, whether or not their content is reassigned to somebody else.
 *
 * @group integration
 */
class Test_User_Deletion extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$this->writer  = new Access_Writer( new Access_Validator( new Access_Taxonomy() ), new Access_Lookup() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Every access record on the site, whoever holds it.
	 *
	 * @return array<int, int>
	 */
	private function all_access_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $ids );
	}

	/** @testdox Deleting a user and reassigning their content hands no access to the person it is reassigned to. */
	public function test_reassignment_does_not_hand_on_access(): void {
		$file_id = self::factory()->attachment->create();
		$heir    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->grant( $this->user_id, 'file', (string) $file_id, 30, 'admin' );

		wp_delete_user( $this->user_id, $heir );

		$this->assertSame( array(), $this->all_access_ids(), 'the records are gone, not inherited' );

		wp_set_current_user( $heir );
		$this->assertSame( array(), get_posts( array( 'post_type' => Post_Types::ACCESS, 'author' => $heir, 'fields' => 'ids', 'post_status' => 'any' ) ) );
	}

	/** @testdox Deleting a user without reassigning takes their access records with them. */
	public function test_deleting_a_user_removes_their_access(): void {
		$file_id = self::factory()->attachment->create();

		$this->writer->grant( $this->user_id, 'file', (string) $file_id, 30, 'admin' );
		$this->writer->grant( $this->user_id, 'post', (string) self::factory()->post->create(), null, 'admin' );

		wp_delete_user( $this->user_id );

		$this->assertSame( array(), $this->all_access_ids() );
	}

	/** @testdox Another person's access is untouched when a user is deleted. */
	public function test_other_holders_are_left_alone(): void {
		$file_id = self::factory()->attachment->create();
		$other   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->writer->grant( $this->user_id, 'file', (string) $file_id, 30, 'admin' );
		$kept = $this->writer->grant( $other, 'file', (string) $file_id, 30, 'admin' );

		wp_delete_user( $this->user_id );

		$this->assertSame( array( $kept ), $this->all_access_ids() );
	}
}
