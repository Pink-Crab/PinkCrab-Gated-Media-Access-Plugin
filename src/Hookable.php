<?php
/**
 * A service that registers hooks.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access;

use PinkCrab\Loader\Hook_Loader;

/**
 * Implemented by any service that wants hooks.
 *
 * The boot loop builds every service in its list, then calls this on the ones
 * that have it. Hooks live next to the code that runs them, and are attached
 * to WordPress in one pass afterwards.
 */
interface Hookable {

	/**
	 * Registers this service's hooks against the loader.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void;
}
