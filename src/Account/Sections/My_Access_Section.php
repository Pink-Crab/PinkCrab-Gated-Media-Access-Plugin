<?php
/**
 * The My Access section.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account\Sections;

use PinkCrab\Gated_Access\Account\Account_Section;

/**
 * The landing view for the account area.
 *
 * Everything a person holds: groups, posts and files, with the expiry on each.
 *
 * It is first in the nav, which makes it what the bare `/account` route lands on, because `Section_Collection::first()` sorts by position, so the ordering here is the routing decision as well as the visual one.
 */
class My_Access_Section implements Account_Section {

	/**
	 * The URL segment.
	 */
	public function slug(): string {
		return 'my-access';
	}

	/**
	 * The page title. Rendered by the theme as the page's one h1.
	 */
	public function title(): string {
		return __( 'My Access', 'gated-media-access' );
	}

	/**
	 * Drawn with no sub-line at all, so the empty string is deliberate.
	 */
	public function description(): string {
		return '';
	}

	/**
	 * The nav label.
	 */
	public function menu_label(): string {
		return __( 'My Access', 'gated-media-access' );
	}

	/**
	 * The sprite symbol.
	 */
	public function icon(): string {
		return 'i-access';
	}

	/**
	 * The block that draws it.
	 */
	public function block(): string {
		return 'gated-media-access/my-access';
	}

	/**
	 * First, so the bare account route lands here.
	 */
	public function position(): int {
		return 10;
	}

	/**
	 * Anyone signed in, because the account area is a person's own record.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool {
		return $user_id > 0;
	}
}
