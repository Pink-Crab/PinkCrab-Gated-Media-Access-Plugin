<?php
/**
 * The restriction wiring.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Access;

use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Owns the marker term and the two automatic behaviours from architecture.md
 * §6: any group term applied to content applies the marker too, and a group
 * term applied to an attachment restricts its file through the dependency.
 *
 * Removal does neither — the brief's deliberate asymmetry. Unrestricting
 * rewrites post content across the site, so it stays a manual administrator
 * act.
 *
 * The marker is one term, slug `restricted` (the docs' `is_gated`, renamed
 * under the access/groups vocabulary), created the first time something needs
 * it and hidden from every term list via `list_terms_exclusions`. Readers
 * that genuinely need it pass the INCLUDE_MARKER query arg; membership reads
 * (`get_objects_in_term`) query the database directly and never see the
 * exclusion.
 */
class Restriction implements Hookable {

	/** The marker term's slug. */
	public const MARKER_SLUG = 'restricted';

	/**
	 * Term-query argument that lets a caller see the marker.
	 *
	 * Our own lookups pass it; everything else — the admin list table, the
	 * editor's panel, front-of-site term queries — never does, so the marker
	 * stays out of sight.
	 */
	public const INCLUDE_MARKER = 'gatedmedia_include_marker';

	/**
	 * Watches term assignment and hides the marker from term queries.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'set_object_terms', array( $this, 'apply_restriction' ), 6 );
		$loader->filter( 'list_terms_exclusions', array( $this, 'hide_marker' ), 3 );
	}

	/**
	 * Applies the marker (and file restriction) when a group term lands.
	 *
	 * Fires on every `wp_set_object_terms` call. Our own marker append below
	 * re-fires it with the marker as the only term, which the group check
	 * reads as "no group term applied" — that is what ends the recursion.
	 *
	 * @param int               $object_id  The object the terms were set on.
	 * @param array<int|string> $terms      The terms as passed in (unused; tt_ids is canonical).
	 * @param array<int|string> $tt_ids     Term taxonomy IDs set by this call.
	 * @param string            $taxonomy   The taxonomy the terms belong to.
	 * @param bool              $append     Whether the terms were appended.
	 * @param array<int|string> $old_tt_ids Term taxonomy IDs before the call.
	 */
	public function apply_restriction( int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids ): void {
		if ( Access_Taxonomy::TAXONOMY !== $taxonomy || array() === $this->group_tt_ids( $tt_ids ) ) {
			return;
		}

		$marker_id = $this->ensure_marker();

		if ( 0 !== $marker_id ) {
			wp_set_object_terms( $object_id, array( $marker_id ), Access_Taxonomy::TAXONOMY, true );
		}

		// The dependency's own guard makes this a no-op on an already
		// restricted file.
		if ( 'attachment' === get_post_type( $object_id ) ) {
			rmfa_set_file_as_protected( $object_id );
		}
	}

	/**
	 * Keeps the marker out of term queries that did not ask for it.
	 *
	 * @param string              $exclusions The NOT IN clause so far ('' or SQL).
	 * @param array<string,mixed> $args       The term query arguments.
	 * @param array<string>|null  $taxonomies The taxonomies being queried.
	 */
	public function hide_marker( string $exclusions, array $args, ?array $taxonomies ): string {
		if ( ! is_array( $taxonomies ) || ! in_array( Access_Taxonomy::TAXONOMY, $taxonomies, true ) ) {
			return $exclusions;
		}

		if ( (bool) ( $args[ self::INCLUDE_MARKER ] ?? false ) ) {
			return $exclusions;
		}

		// Hidden from lists, not from direct lookups. A query naming a term —
		// by ID (term_exists), slug or name (get_term_by) — gets the truth;
		// hiding the marker there makes wp_set_object_terms() silently drop
		// it as a non-existent term ID.
		foreach ( array( 'include', 'slug', 'name' ) as $direct ) {
			$value = $args[ $direct ] ?? '';

			if ( '' !== $value && array() !== $value ) {
				return $exclusions;
			}
		}

		$marker = $this->marker();

		if ( null === $marker ) {
			return $exclusions;
		}

		$clause = 't.term_id <> ' . (int) $marker->term_id;

		return '' === $exclusions ? $clause : $exclusions . ' AND ' . $clause;
	}

	/**
	 * The marker term, if it exists yet.
	 *
	 * Uncached on purpose: core's term query cache already covers the lookup,
	 * and an instance memo would go stale the moment a test transaction rolls
	 * back under it.
	 */
	public function marker(): ?WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'           => Access_Taxonomy::TAXONOMY,
				'slug'               => self::MARKER_SLUG,
				'hide_empty'         => false,
				'number'             => 1,
				self::INCLUDE_MARKER => true,
			)
		);

		if ( is_array( $terms ) && array() !== $terms && $terms[0] instanceof WP_Term ) {
			return $terms[0];
		}

		return null;
	}

	/**
	 * The marker term's ID, creating the term on first need.
	 *
	 * @return int The term ID, or 0 when creation failed outright.
	 */
	public function ensure_marker(): int {
		$existing = $this->marker();

		if ( null !== $existing ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term(
			__( 'Restricted', 'gated-media-access' ),
			Access_Taxonomy::TAXONOMY,
			array( 'slug' => self::MARKER_SLUG )
		);

		if ( is_wp_error( $created ) ) {
			// Lost a race to another request: look it up again.
			$existing = $this->marker();

			return null === $existing ? 0 : (int) $existing->term_id;
		}

		return (int) $created['term_id'];
	}

	/**
	 * The applied tt_ids with the marker's removed — what's left is groups.
	 *
	 * @param array<int|string> $tt_ids Term taxonomy IDs from the assignment.
	 * @return array<int>
	 */
	private function group_tt_ids( array $tt_ids ): array {
		$tt_ids = array_map( 'intval', $tt_ids );
		$marker = $this->marker();

		if ( null === $marker ) {
			return $tt_ids;
		}

		return array_diff( $tt_ids, array( (int) $marker->term_taxonomy_id ) );
	}
}
