<?php
/**
 * Picking a user.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin\Pickers;

/**
 * Search-as-you-type over users, by name, login or email.
 */
final class User_Picker extends Search_Picker {

	/**
	 * The users endpoint.
	 */
	protected function endpoint(): string {
		return 'gatedmedia_search_users';
	}

	/**
	 * What to type.
	 */
	protected function placeholder(): string {
		return __( 'Start typing a name, login or email…', 'gated-media-access' );
	}
}
