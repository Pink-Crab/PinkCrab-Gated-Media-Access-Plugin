<?php
/**
 * Composing blocks from PHP.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

/**
 * Renders a registered block with attributes, from server code.
 *
 * The account area's views are assembled at render time, so their contents cannot be authored in the editor, but they are still built from the same component blocks an editor would place. A block comment is composed and run through `do_blocks()`, the ordinary rendering path, so a component behaves identically whichever way it got onto the page.
 *
 * Going through `do_blocks()` also means each component keeps the `render_block_gated-media-access/<name>` filter core gives every block, so a site can change how a Row draws without us inventing a filter for it.
 */
class Block {

	/**
	 * Renders one block.
	 *
	 * @param string               $name       Block name, `namespace/name`.
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $inner      Already-rendered inner block markup.
	 */
	public static function render( string $name, array $attributes = array(), string $inner = '' ): string {
		$encoded = array() === $attributes
			? ''
			: ' ' . (string) wp_json_encode( $attributes );

		$markup = '' === $inner
			? sprintf( '<!-- wp:%s%s /-->', $name, $encoded )
			: sprintf( '<!-- wp:%1$s%2$s -->%3$s<!-- /wp:%1$s -->', $name, $encoded, $inner );

		return do_blocks( $markup );
	}

	/**
	 * Renders a list of blocks and joins them.
	 *
	 * Each entry is `[ name, attributes, inner ]`, the last two optional.
	 * Entries not shaped that way are skipped rather than fataling, since a third-party section is the likely caller.
	 *
	 * @param array<int, array{0: string, 1?: array<string, mixed>, 2?: string}> $blocks Blocks to render.
	 */
	public static function render_all( array $blocks ): string {
		$out = '';

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block[0] ) || ! is_string( $block[0] ) ) {
				continue;
			}

			$out .= self::render(
				$block[0],
				isset( $block[1] ) && is_array( $block[1] ) ? $block[1] : array(),
				isset( $block[2] ) && is_string( $block[2] ) ? $block[2] : ''
			);
		}

		return $out;
	}
}
