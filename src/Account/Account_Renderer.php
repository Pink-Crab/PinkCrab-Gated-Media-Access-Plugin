<?php
/**
 * The account shell.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Block;
use PinkCrab\Gated_Access\Support\View;

/**
 * Draws the shell every account view sits in, and fills its main column with the current section's block.
 *
 * **It returns markup, not a document.** The account area is a virtual page, so the theme renders its own header, navigation and footer and this is the content inside them.
 *
 * No top bar of our own, because the theme's header already is one. The tab strip is kept, since it is section navigation the theme does not provide.
 *
 * A block placed by an administrator on their own page gets the frame the same way, which is why the shell lives here rather than inside the blocks.
 */
class Account_Renderer {

	/**
	 * The whole shell, as a string.
	 *
	 * Returned rather than echoed, because it becomes a virtual post's content and has to exist before the theme starts rendering.
	 *
	 * @param Account_Section    $current  The section being viewed.
	 * @param Section_Collection $sections Everything in the nav, already filtered to this user.
	 * @param string             $detail   The optional second URL segment.
	 */
	public function markup( Account_Section $current, Section_Collection $sections, string $detail = '' ): string {
		$items = $this->nav_items( $current, $sections );

		return View::get(
			'account/shell',
			array(
				'nav'         => Block::render(
					'gated-media-access/account-nav',
					array(
						'items'   => $items,
						'variant' => 'sidebar',
						'label'   => __( 'Account', 'gated-media-access' ),
					)
				),
				'tabs'        => Block::render(
					'gated-media-access/account-nav',
					array(
						'items'   => $items,
						'variant' => 'tabs',
						'label'   => __( 'Account sections', 'gated-media-access' ),
					)
				),
				// My Access is drawn with no sub-line, and an empty description renders nothing rather than an empty paragraph.
				'description' => $current->description(),
				'content'     => $this->section_content( $current, $detail ),
			)
		);
	}

	/**
	 * The nav items, as data for the nav block.
	 *
	 * Built once and handed to both variants, so the sidebar and the tab strip cannot disagree about what exists or which one is current.
	 *
	 * @param Account_Section    $current  The section being viewed.
	 * @param Section_Collection $sections Everything in the nav.
	 * @return array<int, array<string, mixed>>
	 */
	private function nav_items( Account_Section $current, Section_Collection $sections ): array {
		$items = array();

		foreach ( $sections as $section ) {
			$items[] = array(
				'label'  => $section->menu_label(),
				'href'   => $this->url( $section ),
				'icon'   => $section->icon(),
				'active' => $section->slug() === $current->slug(),
			);
		}

		return $items;
	}

	/**
	 * The section's block, rendered through the block itself.
	 *
	 * `do_blocks()` on a block comment rather than a direct render callback, so this route and an editor-placed block go through the same code and produce the same markup.
	 *
	 * @param Account_Section $current The section being viewed.
	 * @param string          $detail  The optional second URL segment.
	 */
	private function section_content( Account_Section $current, string $detail ): string {
		$block = $current->block();

		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
			return $this->missing_block( $block );
		}

		$attributes = '' === $detail
			? ''
			: ' ' . (string) wp_json_encode( array( 'detail' => $detail ) );

		return do_blocks( sprintf( '<!-- wp:%s%s /-->', $block, $attributes ) );
	}

	/**
	 * A section naming a block that is not registered.
	 *
	 * Only reachable through a third party's own mistake, so it names the block rather than failing silently, and stays inside the shell so the rest of the account area still works.
	 *
	 * @param string $block The block name that is missing.
	 */
	private function missing_block( string $block ): string {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return '';
		}

		return View::get(
			'account/missing-block',
			array( 'block' => $block )
		);
	}

	/**
	 * The URL for a section.
	 *
	 * The first section answers at the bare route as well as at its own slug, and is linked at its slug so the nav's URLs and the address bar agree.
	 *
	 * @param Account_Section $section The section to link to.
	 */
	private function url( Account_Section $section ): string {
		return Account_Url::section( $section->slug() );
	}
}
