<?php
/**
 * The account area's section list.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

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
	 * The four from ui-spec.md §7, spaced in tens so a third party can land
	 * between any two without renumbering ours.
	 */
	private function defaults(): Section_Collection {
		return new Section_Collection(
			new Section(
				slug: 'my-access',
				title: __( 'My Access', 'gated-media-access' ),
				menu_label: __( 'My Access', 'gated-media-access' ),
				block: self::BLOCK_NAMESPACE . '/my-access',
				position: 10,
				// §7.1 is drawn with no sub-line. Deliberately empty.
				description: '',
				icon: 'i-access',
			),
			new Section(
				slug: 'files',
				title: __( 'Files', 'gated-media-access' ),
				menu_label: __( 'Files', 'gated-media-access' ),
				block: self::BLOCK_NAMESPACE . '/files',
				position: 20,
				description: __( 'Everything you can download.', 'gated-media-access' ),
				icon: 'i-files',
			),
			new Section(
				slug: 'orders',
				title: __( 'Orders', 'gated-media-access' ),
				menu_label: __( 'Orders', 'gated-media-access' ),
				block: self::BLOCK_NAMESPACE . '/orders',
				position: 30,
				description: __( 'What you have taken, and when.', 'gated-media-access' ),
				icon: 'i-orders',
			),
			new Section(
				slug: 'profile',
				title: __( 'Profile', 'gated-media-access' ),
				menu_label: __( 'Profile', 'gated-media-access' ),
				block: self::BLOCK_NAMESPACE . '/profile',
				position: 40,
				description: __( 'Your details.', 'gated-media-access' ),
				icon: 'i-profile',
			),
		);
	}
}
