<?php
/**
 * The pickers' search endpoints.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Picker_Search;

/**
 * Each search finds its own kind and shapes the result for the picker:
 * id and label, nothing else.
 *
 * @group integration
 */
class Test_Picker_Search extends WP_UnitTestCase {

	private Picker_Search $search;

	public function set_up(): void {
		parent::set_up();

		$this->search = new Picker_Search();
	}

	/** @testdox Users are found by name, login or email, labelled name (email). */
	public function test_find_users(): void {
		self::factory()->user->create(
			array(
				'user_login'   => 'dholder',
				'user_email'   => 'dave@example.test',
				'display_name' => 'Dave Holder',
			)
		);

		$by_name  = $this->search->find_users( 'Dave' );
		$by_email = $this->search->find_users( 'dave@example' );

		$this->assertCount( 1, $by_name );
		$this->assertSame( 'Dave Holder (dave@example.test)', $by_name[0]['label'] );
		$this->assertCount( 1, $by_email );
	}

	/** @testdox Posts and pages are found by title; attachments never are. */
	public function test_find_posts(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'The Gated Report' ) );
		self::factory()->post->create( array( 'post_title' => 'The Gated Page', 'post_type' => 'page' ) );
		self::factory()->attachment->create( array( 'post_title' => 'The Gated Image', 'post_mime_type' => 'image/jpeg' ) );

		$found = $this->search->find_posts( 'Gated' );

		$labels = array_column( $found, 'label' );
		sort( $labels );

		$this->assertSame( array( 'The Gated Page (page)', 'The Gated Report (post)' ), $labels );
		$this->assertContains( $post_id, array_column( $found, 'id' ) );
	}

	/** @testdox Files are found by title, labelled with their mime type. */
	public function test_find_files(): void {
		self::factory()->post->create( array( 'post_title' => 'Gated but not a file' ) );
		$file_id = self::factory()->attachment->create( array( 'post_title' => 'Gated Diagram', 'post_mime_type' => 'image/png' ) );

		$found = $this->search->find_files( 'Gated' );

		$this->assertCount( 1, $found );
		$this->assertSame( $file_id, $found[0]['id'] );
		$this->assertSame( 'Gated Diagram (image/png)', $found[0]['label'] );
	}
}
