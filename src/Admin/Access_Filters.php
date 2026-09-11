<?php
/**
 * The Access list's toolbar filters.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Query;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Admin\Pickers\User_Picker;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;
use PinkCrab\Gated_Access\Support\View;

/**
 * Filtering the Access list by holder, item type, one item, or source, submitted by the list's own Filter button.
 *
 * The holder rides core's `author` query var and the rest become meta clauses. The item search follows the type select, with the admin bundle swapping its endpoint.
 */
class Access_Filters implements Hookable {

	/**
	 * Group names resolve through the taxonomy's UUID identity.
	 *
	 * @param Access_Taxonomy $taxonomy Turns a UUID into its term.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The toolbar, and the clauses it puts on the list query.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->admin_action( 'restrict_manage_posts', array( $this, 'render_filters' ), 2 );
		$loader->admin_action( 'pre_get_posts', array( $this, 'apply' ) );
	}

	/**
	 * The four controls, on our list's top toolbar only.
	 *
	 * @param string $post_type The list's type.
	 * @param string $which     Which toolbar, top or bottom.
	 */
	public function render_filters( string $post_type, string $which ): void {
		if ( Post_Types::ACCESS !== $post_type || 'top' !== $which ) {
			return;
		}

		$wanted    = $this->wanted();
		$item_type = $wanted[ Access_Writer::META_ITEM_TYPE ];
		$item      = $wanted[ Access_Writer::META_ITEM_ID ];

		View::render(
			'admin/access-filters',
			array(
				'holder_picker' => $this->holder_picker(),
				'item_type'     => $item_type,
				'item_types'    => array(
					'group' => __( 'Groups', 'gated-media-access' ),
					'post'  => __( 'Posts', 'gated-media-access' ),
					'file'  => __( 'Files', 'gated-media-access' ),
				),
				'item'          => $item,
				'item_label'    => $this->item_label( $item_type, $item ),
				'item_endpoint' => $this->item_endpoint( $item_type ),
				'source'        => $wanted[ Access_Writer::META_SOURCE ],
				'sources'       => $this->known_sources(),
			)
		);
	}

	/**
	 * The chosen filters become meta clauses on the list's main query.
	 *
	 * @param WP_Query $query The list query.
	 */
	public function apply( WP_Query $query ): void {
		if ( ! $query->is_main_query() || Post_Types::ACCESS !== $query->get( 'post_type' ) ) {
			return;
		}

		$clauses = array();

		foreach ( $this->wanted() as $key => $value ) {
			if ( '' !== $value ) {
				$clauses[] = array(
					'key'   => $key,
					'value' => $value,
				);
			}
		}

		if ( array() !== $clauses ) {
			$query->set( 'meta_query', $clauses ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The admin list, filtered on request.
		}
	}

	/**
	 * What the toolbar submitted, sanitised, keyed by the meta it filters.
	 *
	 * The holder is absent deliberately, since it submits core's own `author`.
	 *
	 * @return array<string, string>
	 */
	private function wanted(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filtering; core's filter form carries no nonce either.
		$text = static fn ( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';

		return array(
			Access_Writer::META_ITEM_TYPE => $text( 'gatedmedia_item_type' ),
			Access_Writer::META_ITEM_ID   => $text( 'gatedmedia_item' ),
			Access_Writer::META_SOURCE    => $text( 'gatedmedia_source' ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The holder filter's picker, prefilled with whoever is being filtered on.
	 *
	 * It submits core's own `author` query var rather than a meta key of ours.
	 */
	private function holder_picker(): User_Picker {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill of the current filter.
		$author = isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0;
		$holder = 0 === $author ? false : get_userdata( $author );

		return new User_Picker(
			'author',
			'gatedmedia_filter_holder',
			false === $holder ? '' : (string) $author,
			false === $holder ? '' : sprintf( '%s (%s)', $holder->display_name, $holder->user_email )
		);
	}

	/**
	 * Which endpoint the item filter searches for a chosen type.
	 *
	 * @param string $item_type One of group, post, file, or ''.
	 */
	private function item_endpoint( string $item_type ): string {
		return match ( $item_type ) {
			'group' => 'gatedmedia_search_groups',
			'file'  => 'gatedmedia_search_files',
			default => 'gatedmedia_search_posts',
		};
	}

	/**
	 * The chosen filter item's display name, so the prefilled filter reads as what it is.
	 *
	 * @param string $item_type One of group, post, file, or ''.
	 * @param string $item      The chosen identifier, '' for none.
	 */
	private function item_label( string $item_type, string $item ): string {
		if ( '' === $item ) {
			return '';
		}

		if ( 'group' === $item_type ) {
			$term = $this->taxonomy->find_group( $item );

			return null === $term ? $item : $term->name;
		}

		$title = get_the_title( (int) $item );

		return '' === $title ? $item : $title;
	}

	/**
	 * Every source that appears on a record, for the filter's options.
	 *
	 * @return array<int, string>
	 */
	private function known_sources(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One DISTINCT over the admin list's own meta; no core API asks this.
		$sources = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s ORDER BY pm.meta_value",
				Access_Writer::META_SOURCE,
				Post_Types::ACCESS
			)
		);

		return array_values( array_filter( array_map( 'strval', $sources ), static fn ( string $found ): bool => '' !== $found ) );
	}
}
