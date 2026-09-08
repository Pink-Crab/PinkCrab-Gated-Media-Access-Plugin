<?php
/**
 * Picking a post.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * Search-as-you-type over the restrictable post types, files excluded because `File_Picker` is theirs.
 */
final class Post_Picker extends Search_Picker {

	/**
	 * The posts endpoint.
	 */
	protected function endpoint(): string {
		return 'gatedmedia_search_posts';
	}

	/**
	 * What to type.
	 */
	protected function placeholder(): string {
		return __( 'Start typing a title…', 'gated-media-access' );
	}
}
