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
 * One taxonomy for both jobs: a term marking content as restricted, and one term per group.
 *
 * It shares its name with the access post type deliberately. Separate registries, and `query_var` and `rewrite` are off on both sides, so nothing collides.
 *
 * Registration only. Terms, group identity and the two `set_object_terms` behaviours all belong to `Restriction`.
 */
class Access_Taxonomy implements Hookable {

	/** The taxonomy name. */
	public const TAXONOMY = 'gatedmedia_access';

	/**
	 * Term meta: the group's stable identity.
	 *
	 * Access records point at this rather than the term id or slug, so renaming a group never orphans anyone's access.
	 */
	public const UUID_META = \PinkCrab\Gated_Access\Support\Uuid::META;

	/**
	 * Registers on init, after the post types; mints identity on creation.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
		// created_{$taxonomy} fires for terms made anywhere, core screens included.
		$loader->action( 'created_' . self::TAXONOMY, array( $this, 'mint_uuid' ) );
		// Groups are created on the Groups screens only, never typed into an editor.
		$loader->filter( 'pre_insert_term', array( $this, 'forbid_editor_creation' ), 2 );
		$loader->filter( 'rest_request_before_callbacks', array( $this, 'forbid_rest_creation' ), 3 );
	}

	/**
	 * The restrictable post types, and where the item-side admin surfaces appear.
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
				// "Groups" is the name these screens go by everywhere.
				'labels'             => array(
					'name'          => __( 'Groups', 'gated-media-access' ),
					'singular_name' => __( 'Group', 'gated-media-access' ),
					'search_items'  => __( 'Search Groups', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Group', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Group', 'gated-media-access' ),
				),
				'public'             => false,
				// Core's term editing stays, but with no menu: `Groups_Page` is the one door.
				'show_ui'            => true,
				'show_in_menu'       => false,
				// The item's Access metabox is the assignment surface, so free-tagging is off.
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
	 * Non-hierarchical taxonomies create unknown names on save, so quick edit, bulk edit and the classic editor all pass through here. Code calling `wp_insert_term()` is untouched.
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
	 * Refuses group creation over REST.
	 *
	 * Dormant as registered, since `show_in_rest` is false and core creates no `/wp/v2/gatedmedia_access` route to POST at. It stands so turning `show_in_rest` on cannot quietly open a create path.
	 *
	 * Not `rest_pre_insert_{taxonomy}`: the terms controller never error-checks that filter's return.
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
	 * The backfill covers terms created before the hook existed, or inserted directly, so identity is settled the first time anything asks.
	 *
	 * @param int $term_id The term.
	 */
	public function uuid_for( int $term_id ): string {
		return \PinkCrab\Gated_Access\Support\Uuid::ensure( 'term', $term_id );
	}

	/**
	 * What a group holds right now.
	 *
	 * Groups are live, so this is a query rather than anything stored.
	 *
	 * **Statuses are named explicitly.** An attachment's status is `inherit`, and the default would silently drop every file from a group while the term's count still included it.
	 *
	 * @param string $uuid The group.
	 * @return array<int, int> Post IDs, newest first.
	 */
	public function contents( string $uuid ): array {
		$term = $this->find_group( $uuid );

		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$found = get_posts(
			array(
				'post_type'      => self::object_types(),
				// Gated posts belong in a group's contents, not dropped for their status.
				'post_status'    => array( 'publish', 'inherit', Post_Types::STATUS_GATED ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- The term is the whole question.
					array(
						'taxonomy' => self::TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			)
		);

		return array_map( 'intval', $found );
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
