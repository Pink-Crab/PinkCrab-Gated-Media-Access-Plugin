<?php
/**
 * The access taxonomy.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

use WP_Error;
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
	 * re-slugging a group never orphans anyone's access. The key is the one
	 * `Support\Uuid` minter's — products carry the same one on post meta.
	 */
	public const UUID_META = \PinkCrab\Gated_Access\Support\Uuid::META;

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
		// Groups are created on the Groups screens only — not typed into the
		// editors' free-tagging fields. Assigning existing groups stays.
		$loader->filter( 'pre_insert_term', array( $this, 'forbid_editor_creation' ), 2 );
		$loader->filter( 'rest_request_before_callbacks', array( $this, 'forbid_rest_creation' ), 3 );
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
				'labels'             => array(
					'name'          => __( 'Groups', 'gated-media-access' ),
					'singular_name' => __( 'Group', 'gated-media-access' ),
					'search_items'  => __( 'Search Groups', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Group', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Group', 'gated-media-access' ),
				),
				'public'             => false,
				'show_ui'            => true,
				// Round 4: the item's Access metabox is the assignment
				// surface, so core's free-tagging fields all switch off —
				// the editor panel (REST), the classic tag box, and the
				// quick/bulk edit field. The Groups screens stay.
				'show_in_rest'       => false,
				'meta_box_cb'        => false,
				'show_in_quick_edit' => false,
				'hierarchical'       => false,
				'show_admin_column'  => true,
				'rewrite'            => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'manage_categories',
				),
			)
		);
	}

	/**
	 * Refuses a group typed into an editor's free-tagging field.
	 *
	 * Non-hierarchical taxonomies create unknown names on save — quick edit,
	 * bulk edit and the classic editor all pass through here. A group is a
	 * deliberate thing with an identity; it is made on the Groups screen, not
	 * as a side effect of saving a post. Code calling `wp_insert_term()` —
	 * the restricted marker included — is untouched.
	 *
	 * @param string|WP_Error $term     The prospective term name, or an earlier refusal.
	 * @param string          $taxonomy The taxonomy it would land in.
	 * @return string|WP_Error
	 */
	public function forbid_editor_creation( string|WP_Error $term, string $taxonomy ): string|WP_Error {
		if ( self::TAXONOMY !== $taxonomy || $term instanceof WP_Error ) {
			return $term;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only routing check; each of these actions verifies its own nonce.
		$origin = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		if ( in_array( $origin, array( 'inline-save', 'editpost', 'bulk-edit' ), true ) ) {
			return new WP_Error(
				'gatedmedia_group_creation_forbidden',
				__( 'Groups are created on the Groups screen, not from the editor.', 'gated-media-access' )
			);
		}

		return $term;
	}

	/**
	 * Refuses group creation over REST — the block editor's "add new" path.
	 *
	 * A POST to the terms collection is a create; the panel assigning
	 * existing groups goes through the posts endpoint and passes untouched.
	 * (Not `rest_pre_insert_{taxonomy}`: the terms controller, unlike the
	 * posts one, never error-checks that filter's return.)
	 *
	 * @param mixed                $response The dispatch result so far.
	 * @param array<string, mixed> $handler  The matched route handler.
	 * @param \WP_REST_Request     $request  The request.
	 * @return mixed
	 */
	public function forbid_rest_creation( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		if ( 'POST' === $request->get_method() && '/wp/v2/' . self::TAXONOMY === $request->get_route() ) {
			return new WP_Error(
				'gatedmedia_group_creation_forbidden',
				__( 'Groups are created on the Groups screen, not from the editor.', 'gated-media-access' ),
				array( 'status' => 403 )
			);
		}

		return $response;
	}

	/**
	 * Gives a new term its UUID.
	 *
	 * @param int $term_id The term just created.
	 */
	public function mint_uuid( int $term_id ): void {
		\PinkCrab\Gated_Access\Support\Uuid::ensure( 'term', $term_id );
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
		return \PinkCrab\Gated_Access\Support\Uuid::ensure( 'term', $term_id );
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
