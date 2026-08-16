<?php
/**
 * The icon sprite.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Blocks;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Puts the sprite on any page that draws one of our blocks.
 *
 * Nearly every §6 component names an icon — the expiry has one per state, the
 * status pill one per value, notices one per kind. Printing the sprite only on
 * the account route meant a component placed anywhere else lost every one of
 * them, silently: `<use href="#i-files">` with no matching symbol renders
 * nothing at all rather than failing.
 *
 * It is inlined rather than referenced as an external file because `<use>`
 * across documents is not reliably supported, and a nav whose icons work in
 * one browser and not another is worse than a kilobyte of markup.
 *
 * Printed once, and only when something asked for it — a page with none of our
 * blocks on it gets nothing.
 */
class Sprite implements Hookable {

	/**
	 * Whether a block of ours has rendered on this request.
	 *
	 * @var bool
	 */
	private bool $needed = false;

	/**
	 * Whether it has already been printed.
	 *
	 * @var bool
	 */
	private bool $printed = false;

	/**
	 * Watches for our blocks, and prints in the footer.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'render_block', array( $this, 'note_block' ), 10, 2 );
		$loader->action( 'wp_footer', array( $this, 'print_sprite' ), 5 );
	}

	/**
	 * Notes that one of ours rendered, without touching its output.
	 *
	 * @param string               $content The rendered block.
	 * @param array<string, mixed> $block   The parsed block.
	 * @return string The block, unchanged.
	 */
	public function note_block( string $content, array $block ): string {
		$name = isset( $block['blockName'] ) && is_string( $block['blockName'] )
			? $block['blockName']
			: '';

		if ( str_starts_with( $name, 'gated-media-access/' ) ) {
			$this->needed = true;
		}

		return $content;
	}

	/**
	 * Marks the sprite as wanted, for markup we compose ourselves.
	 *
	 * The account shell draws its navigation before the loop, so nothing has
	 * gone through `render_block` by the time the footer is reached.
	 */
	public function require_sprite(): void {
		$this->needed = true;
	}

	/**
	 * Prints the symbols, once.
	 */
	public function print_sprite(): void {
		if ( ! $this->needed || $this->printed ) {
			return;
		}

		$path = GATEDMEDIA_DIR_PATH . 'assets/icons.svg';

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, not a remote request.
		$sprite = file_get_contents( $path );

		if ( false === $sprite ) {
			return;
		}

		$this->printed = true;

		echo wp_kses(
			$sprite,
			array(
				'svg'    => array(
					'xmlns'       => true,
					'width'       => true,
					'height'      => true,
					'style'       => true,
					'aria-hidden' => true,
					'focusable'   => true,
				),
				'defs'   => array(),
				'symbol' => array(
					'id'      => true,
					'viewbox' => true,
				),
				'path'   => array(
					'fill' => true,
					'd'    => true,
				),
			)
		);
	}
}
