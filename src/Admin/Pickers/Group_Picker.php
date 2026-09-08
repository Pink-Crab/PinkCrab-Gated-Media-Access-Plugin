<?php
/**
 * Picking a group.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * Search-as-you-type over groups. The hidden input carries the group's UUID.
 */
final class Group_Picker extends Search_Picker {

	/**
	 * The groups endpoint.
	 */
	protected function endpoint(): string {
		return 'gatedmedia_search_groups';
	}

	/**
	 * What to type.
	 */
	protected function placeholder(): string {
		return __( 'Start typing a group name…', 'gated-media-access' );
	}
}
