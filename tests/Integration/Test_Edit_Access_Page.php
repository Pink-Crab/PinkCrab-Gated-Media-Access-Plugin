<?php
/**
 * The Edit Access page.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Access\Resolver;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Admin\Access_List;
use PinkCrab\Gated_Access\Admin\Edit_Access_Page;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * A record's expiry can be moved, cleared to lifetime, or pulled into the
 * past — the status mirrors the date, the holder's access follows within
 * the request, and a revoked record refuses.
 *
 * @group integration
 */
class Test_Edit_Access_Page extends WP_UnitTestCase {

	private Edit_Access_Page $page;

	private Access_Writer $writer;

	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$taxonomy     = new Access_Taxonomy();
		$this->writer = new Access_Writer( new Access_Validator( $taxonomy ), new Access_Lookup() );
		$this->page   = new Edit_Access_Page( $this->writer, new Access_List( $taxonomy ) );

		// The framework's tear_down() unregisters every meta key after every
		// test (abstract-testcase.php:212), so re-register here.
		$this->writer->register_meta();

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/** @testdox A new future date lands in the meta as UTC, and the record stays active. */
	public function test_moves_the_expiry(): void {
		$access_id = $this->grant();

		$this->assertTrue( $this->page->apply( $access_id, gmdate( 'Y-m-d\TH:i', time() + WEEK_IN_SECONDS ) ) );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $access_id ) );

		$stored = (string) get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true );
		$this->assertEqualsWithDelta( time() + WEEK_IN_SECONDS, (int) strtotime( $stored . ' +0000' ), MINUTE_IN_SECONDS );
	}

	/** @testdox Clearing the field makes the record lifetime. */
	public function test_clears_to_lifetime(): void {
		$access_id = $this->grant();

		$this->assertTrue( $this->page->apply( $access_id, '' ) );
		$this->assertSame( '', get_post_meta( $access_id, Access_Writer::META_EXPIRES_AT, true ) );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $access_id ) );
	}

	/** @testdox A past date expires the record, and the holder loses access within the request. */
	public function test_a_past_date_expires_now(): void {
		$post_id   = self::factory()->post->create();
		$access_id = $this->grant( $post_id );

		$resolver = new Resolver( new Access_Taxonomy() );
		$loader   = new Hook_Loader();
		$resolver->register_hooks( $loader );
		$loader->register_hooks();

		$this->assertTrue( $resolver->can_see( $this->user_id, 'post', (string) $post_id ) );

		$this->assertTrue( $this->page->apply( $access_id, gmdate( 'Y-m-d\TH:i', time() - DAY_IN_SECONDS ) ) );

		$this->assertSame( Post_Types::STATUS_EXPIRED, get_post_status( $access_id ) );
		$this->assertFalse( $resolver->can_see( $this->user_id, 'post', (string) $post_id ) );
	}

	/** @testdox A future date on an expired record brings it back active. */
	public function test_a_future_date_reactivates_an_expired_record(): void {
		$access_id = $this->grant();
		$this->writer->expire( $access_id );
		$this->assertSame( Post_Types::STATUS_EXPIRED, get_post_status( $access_id ) );

		$this->assertTrue( $this->page->apply( $access_id, gmdate( 'Y-m-d\TH:i', time() + WEEK_IN_SECONDS ) ) );
		$this->assertSame( Post_Types::STATUS_ACTIVE, get_post_status( $access_id ) );
	}

	/** @testdox A revoked record refuses the edit — revocation is final. */
	public function test_revoked_records_refuse(): void {
		$access_id = $this->grant();
		$this->writer->revoke( $access_id );

		$this->assertFalse( $this->page->apply( $access_id, gmdate( 'Y-m-d\TH:i', time() + WEEK_IN_SECONDS ) ) );
		$this->assertSame( Post_Types::STATUS_REVOKED, get_post_status( $access_id ) );
	}

	/** @testdox Garbage input and non-records both refuse. */
	public function test_refuses_bad_input(): void {
		$access_id = $this->grant();

		$this->assertFalse( $this->page->apply( $access_id, 'not a date at all' ) );
		$this->assertFalse( $this->page->apply( self::factory()->post->create(), '' ) );
	}

	/** @testdox The page renders the record's summary and the prefilled expiry field. */
	public function test_renders_summary_and_field(): void {
		$access_id      = $this->grant();
		$_GET['access'] = (string) $access_id;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		unset( $_GET['access'] );

		$this->assertStringContainsString( 'Edit Access', $html );
		$this->assertStringContainsString( 'gatedmedia_expires', $html );
		$this->assertStringContainsString( 'datetime-local', $html );
		$this->assertStringContainsString( 'access" value="' . $access_id . '"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
	}

	/** @testdox A revoked record's page offers no form. */
	public function test_renders_no_form_for_revoked(): void {
		$access_id = $this->grant();
		$this->writer->revoke( $access_id );
		$_GET['access'] = (string) $access_id;

		ob_start();
		$this->page->render();
		$html = (string) ob_get_clean();

		unset( $_GET['access'] );

		$this->assertStringNotContainsString( 'gatedmedia_expires', $html );
		$this->assertStringContainsString( 'revoked', $html );
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
