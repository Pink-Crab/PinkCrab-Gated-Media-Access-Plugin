<?php
/**
 * The revoke action and the site's revoke behaviour.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Admin\Revoke_Action;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Each behaviour leaves the record where the setting says, every one goes
 * through the writer, and the holder's access is gone within the request.
 *
 * @group integration
 */
class Test_Revoke_Action extends WP_UnitTestCase {

	private Revoke_Action $action;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->writer = new Access_Writer( new Grant_Validator( new Access_Taxonomy() ) );
		$this->action = new Revoke_Action( $this->writer, new Settings() );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** @testdox With nothing configured, revoking marks the record revoked — history kept. */
	public function test_default_behaviour_is_revoke(): void {
		$access_id = $this->grant();

		$this->assertTrue( $this->action->apply( $access_id ) );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
	}

	/** @testdox Set to expire, revoking pulls the date to now and marks the record expired. */
	public function test_expire_behaviour(): void {
		update_option( Settings::OPTION, array( 'revoke_behaviour' => 'expire' ) );
		$access_id = $this->grant();

		$this->assertTrue( $this->action->apply( $access_id ) );
		$this->assertSame( Post_Types::STATUS_EXPIRED, get_post_status( $access_id ) );

		$expires = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );
		$this->assertLessThanOrEqual( time(), (int) strtotime( $expires . ' +0000' ) );
	}

	/** @testdox Set to delete, revoking removes the record outright and still announces the withdrawal. */
	public function test_delete_behaviour(): void {
		update_option( Settings::OPTION, array( 'revoke_behaviour' => 'delete' ) );
		$access_id = $this->grant();

		$fired = array();
		add_action(
			'gatedmedia_access_revoked',
			static function ( int $id, int $user_id ) use ( &$fired ): void {
				$fired[] = array( $id, $user_id );
			},
			10,
			2
		);

		$this->assertTrue( $this->action->apply( $access_id ) );
		$this->assertNull( get_post( $access_id ) );
		$this->assertSame( array( array( $access_id, $this->user_id ) ), $fired );
	}

	/** @testdox An unrecognised stored value falls back to revoke. */
	public function test_unknown_behaviour_falls_back_to_revoke(): void {
		update_option( Settings::OPTION, array( 'revoke_behaviour' => 'obliterate' ) );
		$access_id = $this->grant();

		$this->assertTrue( $this->action->apply( $access_id ) );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
	}

	/** @testdox The filter has the last word over the stored setting. */
	public function test_filter_overrides_the_option(): void {
		update_option( Settings::OPTION, array( 'revoke_behaviour' => 'delete' ) );
		add_filter( 'gatedmedia_revoke_behaviour', static fn (): string => 'revoke' );

		$access_id = $this->grant();

		$this->assertTrue( $this->action->apply( $access_id ) );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
	}

	/** @testdox Whatever the behaviour, the holder's access is gone within the same request. */
	public function test_holder_loses_access_in_request(): void {
		foreach ( array( 'revoke', 'expire', 'delete' ) as $behaviour ) {
			update_option( Settings::OPTION, array( 'revoke_behaviour' => $behaviour ) );

			$post_id   = self::factory()->post->create();
			$access_id = $this->grant( $post_id );

			$resolver = new Resolver( new Access_Taxonomy() );
			$loader   = new Hook_Loader();
			$resolver->register_hooks( $loader );
			$loader->register_hooks();

			$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $post_id ), $behaviour );

			$this->action->apply( $access_id );

			$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $post_id ), $behaviour );
		}
	}

	/** @testdox Deleting refuses anything that is not an access record. */
	public function test_delete_refuses_other_post_types(): void {
		$post_id = self::factory()->post->create();

		$this->assertFalse( $this->writer->delete( $post_id ) );
		$this->assertNotNull( get_post( $post_id ) );
	}

	/** @testdox The row link posts to admin-post with the record and a nonce. */
	public function test_url_for_carries_action_record_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$url = Revoke_Action::url_for( 42 );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=' . Revoke_Action::ACTION, $url );
		$this->assertStringContainsString( 'access=42', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}

	/**
	 * Grants through the writer for a fresh (or given) post target.
	 *
	 * @param int|null $post_id A target to reuse, or null for a fresh one.
	 */
	private function grant( ?int $post_id = null ): int {
		$post_id ??= self::factory()->post->create();

		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin' );

		$this->assertIsInt( $access_id );

		return $access_id;
	}
}
