<?php
/**
 * Picking a file.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * Search-as-you-type over attachments, the same control as `Post_Picker` against its own endpoint, with no media modal and nothing required of the screen.
 */
final class File_Picker extends Search_Picker {

	/**
	 * The files endpoint.
	 */
	protected function endpoint(): string {
		return 'gatedmedia_search_files';
	}

	/**
	 * What to type.
	 */
	protected function placeholder(): string {
		return __( 'Start typing a file name…', 'gated-media-access' );
	}
}
