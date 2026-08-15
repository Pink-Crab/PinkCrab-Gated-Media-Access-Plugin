<?php
/**
 * One section of the account area.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

/**
 * A section is a page of the account area: My Access, Files, Orders, Profile —
 * and whatever a third party adds.
 *
 * Implementing this is the whole of the contract. An implementation carries its
 * own URL segment, how it is labelled, and which block draws it; the account
 * area does the rest. There is nothing else to register: no rewrite rule of
 * your own, no query var, no menu call.
 *
 * Instances are collected into a Section_Collection and passed through the
 * `gatedmedia_account_sections` filter, which is the only place third-party
 * code adds UI. Build yours however you like — the container, a factory, or
 * `new` — the collection only cares that it implements this.
 *
 * A section always renders inside the account shell: the sidebar, the tab strip
 * and the page header are drawn for you and `block()` fills the main column.
 * Something that wants the whole page is not a section, it is a page, and it
 * wants a normal WordPress route instead.
 */
interface Account_Section {

	/**
	 * The URL segment, and the identity of this section everywhere else.
	 *
	 * Appears as `/{account}/{slug}`. Must be unique across the collection and
	 * must survive `sanitize_title()` unchanged — anything else cannot be
	 * routed to.
	 */
	public function slug(): string;

	/**
	 * The page title. Rendered as the view's `h1`, of which there is exactly
	 * one per page.
	 */
	public function title(): string;

	/**
	 * The line beneath the title saying what the page is for.
	 *
	 * My Access is drawn with no sub-line at all, so an empty string is a real
	 * answer and not a missing one.
	 */
	public function description(): string;

	/**
	 * The nav label. Usually shorter than the title, because it sits in a 256px
	 * column and in a scrolling strip on a phone.
	 */
	public function menu_label(): string;

	/**
	 * The icon, as a symbol id in the sprite — `i-files`, `i-orders`.
	 *
	 * Third-party sections may name a symbol we do not ship, in which case no
	 * icon renders and the label stands alone. That is deliberate: a missing
	 * icon is not worth a broken page.
	 */
	public function icon(): string;

	/**
	 * The block that draws this section, as `namespace/name`.
	 *
	 * The route renders this block and an editor placing it on their own page
	 * renders the same one, which is what keeps the two routes from drifting.
	 * The block must be registered by the time the account area renders.
	 */
	public function block(): string;

	/**
	 * Sort order in the nav. Lower is earlier.
	 *
	 * Ours are spaced in tens so a third party can land between two of them
	 * without having to renumber anything.
	 */
	public function position(): int;

	/**
	 * Whether this user may see the section at all.
	 *
	 * False hides it from the nav and 404s its URL — the two have to agree, or
	 * the nav advertises a page that refuses to load.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function is_visible( int $user_id ): bool;
}
