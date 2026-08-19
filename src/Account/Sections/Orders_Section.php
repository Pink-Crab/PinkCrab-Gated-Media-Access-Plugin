<?php
/**
 * The Orders section.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account\Sections;

use PinkCrab\Gated_Access\Account\Account_Section;

/**
 * The record of what was taken and when — ui-spec.md §7.3.
 *
 * **This is not a shop.** It is a history, and §7.4's order detail hangs off
 * it at `/account/orders/{id}` — which is what the route's second captured
 * segment exists for.
 */
class Orders_Section implements Account_Section {

	/**
	 * The URL segment.
	 */
	public function slug(): string {
		return 'orders';
	}

	/**
	 * The page title.
	 */
	public function title(): string {
		return __( 'Orders', 'gated-media-access' );
	}

	/**
	 * The line beneath the title.
	 */
	public function description(): string {
		return __( 'What you have taken, and when.', 'gated-media-access' );
	}

	/**
	 * The nav label.
	 */
	public function menu_label(): string {
		return __( 'Orders', 'gated-media-access' );
	}

	/**
	 * The sprite symbol.
	 */
	public function icon(): string {
		return 'i-orders';
	}

	/**
	 * The block that draws it.
	 */
	public function block(): string {
		return 'gated-media-access/orders';
	}

	/**
	 * Third in the nav.
	 */
	public function position(): int {
		return 30;
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
