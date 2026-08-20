<?php
/**
 * The daily expiry sweep.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Access\Sweep;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Past-expiry active records are swept, everything else is left alone, and the
 * sweep itself changes nothing — every move is the writer's.
 *
 * @group integration
 */
class Test_Sweep extends WP_UnitTestCase {

	private Sweep $sweep;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer = new Access_Writer( new Grant_Validator( new Access_Taxonomy() ) );
		$this->sweep  = new Sweep( $this->writer );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Sweep::HOOK );
		parent::tear_down();
	}

	/** @testdox A past-expiry active record is swept to expired, and its date is left as it was. */
	public function test_sweeps_a_past_expiry_record(): void {
		$access_id = $this->grant_days( 30 );
		$past      = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		update_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, $past );

		$this->assertSame( 1, $this->sweep->run() );
		$this->assertSame( Post_Types::STATUS_EXPIRED, get_post_status( $access_id ) );
		$this->assertSame( $past, get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true ) );
	}

	/** @testdox Lifetime and future-dated records are untouched. */
	public function test_leaves_lifetime_and_future_records(): void {
		$lifetime = $this->grant_days( null );
		$future   = $this->grant_days( 30 );

		$this->assertSame( 0, $this->sweep->run() );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $lifetime ) );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $future ) );
	}

	/** @testdox A revoked record past its date is not the sweep's to touch. */
	public function test_leaves_revoked_records(): void {
		$access_id = $this->grant_days( 30 );
		update_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
		$this->writer->revoke( $access_id );

		$this->assertSame( 0, $this->sweep->run() );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
	}

	/** @testdox The expired action fires once per swept record, carrying the record and its holder. */
	public function test_fires_the_expired_action_per_record(): void {
		$first  = $this->grant_days( 30 );
		$second = $this->grant_days( 30, self::factory()->post->create() );
		$past   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		update_post_meta( $first, Access_Writer::META_EXPIRES_AT, $past );
		update_post_meta( $second, Access_Writer::META_EXPIRES_AT, $past );

		$fired = array();
		add_action(
			'gatedmedia_access_expired',
			static function ( int $access_id, int $user_id ) use ( &$fired ): void {
				$fired[ $access_id ] = $user_id;
			},
			10,
			2
		);

		$this->assertSame( 2, $this->sweep->run() );
		$this->assertSame( array( $first => $this->user_id, $second => $this->user_id ), $fired );
	}

	/** @testdox Scheduling twice leaves exactly one daily event. */
	public function test_schedules_exactly_once(): void {
		$this->sweep->schedule();
		$scheduled = wp_next_scheduled( Sweep::HOOK );
		$this->sweep->schedule();

		$this->assertNotFalse( $scheduled );
		$this->assertSame( $scheduled, wp_next_scheduled( Sweep::HOOK ) );

		$event = wp_get_scheduled_event( Sweep::HOOK );
		$this->assertNotFalse( $event );
		$this->assertSame( 'daily', $event->schedule );
	}

	/** @testdox The writer's expire pulls a live record's date to now; a swept holder loses nothing they had not already lost. */
	public function test_writer_expire_pulls_a_live_date_to_now(): void {
		$access_id = $this->grant_days( 30 );

		$this->assertTrue( $this->writer->expire( $access_id ) );
		$this->assertSame( Post_Types::STATUS_EXPIRED, get_post_status( $access_id ) );

		$expires = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );
		$this->assertLessThanOrEqual( time(), (int) strtotime( $expires . ' +0000' ) );
	}

	/** @testdox Expiring a record is visible to the resolver within the same request. */
	public function test_expire_is_visible_to_the_resolver_in_request(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->grant_days( 30, $post_id );

		$resolver = new Resolver( new Access_Taxonomy() );
		$loader   = new \PinkCrab\Loader\Hook_Loader();
		$resolver->register_hooks( $loader );
		$loader->register_hooks();

		$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $post_id ) );

		$this->writer->expire( $access_id );

		$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $post_id ) );
	}

	/** @testdox Expire refuses anything that is not an access record. */
	public function test_expire_refuses_other_post_types(): void {
		$this->assertFalse( $this->writer->expire( self::factory()->post->create() ) );
	}

	/**
	 * Grants through the writer for a fresh (or given) post target.
	 *
	 * @param int|null $duration_days Days, or null for lifetime.
	 * @param int|null $post_id       A target to reuse, or null for a fresh one.
	 */
	private function grant_days( ?int $duration_days, ?int $post_id = null ): int {
		$post_id ??= self::factory()->post->create();

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, $duration_days, 'admin' );

		$this->assertIsInt( $access_id );

		return $access_id;
	}
}
