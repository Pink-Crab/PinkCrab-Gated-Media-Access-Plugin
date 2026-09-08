<?php
/**
 * The Files section.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account\Sections;

use PinkCrab\Gated_Access\Account\Account_Section;

/**
 * Everything the person can download.
 *
 * Distinct from My Access, which also lists files: this is the view where downloading is the point, so it carries a search and a type filter and gives each row a Download button rather than a text link.
 */
class Files_Section implements Account_Section {

	/**
	 * The URL segment.
	 */
	public function slug(): string {
		return 'files';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Files', 'gated-media-access' );
	}

	/**
	 * The line beneath the title.
	 */
	public function description(): string {
		return __( 'Everything you can download.', 'gated-media-access' );
	}

	/**
	 * The nav label.
	 */
	public function menu_label(): string {
		return __( 'Files', 'gated-media-access' );
	}

	/**
	 * The sprite symbol.
	 */
	public function icon(): string {
		return 'i-files';
	}

	/**
	 * The block that draws it.
	 */
	public function block(): string {
		return 'gated-media-access/files';
	}

	/**
	 * Second in the nav.
	 */
	public function position(): int {
		return 20;
	}

	/**
	 * Anyone signed in.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool {
		return $user_id > 0;
	}
}
