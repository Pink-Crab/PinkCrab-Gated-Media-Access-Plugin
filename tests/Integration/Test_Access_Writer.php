<?php
/**
 * The one writer of access records.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The record is written whole, retries write nothing, the stacking rules hold,
 * and nothing invalid gets through.
 *
 * @group integration
 */
class Test_Access_Writer extends WP_UnitTestCase {

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer  = new Access_Writer( new Access_Taxonomy() );
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so the boot-time registration is
		// gone by the time any test here runs.
		$this->writer->register_meta();
	}

	/** @testdox A grant writes the record whole: columns, meta, expiry from the duration. */
	public function test_a_grant_writes_the_record_whole(): void {
		$post_id = self::factory()->post->create();

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin', '', array(), 1 );

		$this->assertIsInt( $access_id );

		$record = get_post( $access_id );

		$this->assertSame( Post_Types::ACCESS, $record->post_type );
		$this->assertSame( Post_Types::STATUS_ACTIVE, $record->post_status );
		$this->assertSame( $this->user_id, (int) $record->post_author );
		$this->assertSame( 'post', get_post_meta( $access_id, Access_Writer::META_ITEM_TYPE, true ) );
		$this->assertSame( (string) $post_id, get_post_meta( $access_id, Access_Writer::META_ITEM_ID, true ) );
		$this->assertSame( 'admin', get_post_meta( $access_id, Access_Writer::META_SOURCE, true ) );
		$this->assertSame( '1', get_post_meta( $access_id, Access_Writer::META_CREATED_BY, true ) );

		$expires = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );

		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $expires . ' +0000' ), 5 );
	}

	/** @testdox A null duration is lifetime: the expiry meta is empty. */
	public function test_lifetime_leaves_the_expiry_empty(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		$this->assertIsInt( $access_id );
		$this->assertSame( '', get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true ) );
	}

	/**
	 * @testdox A source and reference already seen writes nothing and returns the existing record.
	 *
	 * Counted with the statuses named explicitly — never 'any', which excludes
	 * exclude_from_search statuses and once made both sides of this equally
	 * blind while the guard itself was broken the same way.
	 */
	public function test_a_repeated_reference_writes_nothing(): void {
		$post_id = self::factory()->post->create();

		$first  = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'crm', 'inv-100' );
		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'crm', 'inv-100' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->all_access_records() );
	}

	/**
	 * Every access record, whatever its status.
	 *
	 * @return array<int, int>
	 */
	private function all_access_records(): array {
		return get_posts(
			array(
				'post_type'      => Post_Types::ACCESS,
				'post_status'    => array( Post_Types::STATUS_ACTIVE, Post_Types::STATUS_EXPIRED, Post_Types::STATUS_REVOKED ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
	}

	/** @testdox A retry after revocation still writes nothing — the reference has been seen. */
	public function test_a_revoked_reference_still_blocks_a_retry(): void {
		$post_id = self::factory()->post->create();

		$first = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'crm', 'inv-200' );
		$this->writer->revoke( $first );

		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'crm', 'inv-200' );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->all_access_records() );
	}

	/** @testdox Re-granting timed access while live stacks the new time onto the existing expiry. */
	public function test_a_live_timed_regrant_stacks(): void {
		$post_id = self::factory()->post->create();

		$first  = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'stripe', 'pi_1' );
		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 10, 'stripe', 'pi_2' );

		$this->assertSame( $first, $second );

		$expires = (string) get_post_meta( $first, Access_Writer::META_EXPIRES_AT, true );

		$this->assertEqualsWithDelta( time() + 40 * DAY_IN_SECONDS, strtotime( $expires . ' +0000' ), 5 );
	}

	/** @testdox Re-granting after expiry writes a fresh record from today. */
	public function test_an_expired_regrant_is_a_fresh_record(): void {
		$post_id = self::factory()->post->create();

		$first = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'stripe', 'pi_3' );
		update_post_meta( $first, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 10, 'stripe', 'pi_4' );

		$this->assertNotSame( $first, $second );
	}

	/** @testdox A lifetime re-grant is allowed, and duplicates. */
	public function test_a_lifetime_regrant_duplicates(): void {
		$post_id = self::factory()->post->create();

		$first  = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );
		$second = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		$this->assertNotSame( $first, $second );
	}

	/** @testdox A file grant validates the target is an attachment; a group grant, its UUID. */
	public function test_file_and_group_targets_resolve(): void {
		$attachment_id = self::factory()->attachment->create();
		$term          = wp_insert_term( 'Writer Group', Access_Taxonomy::TAXONOMY );
		$uuid          = ( new Access_Taxonomy() )->uuid_for( $term['term_id'] );

		$this->assertIsInt( $this->writer->grant( $this->user_id, 'file', (string) $attachment_id, null, 'admin' ) );
		$this->assertIsInt( $this->writer->grant( $this->user_id, 'group', $uuid, null, 'admin' ) );
	}

	/** @testdox Invalid input never writes: bad user, type, target, duration or source. */
	public function test_invalid_input_is_refused(): void {
		$post_id = self::factory()->post->create();

		$this->assertWPError( $this->writer->grant( 999999, 'post', (string) $post_id, null, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'product', (string) $post_id, null, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'file', (string) $post_id, null, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'post', '999999', null, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'group', 'not-a-uuid', null, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'post', (string) $post_id, 0, 'admin' ) );
		$this->assertWPError( $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, ' ' ) );
	}

	/** @testdox Revoking flips the status and fires the action; a non-access post is refused. */
	public function test_revoke(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, null, 'admin' );

		$fired = 0;
		add_action(
			'gatedmedia_access_revoked',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->assertTrue( $this->writer->revoke( $access_id ) );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
		$this->assertSame( 1, $fired );
		$this->assertFalse( $this->writer->revoke( $post_id ) );
	}

	/** @testdox The seven meta keys are registered against the access type, and protected. */
	public function test_meta_is_registered_and_protected(): void {
		$keys = array(
			Access_Writer::META_ITEM_TYPE,
			Access_Writer::META_ITEM_ID,
			Access_Writer::META_EXPIRES_AT,
			Access_Writer::META_SOURCE,
			Access_Writer::META_REFERENCE,
			Access_Writer::META_CREATED_BY,
			Access_Writer::META_PAYLOAD,
		);

		foreach ( $keys as $key ) {
			$this->assertTrue( registered_meta_key_exists( 'post', $key, Post_Types::ACCESS ), "{$key} is not registered" );
			$this->assertTrue( is_protected_meta( $key, 'post' ), "{$key} is not protected" );
		}
	}
}
