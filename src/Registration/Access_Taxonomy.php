<?php
/**
 * The access taxonomy.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

use WP_Term;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * One taxonomy for both jobs (architecture.md §6): it carries a term marking
 * content as restricted, and one term per group.
 *
 * Named `gatedmedia_access`, not the specification's `gatedmedia_gate` — a
 * naming decision taken in review: "access" and "groups" are the terms, never
 * "gate". It shares the string with the access post type deliberately; post
 * types and taxonomies live in separate registries, and with `query_var` and
 * `rewrite` off on both sides nothing collides.
 *
 * This class registers the taxonomy only. No terms are created, no group
 * identity is minted, and neither of the two `set_object_terms` behaviours —
 * a group term applying the restricted marker, a group term on an attachment
 * calling `rmfa_set_file_as_protected()` — is wired here. Those belong to the
 * restriction step, which is where group identity is settled too (decided:
 * human slug, UUID in term meta, because the core taxonomy screens write
 * `sanitize_title( $name )` as the slug and a UUID there is unreadable).
 */
class Access_Taxonomy implements Hookable {

	/** The taxonomy name. */
	public const TAXONOMY = 'gatedmedia_access';

	/**
	 * Term meta: the group's stable identity.
	 *
	 * Access records point at this, not the term id or slug, so renaming or
	 * re-slugging a group never orphans anyone's access.
	 */
	public const UUID_META = 'gatedmedia_uuid';

	/**
	 * Registers on init, after the post types; mints identity on creation.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
		// created_{$taxonomy} — fires for terms made anywhere, including the
		// core screens, which is what keeps this UI-free.
		$loader->action( 'created_' . self::TAXONOMY, array( $this, 'mint_uuid' ) );
	}

	/**
	 * The restrictable post types — what the taxonomy registers against, and
	 * where the item-side admin surfaces appear.
	 *
	 * @return array<int, string>
	 */
	public static function object_types(): array {
		/**
		 * The post types the access taxonomy attaches to.
		 *
		 * @param array<int, string> $object_types Defaults to post, page and attachment.
		 */
		return (array) apply_filters(
			'gatedmedia_access_object_types',
			array( 'post', 'page', 'attachment' )
		);
	}

	/**
	 * Registers the taxonomy against the restrictable types.
	 */
	public function register(): void {
		$object_types = self::object_types();

		register_taxonomy(
			self::TAXONOMY,
			$object_types,
			array(
				// "Groups" is the docs' name for these screens — specification.md
				// §2 and the architecture.md admin table both use it.
				'labels'            => array(
					'name'          => __( 'Groups', 'gated-media-access' ),
					'singular_name' => __( 'Group', 'gated-media-access' ),
					'search_items'  => __( 'Search Groups', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Group', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Group', 'gated-media-access' ),
				),
				'public'            => false,
				'show_ui'           => true,
				// The block editor's taxonomy panel needs it.
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'rewrite'           => false,
				'capabilities'      => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'manage_categories',
				),
			)
		);
	}

	/**
	 * Gives a new term its UUID.
	 *
	 * @param int $term_id The term just created.
	 */
	public function mint_uuid( int $term_id ): void {
		if ( '' === (string) get_term_meta( $term_id, self::UUID_META, true ) ) {
			update_term_meta( $term_id, self::UUID_META, wp_generate_uuid4() );
		}
	}

	/**
	 * The term's UUID, minting one if it has none.
	 *
	 * The backfill covers terms created before the hook existed, or inserted
	 * directly — identity is settled the first time anything asks.
	 *
	 * @param int $term_id The term.
	 */
	public function uuid_for( int $term_id ): string {
		$uuid = (string) get_term_meta( $term_id, self::UUID_META, true );

		if ( '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_term_meta( $term_id, self::UUID_META, $uuid );
		}

		return $uuid;
	}

	/**
	 * The group holding this UUID, if any.
	 *
	 * @param string $uuid The identity to look up.
	 */
	public function find_group( string $uuid ): ?WP_Term {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				'meta_key'   => self::UUID_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row by unique value; groups number in the tens.
				'meta_value' => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		if ( is_array( $terms ) && array() !== $terms && $terms[0] instanceof WP_Term ) {
			return $terms[0];
		}

		return null;
	}
}
