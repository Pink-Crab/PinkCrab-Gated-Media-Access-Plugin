<?php
/**
 * A typed set of account sections.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use IteratorAggregate;
use ArrayIterator;
use Traversable;
use Countable;

/**
 * Holds Account_Section instances, keyed by slug, in nav order.
 *
 * It exists so `gatedmedia_account_sections` passes something typed rather than a bare array, since a filter returning an array of anything at all is how a third party's mistake becomes our fatal error three hooks later.
 *
 * The only way in is `add()`, which takes nothing else, and it returns a new collection, so a filter that forgets to return is caught by the type on the other side instead of quietly mutating ours.
 *
 * Iteration is by position rather than slug, so the keys a consumer sees are 0..n in nav order.
 *
 * @implements IteratorAggregate<int, Account_Section>
 */
final class Section_Collection implements IteratorAggregate, Countable {

	/**
	 * Sections, keyed by slug.
	 *
	 * @var array<string, Account_Section>
	 */
	private array $sections;

	/**
	 * Builds a collection from any number of sections.
	 *
	 * @param Account_Section ...$sections Any number, in any order.
	 */
	public function __construct( Account_Section ...$sections ) {
		$this->sections = array();

		foreach ( $sections as $section ) {
			$this->sections[ $section->slug() ] = $section;
		}
	}

	/**
	 * Returns a copy with one more section in it.
	 *
	 * A slug that already exists is replaced, which is how a site swaps our Profile for its own without removing ours first.
	 *
	 * @param Account_Section $section The section to add.
	 */
	public function add( Account_Section $section ): self {
		$clone                               = clone $this;
		$clone->sections[ $section->slug() ] = $section;

		return $clone;
	}

	/**
	 * Returns a copy without the named section.
	 *
	 * @param string $slug The section to drop.
	 */
	public function remove( string $slug ): self {
		$clone = clone $this;
		unset( $clone->sections[ $slug ] );

		return $clone;
	}

	/**
	 * The section for a slug, or null when there is none.
	 *
	 * @param string $slug The section to look up.
	 */
	public function get( string $slug ): ?Account_Section {
		return $this->sections[ $slug ] ?? null;
	}

	/**
	 * Whether the collection holds a section with this slug.
	 *
	 * @param string $slug The section to look for.
	 */
	public function has( string $slug ): bool {
		return isset( $this->sections[ $slug ] );
	}

	/**
	 * Only the sections this user may see, in nav order.
	 *
	 * The nav and the router both read this, so a hidden section cannot be advertised in one and refused by the other.
	 *
	 * @param int $user_id The user viewing, 0 when signed out.
	 */
	public function visible_to( int $user_id ): self {
		$visible = array_filter(
			$this->sections,
			static fn( Account_Section $section ): bool => $section->is_visible( $user_id )
		);

		return new self( ...array_values( $visible ) );
	}

	/**
	 * The first section, which is where `/{account}` with no segment lands.
	 */
	public function first(): ?Account_Section {
		$sorted = $this->sorted();

		return array_shift( $sorted );
	}

	/**
	 * All sections in nav order.
	 *
	 * @return array<int, Account_Section>
	 */
	public function sorted(): array {
		$sections = array_values( $this->sections );

		usort(
			$sections,
			static fn( Account_Section $first, Account_Section $second ): int => $first->position() <=> $second->position()
		);

		return $sections;
	}

	/**
	 * Every slug in the collection.
	 *
	 * @return array<int, string>
	 */
	public function slugs(): array {
		return array_keys( $this->sections );
	}

	/**
	 * Iterates in nav order rather than insertion order, since forgetting to sort is an easy bug to miss.
	 *
	 * @return Traversable<int, Account_Section>
	 */
	public function getIterator(): Traversable {
		return new ArrayIterator( $this->sorted() );
	}

	/**
	 * How many sections the collection holds.
	 */
	public function count(): int {
		return count( $this->sections );
	}
}
