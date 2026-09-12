<?php
/**
 * The template renderer.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PinkCrab\Gated_Access\Support\View;

/**
 * `View` is what every admin screen will draw through, so its boundaries are a contract: what a template can see, and what it refuses to load.
 *
 * Templates come from `tests/Fixtures/views` here, pointed at with the `gatedmedia_view_directory` filter, so nothing test-only ever ships in the plugin's own `views/`.
 *
 * @group unit
 */
class Test_View extends TestCase {

	/**
	 * The fixture template directory. Public: WordPress calls it as a filter.
	 */
	public function fixtures(): string {
		return dirname( __DIR__ ) . '/Fixtures/views/';
	}

	/**
	 * Point the renderer at the fixtures.
	 */
	public function set_up(): void {
		add_filter( 'gatedmedia_view_directory', array( $this, 'fixtures' ) );
	}

	/**
	 * Put the real directory back.
	 */
	public function tear_down(): void {
		remove_filter( 'gatedmedia_view_directory', array( $this, 'fixtures' ) );
	}

	/**
	 * PHPUnit 9 calls these, wp-phpunit's snake_case names are ours.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->set_up();
	}

	/**
	 * PHPUnit 9 calls these, wp-phpunit's snake_case names are ours.
	 */
	protected function tearDown(): void {
		$this->tear_down();
		parent::tearDown();
	}

	/** A template is returned as a string, not printed. */
	public function test_get_returns_the_markup(): void {
		$this->expectOutputString( '' );

		$this->assertStringContainsString( '<p class="simple">Hello</p>', View::get( 'simple' ) );
	}

	/** The same template, printed. */
	public function test_render_echoes_the_markup(): void {
		ob_start();
		View::render( 'simple' );
		$printed = (string) ob_get_clean();

		$this->assertStringContainsString( '<p class="simple">Hello</p>', $printed );
	}

	/** The data array reaches the template under its own keys. */
	public function test_data_reaches_the_template(): void {
		$out = View::get(
			'with-data',
			array(
				'title' => 'Add Access',
				'count' => 3,
			)
		);

		$this->assertStringContainsString( '<h2>Add Access</h2>', $out );
		$this->assertStringContainsString( '<span class="count">3</span>', $out );
	}

	/** Two renders of one template do not share data. */
	public function test_data_does_not_carry_between_renders(): void {
		View::get( 'with-data', array( 'title' => 'First' ) );

		$this->assertStringContainsString( '<h2>none</h2>', View::get( 'with-data' ) );
	}

	/** A template in a sub-directory is named by path. */
	public function test_nested_template_renders(): void {
		$this->assertStringContainsString( '<p class="deep">Deep</p>', View::get( 'nested/deep' ) );
	}

	/** A template that is not there renders nothing and does not fatal. */
	public function test_missing_template_renders_empty(): void {
		$this->assertSame( '', View::get( 'no-such-template' ) );
	}

	/** A name climbing out of the view directory is refused. */
	public function test_traversal_out_of_the_directory_is_refused(): void {
		$this->assertSame( '', View::get( '../../../gated-media-access' ) );
	}

	/** An absolute path is not a template name. */
	public function test_absolute_path_is_refused(): void {
		$this->assertSame( '', View::get( dirname( __DIR__, 2 ) . '/gated-media-access' ) );
	}

	/** A template sees the data and nothing else: no `$this`, no caller variables. */
	public function test_template_cannot_see_the_calling_scope(): void {
		$leaked = 'should not be visible';

		$this->assertSame( 'no-this:has-data:clean', View::get( 'scope', array( 'leaked' => $leaked ) ) );
	}

	/** A template that throws takes its buffer with it. */
	public function test_a_throwing_template_leaves_no_open_buffer(): void {
		$before = ob_get_level();

		try {
			View::get( 'throws' );
			$this->fail( 'The exception should have come back out.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'template blew up', $e->getMessage() );
		}

		$this->assertSame( $before, ob_get_level() );
	}

	/** Left alone, templates come from the plugin's own `views/` directory. */
	public function test_default_directory_is_the_plugin_views_folder(): void {
		$this->tear_down();

		$seen = null;
		$spy  = static function ( string $directory ) use ( &$seen ): string {
			$seen = $directory;

			return $directory;
		};

		add_filter( 'gatedmedia_view_directory', $spy );
		View::get( 'simple' );
		remove_filter( 'gatedmedia_view_directory', $spy );

		$this->set_up();

		$this->assertSame( GATEDMEDIA_DIR_PATH . 'views/', $seen );
	}
}
