<?php
/**
 * The picker components.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Pickers\File_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Group_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\Post_Picker;
use PinkCrab\Gated_Access\Admin\Pickers\User_Picker;

/**
 * Each picker is a drop-in: a visible control, a hidden input carrying the
 * choice, and the data attributes the admin bundle binds to.
 *
 * @group integration
 */
class Test_Pickers extends WP_UnitTestCase {

	/** @testdox Each search picker renders the pair against its own endpoint — one pattern for all four. */
	public function test_search_pickers_render_their_endpoints(): void {
		$expected = array(
			'gatedmedia_search_users'  => new User_Picker( 'the_user', 'the_user' ),
			'gatedmedia_search_posts'  => new Post_Picker( 'the_post', 'the_post' ),
			'gatedmedia_search_files'  => new File_Picker( 'the_file', 'the_file' ),
			'gatedmedia_search_groups' => new Group_Picker( 'the_group', 'the_group' ),
		);

		foreach ( $expected as $endpoint => $picker ) {
			ob_start();
			$picker->render();
			$html = (string) ob_get_clean();

			$this->assertStringContainsString( 'data-gatedmedia-picker="' . $endpoint . '"', $html, $endpoint );
			$this->assertStringContainsString( 'type="hidden"', $html, $endpoint );
		}
	}

	/** @testdox A pre-chosen value lands in the hidden input, its label in the visible one. */
	public function test_prechosen_value_and_label(): void {
		ob_start();
		( new User_Picker( 'the_user', 'the_user', '42', 'Dave Holder (dave@example.test)' ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="the_user"', $html );
		$this->assertStringContainsString( 'value="42"', $html );
		$this->assertStringContainsString( 'value="Dave Holder (dave@example.test)"', $html );
	}

}
