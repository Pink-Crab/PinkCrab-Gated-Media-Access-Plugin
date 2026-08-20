<?php
/**
 * The access list on a user's profile.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Access_List;
use PinkCrab\Gated_Access\Admin\Profile_Access_List;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Grant_Validator;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * One person's records, all statuses, read-only, and only for those who may
 * give access.
 *
 * @group integration
 */
class Test_Profile_Access_List extends WP_UnitTestCase {

	private Profile_Access_List $profile_list;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy           = new Access_Taxonomy();
		$this->writer       = new Access_Writer( new Grant_Validator( $taxonomy ) );
		$this->profile_list = new Profile_Access_List( new Access_List( $taxonomy ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** @testdox The profile lists the person's records across all three statuses. */
	public function test_lists_all_statuses(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$active  = $this->grant( 'The Active Report' );
		$revoked = $this->grant( 'The Revoked Report' );
		$this->writer->revoke( $revoked );
		$expired = $this->grant( 'The Expired Report' );
		$this->writer->expire( $expired );

		$html = $this->render();

		$this->assertStringContainsString( 'The Active Report', $html );
		$this->assertStringContainsString( 'The Revoked Report', $html );
		$this->assertStringContainsString( 'The Expired Report', $html );
		$this->assertStringContainsString( 'Active', $html );
		$this->assertStringContainsString( 'Revoked', $html );
		$this->assertStringContainsString( 'Expired', $html );
		$this->assertStringContainsString( 'author=' . $this->user_id, $html );
	}

	/** @testdox Another user's records do not bleed in. */
	public function test_only_that_users_records(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post  = self::factory()->post->create( array( 'post_title' => 'Someone Else Holds This' ) );
		$this->assertIsInt( $this->writer->grant( $other, 'post', (string) $post, null, 'admin' ) );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Someone Else Holds This', $html );
		$this->assertStringContainsString( 'no access records', $html );
	}

	/** @testdox Without the give-access capability, the profile shows nothing of ours. */
	public function test_renders_nothing_without_the_capability(): void {
		wp_set_current_user( $this->user_id );
		$this->grant( 'The Hidden Report' );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * Grants a fresh titled post to the shared holder.
	 *
	 * @param string $title The target's title.
	 */
	private function grant( string $title ): int {
		$post_id   = self::factory()->post->create( array( 'post_title' => $title ) );
		$access_id = $this->writer->grant( $this->user_id, 'post', (string) $post_id, 30, 'admin' );

		$this->assertIsInt( $access_id );

		return $access_id;
	}

	/**
	 * What the profile section renders for the shared holder.
	 */
	private function render(): string {
		ob_start();
		$this->profile_list->render( get_userdata( $this->user_id ) );

		return (string) ob_get_clean();
	}
}
