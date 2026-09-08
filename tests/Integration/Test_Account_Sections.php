<?php
/**
 * The account section list and its extension point.
 *
 * @package PinkCrab\Gated_Access\Tests
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Tests\Integration;

use WP_UnitTestCase;
use PinkCrab\Gated_Access\Account\Section;
use PinkCrab\Gated_Access\Account\Section_Registry;
use PinkCrab\Gated_Access\Account\Section_Collection;

/**
 * `gatedmedia_account_sections` is the only place third-party code adds UI, so these cover the promises made to whoever uses it.
 *
 * @group integration
 */
class Test_Account_Sections extends WP_UnitTestCase {

	/**
	 * Removes anything a test hooked on, so one test cannot leak into another.
	 */
	public function tear_down(): void {
		remove_all_filters( 'gatedmedia_account_sections' );
		remove_all_filters( 'gatedmedia_account_slug' );
		parent::tear_down();
	}

	/** @testdox The four sections ship by default. */
	public function test_ships_the_four_default_sections(): void {
		$slugs = ( new Section_Registry() )->all()->slugs();

		sort( $slugs );

		$this->assertSame( array( 'files', 'my-access', 'orders', 'profile' ), $slugs );
	}

	/** @testdox My Access is first, so it is what the bare account route lands on. */
	public function test_my_access_is_the_landing_section(): void {
		$this->assertSame( 'my-access', ( new Section_Registry() )->all()->first()?->slug() );
	}

	/** @testdox Every default section names a block that is actually registered. */
	public function test_default_sections_name_registered_blocks(): void {
		foreach ( ( new Section_Registry() )->all() as $section ) {
			$this->assertTrue(
				\WP_Block_Type_Registry::get_instance()->is_registered( $section->block() ),
				sprintf( 'Section "%s" names unregistered block "%s".', $section->slug(), $section->block() )
			);
		}
	}

	/** @testdox A third party can add a section through the filter. */
	public function test_a_third_party_can_add_a_section(): void {
		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->add(
				new Section(
					slug: 'subscriptions',
					title: 'Subscriptions',
					menu_label: 'Subscriptions',
					block: 'third-party/subscriptions',
					position: 25,
				)
			)
		);

		$sections = ( new Section_Registry() )->all();

		$this->assertTrue( $sections->has( 'subscriptions' ) );
		$this->assertCount( 5, $sections );
	}

	/** @testdox An added section takes its place in nav order rather than going last. */
	public function test_an_added_section_sorts_by_its_position(): void {
		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->add(
				new Section(
					slug: 'subscriptions',
					title: 'Subscriptions',
					menu_label: 'Subscriptions',
					block: 'third-party/subscriptions',
					position: 25,
				)
			)
		);

		$slugs = array_map(
			static fn( $section ): string => $section->slug(),
			( new Section_Registry() )->all()->sorted()
		);

		$this->assertSame(
			array( 'my-access', 'files', 'subscriptions', 'orders', 'profile' ),
			$slugs
		);
	}

	/** @testdox A third party can replace one of ours by reusing its slug. */
	public function test_a_third_party_can_replace_one_of_ours(): void {
		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->add(
				new Section(
					slug: 'profile',
					title: 'My details',
					menu_label: 'My details',
					block: 'third-party/profile',
					position: 40,
				)
			)
		);

		$sections = ( new Section_Registry() )->all();

		$this->assertCount( 4, $sections );
		$this->assertSame( 'third-party/profile', $sections->get( 'profile' )?->block() );
	}

	/** @testdox A third party can remove a section entirely. */
	public function test_a_third_party_can_remove_a_section(): void {
		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->remove( 'orders' )
		);

		$this->assertFalse( ( new Section_Registry() )->all()->has( 'orders' ) );
	}

	/** @testdox A filter returning the wrong type falls back to ours rather than taking the page down. */
	public function test_a_broken_filter_falls_back_to_the_defaults(): void {
		add_filter( 'gatedmedia_account_sections', static fn(): string => 'not a collection' );

		$this->assertCount( 4, ( new Section_Registry() )->all() );
	}

	/** @testdox Sections are resolved once and reused for the rest of the request. */
	public function test_the_section_list_is_memoised(): void {
		$registry = new Section_Registry();
		$first    = $registry->all();

		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->remove( 'orders' )
		);

		$this->assertSame( $first, $registry->all(), 'The filter ran a second time.' );
	}

	/** @testdox A signed-out visitor can see no section, so the nav has nothing to advertise. */
	public function test_nothing_is_visible_to_a_signed_out_visitor(): void {
		$this->assertCount( 0, ( new Section_Registry() )->visible_to( 0 ) );
	}

	/** @testdox A signed-in user sees every default section. */
	public function test_everything_is_visible_to_a_signed_in_user(): void {
		$user_id = self::factory()->user->create();

		$this->assertCount( 4, ( new Section_Registry() )->visible_to( $user_id ) );
	}

	/** @testdox A section gated on a capability is hidden from a user without it. */
	public function test_a_capability_hides_a_section(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->add(
				new Section(
					slug: 'admin-only',
					title: 'Admin only',
					menu_label: 'Admin only',
					block: 'third-party/admin-only',
					position: 50,
					capability: 'manage_options',
				)
			)
		);

		$visible = ( new Section_Registry() )->visible_to( $subscriber );

		$this->assertFalse( $visible->has( 'admin-only' ) );
		$this->assertTrue( $visible->has( 'my-access' ) );
	}

	/** @testdox A section gated on a capability is shown to a user who has it. */
	public function test_a_capability_shows_a_section_to_the_right_user(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		add_filter(
			'gatedmedia_account_sections',
			static fn( Section_Collection $sections ): Section_Collection => $sections->add(
				new Section(
					slug: 'admin-only',
					title: 'Admin only',
					menu_label: 'Admin only',
					block: 'third-party/admin-only',
					position: 50,
					capability: 'manage_options',
				)
			)
		);

		$this->assertTrue( ( new Section_Registry() )->visible_to( $admin )->has( 'admin-only' ) );
	}
}
