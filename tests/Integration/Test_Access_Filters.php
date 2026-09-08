<?php
/**
 * The Access list's toolbar filters.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_Query;
use WP_UnitTestCase;
use PinkCrab\Gated_Access\Admin\Access_Filters;
use PinkCrab\Gated_Access\Access\Access_Lookup;
use PinkCrab\Gated_Access\Access\Access_Validator;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * The toolbar renders on our list only, and what it submits becomes meta clauses on that list's query, with the holder riding core's author var.
 *
 * @group integration
 */
class Test_Access_Filters extends WP_UnitTestCase {

	private Access_Filters $filters;

	private Access_Writer $writer;

	public function set_up(): void {
		parent::set_up();

		$taxonomy      = new Access_Taxonomy();
		$this->filters = new Access_Filters( $taxonomy );
		$this->writer  = new Access_Writer( new Access_Validator( $taxonomy ), new Access_Lookup() );

		// The framework unregisters every meta key after each test, so re-register.
		$this->writer->register_meta();
	}

	/** @testdox The toolbar renders on our list's top bar only: holder, item type, item, source. */
	public function test_filters_render(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id = self::factory()->post->create();
		$this->assertIsInt( $this->writer->grant( $user_id, 'post', (string) $post_id, 30, 'admin' ) );

		ob_start();
		$this->filters->render_filters( Post_Types::ACCESS, 'top' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="author"', $html );
		$this->assertStringContainsString( 'data-gatedmedia-picker="gatedmedia_search_users"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_item_type"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_item"', $html );
		$this->assertStringContainsString( 'name="gatedmedia_source"', $html );
		// The source options are what the records actually carry.
		$this->assertStringContainsString( '<option value="admin"', $html );

		ob_start();
		$this->filters->render_filters( 'post', 'top' );
		$this->filters->render_filters( Post_Types::ACCESS, 'bottom' );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Every filter control is labelled, not left to a placeholder.
	 *
	 * @dataProvider filter_controls
	 *
	 * @param string $id The control's id attribute.
	 */
	public function test_every_filter_control_has_a_label( string $id ): void {
		ob_start();
		$this->filters->render_filters( Post_Types::ACCESS, 'top' );
		$html = (string) ob_get_clean();

		// A placeholder is not a label: it goes as soon as anything is typed.
		$this->assertMatchesRegularExpression(
			sprintf( '/<label[^>]*\bfor="%s"[^>]*>/', preg_quote( $id, '/' ) ),
			$html,
			sprintf( 'No label targets %s.', $id )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function filter_controls(): array {
		return array(
			'holder search' => array( 'gatedmedia_filter_holder_search' ),
			'item type'     => array( 'gatedmedia_filter_item_type' ),
			'item search'   => array( 'gatedmedia_filter_item_search' ),
			'source'        => array( 'gatedmedia_filter_source' ),
		);
	}

	/** @testdox Those labels are hidden from sight, so the toolbar still reads as one row. */
	public function test_the_filter_labels_are_screen_reader_only(): void {
		ob_start();
		$this->filters->render_filters( Post_Types::ACCESS, 'top' );
		$html = (string) ob_get_clean();

		$this->assertSame(
			4,
			substr_count( $html, 'class="screen-reader-text"' ),
			'Each of the four controls needs its own hidden label.'
		);
	}

	/** @testdox The chosen filters become meta clauses on the list query, and touch no other query. */
	public function test_filters_shape_the_query(): void {
		$_GET['gatedmedia_item_type'] = 'post';
		$_GET['gatedmedia_item']      = '42';
		$_GET['gatedmedia_source']    = 'admin';

		$query = new WP_Query();
		$query->set( 'post_type', Post_Types::ACCESS );

		$other = new WP_Query();
		$other->set( 'post_type', 'post' );

		$GLOBALS['wp_the_query'] = $query;
		$this->filters->apply( $query );
		$GLOBALS['wp_the_query'] = $other;
		$this->filters->apply( $other );
		unset( $GLOBALS['wp_the_query'], $_GET['gatedmedia_item_type'], $_GET['gatedmedia_item'], $_GET['gatedmedia_source'] );

		$this->assertSame(
			array(
				array(
					'key'   => Access_Writer::META_ITEM_TYPE,
					'value' => 'post',
				),
				array(
					'key'   => Access_Writer::META_ITEM_ID,
					'value' => '42',
				),
				array(
					'key'   => Access_Writer::META_SOURCE,
					'value' => 'admin',
				),
			),
			$query->get( 'meta_query' )
		);

		$this->assertSame( '', $other->get( 'meta_query' ) );
	}
}
