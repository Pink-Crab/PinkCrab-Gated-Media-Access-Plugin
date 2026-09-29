<?php
/**
 * The front-end label dictionary.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Support\Labels;

/**
 * Every front-end string was written inline in its render file, so a site could only reword one by re-rendering the whole block or translating the text domain. One keyed dictionary behind one filter means a site can change a single line and leave the rest alone.
 *
 * @group integration
 */
class Test_Labels extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_labels' );
		Labels::forget();

		parent::tear_down();
	}

	/** @testdox A key answers with its translated default. */
	public function test_a_key_answers_with_its_default(): void {
		$this->assertSame( 'You already have this.', Labels::text( 'product.held.notice' ) );
	}

	/** @testdox A site can reword one label and leave every other alone. */
	public function test_one_label_can_be_reworded(): void {
		add_filter(
			'gatedmedia_labels',
			static function ( array $labels ): array {
				$labels['product.held.notice'] = 'It is yours already.';

				return $labels;
			}
		);

		$this->assertSame( 'It is yours already.', Labels::text( 'product.held.notice' ) );
		$this->assertSame( 'Get access', Labels::text( 'product.button.paid' ), 'rewording one must not disturb the rest' );
	}

	/**
	 * @testdox A key nothing defines answers with the key, not an empty string.
	 *
	 * An empty label is a blank button with no clue where it came from. The key is at least searchable.
	 */
	public function test_an_unknown_key_answers_with_itself(): void {
		$this->assertSame( 'nothing.defines.this', Labels::text( 'nothing.defines.this' ) );
	}

	/**
	 * @testdox A label carrying a placeholder keeps it, so what fills it still lands.
	 *
	 * The dictionary hands back the pattern; the caller runs sprintf. A site dropping the token would otherwise silently lose the number.
	 */
	public function test_placeholders_survive(): void {
		$this->assertStringContainsString( '%s', Labels::text( 'orders.placed' ) );

		add_filter(
			'gatedmedia_labels',
			static function ( array $labels ): array {
				$labels['orders.placed'] = 'Ordered %s';

				return $labels;
			}
		);

		// The dictionary is read once per request, so a filter added after a lookup needs the memo dropped.
		Labels::forget();

		$this->assertSame( 'Ordered 3 May', sprintf( Labels::text( 'orders.placed' ), '3 May' ) );
	}

	/** @testdox The filter runs once per request, not once per lookup. */
	public function test_the_filter_runs_once(): void {
		$runs = 0;

		add_filter(
			'gatedmedia_labels',
			static function ( array $labels ) use ( &$runs ): array {
				++$runs;

				return $labels;
			}
		);

		Labels::text( 'product.held.notice' );
		Labels::text( 'product.button.paid' );
		Labels::text( 'product.held.notice' );

		$this->assertSame( 1, $runs );
	}

	/** @testdox Anything the filter returns that is not a list of strings is ignored rather than drawn. */
	public function test_a_broken_filter_is_ignored(): void {
		add_filter(
			'gatedmedia_labels',
			static function ( array $labels ): array {
				$labels['product.held.notice'] = array( 'not', 'a', 'string' );

				return $labels;
			}
		);

		$this->assertSame( 'You already have this.', Labels::text( 'product.held.notice' ) );
	}

	/** @testdox Every default is a non-empty string, so no key can draw a blank. */
	public function test_no_default_is_blank(): void {
		foreach ( Labels::all() as $key => $text ) {
			$this->assertIsString( $text, "{$key} is not a string" );
			$this->assertNotSame( '', trim( $text ), "{$key} is blank" );
		}
	}
}
