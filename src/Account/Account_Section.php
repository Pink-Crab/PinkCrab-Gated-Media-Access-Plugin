<?php
/**
 * One section of the account area.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

/**
 * A section is a page of the account area: My Access, Files, Orders, Profile, and whatever a third party adds.
 *
 * Implementing this is the whole of the contract: an implementation carries its own URL segment, how it is labelled, and which block draws it, and there is nothing else to register, no rewrite rule, no query var, no menu call.
 *
 * Instances are collected into a `Section_Collection` and passed through the `gatedmedia_account_sections` filter, the only place third-party code adds UI, and the collection only cares that a section implements this.
 *
 * A section always renders inside the account shell, where the sidebar, the tab strip and the page header are drawn for it and `block()` fills the main column.
 *
 * Something that wants the whole page is not a section, and wants an ordinary WordPress route instead.
 */
interface Account_Section {

	/**
	 * The URL segment, and the identity of this section everywhere else.
	 *
	 * Appears as `/{account}/{slug}`, and must be unique across the collection and survive `sanitize_title()` unchanged, or it cannot be routed to.
	 */
	public function slug(): string;

	/**
	 * The page title, rendered as the view's `h1`, of which there is exactly one per page.
	 */
	public function title(): string;

	/**
	 * The line beneath the title saying what the page is for.
	 *
	 * My Access is drawn with no sub-line at all, so an empty string is a real answer rather than a missing one.
	 */
	public function description(): string;

	/**
	 * The nav label, usually shorter than the title, because it sits in a 256px column and in a scrolling strip on a phone.
	 */
	public function menu_label(): string;

	/**
	 * The icon, as a symbol id in the sprite, such as `i-files` or `i-orders`.
	 *
	 * A third-party section may name a symbol we do not ship, in which case no icon renders and the label stands alone, because a missing icon is not worth a broken page.
	 */
	public function icon(): string;

	/**
	 * The block that draws this section, as `namespace/name`.
	 *
	 * The route renders this block and an editor placing it on their own page renders the same one, and it must be registered by the time the account area renders.
	 */
	public function block(): string;

	/**
	 * Sort order in the nav, lower being earlier.
	 *
	 * Ours are spaced in tens, so a third party can land between two of them without renumbering anything.
	 */
	public function position(): int;

	/**
	 * Whether this user may see the section at all.
	 *
	 * False hides it from the nav and 404s its URL, or the nav advertises a page that refuses to load.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool;
}
