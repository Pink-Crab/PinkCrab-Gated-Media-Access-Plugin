<?php
/**
 * The typed section collection.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PinkCrab\Gated_Access\Account\Account_Section;
use PinkCrab\Gated_Access\Account\Section_Collection;

/**
 * `Section_Collection` is what `gatedmedia_account_sections` hands to third-party code, so its behaviour is a contract rather than an implementation detail.
 *
 * No WordPress here, because the collection calls none, which is what makes it testable without a database.
 *
 * @group unit
 */
class Test_Section_Collection extends TestCase {

	/**
	 * A section with no WordPress behind it.
	 *
	 * `is_visible()` is driven by a flag rather than a capability, so these never reach for user_can().
	 *
	 * @param string $slug     URL segment.
	 * @param int    $position Nav order.
	 * @param bool   $visible  What is_visible() should answer.
	 */
	private function section( string $slug, int $position = 100, bool $visible = true ): Account_Section {
		return new class( $slug, $position, $visible ) implements Account_Section {

			/**
			 * @param string $slug     URL segment.
			 * @param int    $position Nav order.
			 * @param bool   $visible  What is_visible() answers.
			 */
			public function __construct(
				private string $slug,
				private int $position,
				private bool $visible,
			) {
			}

			/** The URL segment. */
			public function slug(): string {
				return $this->slug;
			}

			/** The page title. */
			public function title(): string {
				return ucfirst( $this->slug );
			}

			/** The sub-line. */
			public function description(): string {
				return '';
			}

			/** The nav label. */
			public function menu_label(): string {
				return ucfirst( $this->slug );
			}

			/** The sprite symbol. */
			public function icon(): string {
				return '';
			}

			/** The block that draws it. */
			public function block(): string {
				return 'test/' . $this->slug;
			}

			/** Nav order. */
			public function position(): int {
				return $this->position;
			}

			/**
			 * Whether this user may see it.
			 *
			 * @param int $user_id Ignored, the flag decides.
			 */
			public function is_visible( int $user_id ): bool {
				return $this->visible;
			}
		};
	}

	/** @testdox A collection reports how many sections it holds. */
	public function test_counts_its_sections(): void {
		$collection = new Section_Collection(
			$this->section( 'one' ),
			$this->section( 'two' ),
		);

		$this->assertCount( 2, $collection );
	}

	/** @testdox Sections are keyed by slug, so two with the same slug collapse into one. */
	public function test_duplicate_slugs_collapse(): void {
		$collection = new Section_Collection(
			$this->section( 'files' ),
			$this->section( 'files' ),
		);

		$this->assertCount( 1, $collection );
	}

	/** @testdox Adding a section returns a new collection and leaves the original alone. */
	public function test_add_does_not_mutate_the_original(): void {
		$original = new Section_Collection( $this->section( 'one' ) );
		$extended = $original->add( $this->section( 'two' ) );

		$this->assertCount( 1, $original, 'The original was mutated.' );
		$this->assertCount( 2, $extended );
	}

	/** @testdox Adding a section whose slug already exists replaces it, so a site can swap ours for its own. */
	public function test_add_replaces_a_matching_slug(): void {
		$original = new Section_Collection( $this->section( 'profile', 10 ) );
		$swapped  = $original->add( $this->section( 'profile', 99 ) );

		$this->assertCount( 1, $swapped );
		$this->assertSame( 99, $swapped->get( 'profile' )?->position() );
	}

	/** @testdox Removing a section returns a new collection without it. */
	public function test_remove_returns_a_copy_without_the_section(): void {
		$original = new Section_Collection(
			$this->section( 'one' ),
			$this->section( 'two' ),
		);

		$reduced = $original->remove( 'one' );

		$this->assertCount( 2, $original );
		$this->assertCount( 1, $reduced );
		$this->assertFalse( $reduced->has( 'one' ) );
	}

	/** @testdox Asking for a slug that is not there returns null rather than throwing. */
	public function test_get_returns_null_for_an_unknown_slug(): void {
		$collection = new Section_Collection( $this->section( 'one' ) );

		$this->assertNull( $collection->get( 'nope' ) );
	}

	/** @testdox Sections come back in nav order, not the order they were added. */
	public function test_sorts_by_position(): void {
		$collection = new Section_Collection(
			$this->section( 'last', 30 ),
			$this->section( 'first', 10 ),
			$this->section( 'middle', 20 ),
		);

		$slugs = array_map(
			static fn( Account_Section $section ): string => $section->slug(),
			$collection->sorted()
		);

		$this->assertSame( array( 'first', 'middle', 'last' ), $slugs );
	}

	/** @testdox Iterating yields sections in nav order, so a consumer cannot forget to sort. */
	public function test_iterates_in_nav_order(): void {
		$collection = new Section_Collection(
			$this->section( 'last', 30 ),
			$this->section( 'first', 10 ),
		);

		$slugs = array();

		foreach ( $collection as $section ) {
			$slugs[] = $section->slug();
		}

		$this->assertSame( array( 'first', 'last' ), $slugs );
	}

	/** @testdox The first section is the lowest position, which is where the bare account route lands. */
	public function test_first_is_the_lowest_position(): void {
		$collection = new Section_Collection(
			$this->section( 'later', 20 ),
			$this->section( 'earlier', 10 ),
		);

		$this->assertSame( 'earlier', $collection->first()?->slug() );
	}

	/** @testdox An empty collection has no first section rather than erroring. */
	public function test_first_of_an_empty_collection_is_null(): void {
		$this->assertNull( ( new Section_Collection() )->first() );
	}

	/** @testdox Filtering by user drops the sections that user may not see. */
	public function test_visible_to_drops_hidden_sections(): void {
		$collection = new Section_Collection(
			$this->section( 'shown', 10, true ),
			$this->section( 'hidden', 20, false ),
		);

		$visible = $collection->visible_to( 1 );

		$this->assertCount( 1, $visible );
		$this->assertTrue( $visible->has( 'shown' ) );
		$this->assertFalse( $visible->has( 'hidden' ) );
	}

	/** @testdox Filtering by user leaves the original collection untouched. */
	public function test_visible_to_does_not_mutate_the_original(): void {
		$collection = new Section_Collection(
			$this->section( 'shown', 10, true ),
			$this->section( 'hidden', 20, false ),
		);

		$collection->visible_to( 1 );

		$this->assertCount( 2, $collection );
	}

	/** @testdox The slug list is every slug the collection holds. */
	public function test_lists_its_slugs(): void {
		$collection = new Section_Collection(
			$this->section( 'one' ),
			$this->section( 'two' ),
		);

		$slugs = $collection->slugs();

		sort( $slugs );

		$this->assertSame( array( 'one', 'two' ), $slugs );
	}
}
