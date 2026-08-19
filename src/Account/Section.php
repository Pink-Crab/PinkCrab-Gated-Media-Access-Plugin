<?php
/**
 * The ordinary implementation of an account section.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

/**
 * A section described by its values rather than by behaviour.
 *
 * Our four are built from this, and it is public so a third party adding one
 * does not have to write a class to do it:
 *
 *     add_filter(
 *         'gatedmedia_account_sections',
 *         function ( Section_Collection $sections ): Section_Collection {
 *             return $sections->add(
 *                 new Section(
 *                     slug:       'subscriptions',
 *                     title:      __( 'Subscriptions', 'my-plugin' ),
 *                     menu_label: __( 'Subscriptions', 'my-plugin' ),
 *                     block:      'my-plugin/subscriptions',
 *                     position:   50,
 *                 )
 *             );
 *         }
 *     );
 *
 * Anything needing real behaviour — visibility that depends on more than a
 * capability, a title that varies — implements Account_Section directly. This
 * is the shortcut, not the only way.
 */
final class Section implements Account_Section {

	/**
	 * Describes a section by its values.
	 *
	 * @param string      $slug        URL segment. Unique, and stable across releases.
	 * @param string      $title       Rendered as the view's h1.
	 * @param string      $menu_label  The nav label.
	 * @param string      $block       Block that draws it, `namespace/name`.
	 * @param int         $position    Nav order, lower first.
	 * @param string      $description Sub-line under the title. Empty is valid.
	 * @param string      $icon        Sprite symbol id.
	 * @param string|null $capability  Required capability, or null for "signed in".
	 */
	public function __construct(
		private string $slug,
		private string $title,
		private string $menu_label,
		private string $block,
		private int $position = 100,
		private string $description = '',
		private string $icon = '',
		private ?string $capability = null,
	) {
	}

	/**
	 * The URL segment.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * The page title, rendered as the view's h1.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * The line beneath the title. Empty is a real answer.
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * The nav label.
	 */
	public function menu_label(): string {
		return $this->menu_label;
	}

	/**
	 * The sprite symbol id.
	 */
	public function icon(): string {
		return $this->icon;
	}

	/**
	 * The block that draws this section.
	 */
	public function block(): string {
		return $this->block;
	}

	/**
	 * Sort order in the nav, lower first.
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * Signed in by default; a capability when one was named.
	 *
	 * The account area is a person's own record, so there is no version of this
	 * that a signed-out visitor should see.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool {
		if ( 0 === $user_id ) {
			return false;
		}

		if ( null === $this->capability ) {
			return true;
		}

		return user_can( $user_id, $this->capability );
	}
}
