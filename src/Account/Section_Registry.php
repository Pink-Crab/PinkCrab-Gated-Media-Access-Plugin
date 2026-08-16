<?php
/**
 * The account area's section list.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use PinkCrab\Gated_Access\Account\Sections\Files_Section;
use PinkCrab\Gated_Access\Account\Sections\Orders_Section;
use PinkCrab\Gated_Access\Account\Sections\Profile_Section;
use PinkCrab\Gated_Access\Account\Sections\My_Access_Section;

/**
 * Builds the four sections we ship and hands the collection to
 * `gatedmedia_account_sections`.
 *
 * That filter is the only place third-party code adds UI. It adds sections; it
 * does not register services.
 *
 * Resolved once per request. The nav, the router and the renderer all read the
 * same collection, so a section cannot appear in one and be missing from
 * another.
 */
class Section_Registry {

	public const BLOCK_NAMESPACE = 'gated-media-access';

	/**
	 * Memoised. Building is cheap, but the filter is not ours and may not be.
	 *
	 * @var Section_Collection|null
	 */
	private ?Section_Collection $sections = null;

	/**
	 * Every section, ours and anyone else's, in nav order.
	 */
	public function all(): Section_Collection {
		if ( null !== $this->sections ) {
			return $this->sections;
		}

		/**
		 * Filters the account area's sections.
		 *
		 * Add one with `Section_Collection::add()`, which returns a new
		 * collection — return it, do not mutate in place.
		 *
		 * @param Section_Collection $sections The sections so far.
		 */
		$filtered = apply_filters( 'gatedmedia_account_sections', $this->defaults() );

		// A filter that returns the wrong thing takes the whole account area
		// down with it. Ours is a known-good fallback and a broken third-party
		// filter should not cost the user their downloads.
		$this->sections = $filtered instanceof Section_Collection
			? $filtered
			: $this->defaults();

		return $this->sections;
	}

	/**
	 * The sections this user may see, in nav order.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function visible_to( int $user_id ): Section_Collection {
		return $this->all()->visible_to( $user_id );
	}

	/**
	 * The four from ui-spec.md §7, each its own implementation of
	 * Account_Section.
	 *
	 * Classes rather than `Section` value objects, and deliberately: the
	 * interface is the contract we ask third parties to write against, so ours
	 * being the first things to implement it is what proves it sufficient.
	 * A page that needs behaviour of its own — Profile knowing whether it is
	 * incomplete — then has somewhere to put it.
	 *
	 * Positions are spaced in tens so a third party can land between any two
	 * without renumbering ours.
	 */
	private function defaults(): Section_Collection {
		return new Section_Collection(
			new My_Access_Section(),
			new Files_Section(),
			new Orders_Section(),
			new Profile_Section(),
		);
	}
}
