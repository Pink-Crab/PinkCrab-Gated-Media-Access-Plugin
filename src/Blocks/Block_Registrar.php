<?php
/**
 * Registering the built blocks.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Blocks;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Registers every block in build/blocks.
 *
 * Discovered rather than listed, because a block is already declared by its own block.json and a second list here would be a second place to forget.
 *
 * They are registered from **build**, not from source: block.json is copied there with its `file:` paths rewritten to the compiled assets, so registering the source copy would point the editor at files that do not exist.
 */
class Block_Registrar implements Hookable {

	private const BUILD_DIR = 'build/blocks';

	/**
	 * Registers the blocks on init.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers each built block.
	 *
	 * Silent when the build has not been run, so a checkout without `npm run build` degrades to missing blocks rather than a fatal.
	 */
	public function register(): void {
		$dir = GATEDMEDIA_DIR_PATH . self::BUILD_DIR;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = glob( $dir . '/*', GLOB_ONLYDIR );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_readable( $entry . '/block.json' ) ) {
				continue;
			}

			register_block_type( $entry );
		}
	}
}
