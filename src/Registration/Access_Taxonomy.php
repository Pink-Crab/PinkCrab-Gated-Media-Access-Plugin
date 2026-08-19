<?php
/**
 * The access taxonomy.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

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
	 * Registers on init, after the post types.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the taxonomy against the restrictable types.
	 */
	public function register(): void {
		/**
		 * The post types the access taxonomy attaches to.
		 *
		 * @param array<int, string> $object_types Defaults to post, page and attachment.
		 */
		$object_types = apply_filters(
			'gatedmedia_access_object_types',
			array( 'post', 'page', 'attachment' )
		);

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
}
