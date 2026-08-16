<?php
/**
 * The Profile section.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account\Sections;

use PinkCrab\Gated_Access\Account\Account_Section;
use PinkCrab\Gated_Access\Account\Profile_Writer;

/**
 * The one profile shape, and the only account view that writes anything —
 * ui-spec.md §7.5.
 *
 * The fields and the save handler live in Profile_Writer, because the brief
 * requires all three account-creation routes to fill the same fields and that
 * only holds while there is one definition of what they are.
 */
class Profile_Section implements Account_Section {

	/**
	 * The URL segment.
	 */
	public function slug(): string {
		return 'profile';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Profile', 'gated-media-access' );
	}

	/**
	 * The line beneath the title.
	 */
	public function description(): string {
		return __( 'Your details.', 'gated-media-access' );
	}

	/**
	 * The nav label.
	 */
	public function menu_label(): string {
		return __( 'Profile', 'gated-media-access' );
	}

	/**
	 * The sprite symbol.
	 */
	public function icon(): string {
		return 'i-profile';
	}

	/**
	 * The block that draws it.
	 */
	public function block(): string {
		return 'gated-media-access/profile';
	}

	/**
	 * Last in the nav.
	 */
	public function position(): int {
		return 40;
	}

	/**
	 * Anyone signed in.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool {
		return $user_id > 0;
	}

	/**
	 * Whether this person still has required fields to fill.
	 *
	 * Not part of Account_Section — it is this section's own behaviour, and
	 * having somewhere to put it is the reason these are classes rather than
	 * eight arguments to a shared value object.
	 *
	 * @param int $user_id Whose profile.
	 */
	public function is_incomplete( int $user_id ): bool {
		return array() !== Profile_Writer::missing_for( $user_id );
	}
}
