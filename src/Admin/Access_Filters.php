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

		$this->render_holder_filter();
		$this->render_item_filters();
		$this->render_source_filter();
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
	 * The holder filter: the user picker over core's author query var.
	 */
	private function render_holder_filter(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill of the current filter.
		$author = isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0;
		$holder = 0 === $author ? false : get_userdata( $author );

		self::hidden_label( 'gatedmedia_filter_holder_search', __( 'Filter by holder', 'gated-media-access' ) );

		( new User_Picker(
			'author',
			'gatedmedia_filter_holder',
			false === $holder ? '' : (string) $author,
			false === $holder ? '' : sprintf( '%s (%s)', $holder->display_name, $holder->user_email )
		) )->render();
	}

	/**
	 * A label only assistive technology reads, as core's own toolbar does.
	 *
	 * Each control's meaning is carried by its first option, so a visible label would say the same thing twice. A placeholder is not a label, because it goes as soon as anything is typed.
	 *
	 * @param string $control_id The control's id.
	 * @param string $text       What the control is.
	 */
	private static function hidden_label( string $control_id, string $text ): void {
		printf( '<label class="screen-reader-text" for="%s">%s</label>', esc_attr( $control_id ), esc_html( $text ) );
	}

	/**
	 * The item type select, and the item search that follows it.
	 */
	private function render_item_filters(): void {
		$wanted    = $this->wanted();
		$item_type = $wanted[ Access_Writer::META_ITEM_TYPE ];
		$item      = $wanted[ Access_Writer::META_ITEM_ID ];

		self::hidden_label( 'gatedmedia_filter_item_type', __( 'Filter by item type', 'gated-media-access' ) );

		printf( '<select name="gatedmedia_item_type" id="gatedmedia_filter_item_type">' );
		printf( '<option value="">%s</option>', esc_html__( 'All item types', 'gated-media-access' ) );

		foreach ( array(
			'group' => __( 'Groups', 'gated-media-access' ),
			'post'  => __( 'Posts', 'gated-media-access' ),
			'file'  => __( 'Files', 'gated-media-access' ),
		) as $type => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $item_type, $type, false ), esc_html( $label ) );
		}

		echo '</select>';

		self::hidden_label( 'gatedmedia_filter_item_search', __( 'Filter by item', 'gated-media-access' ) );

		printf(
			'<input type="text" class="gatedmedia-picker" id="gatedmedia_filter_item_search" data-gatedmedia-picker="%s" data-gatedmedia-target="gatedmedia_filter_item" value="%s" placeholder="%s" autocomplete="off" />
			<input type="hidden" name="gatedmedia_item" id="gatedmedia_filter_item" value="%s" />',
			esc_attr( $this->item_endpoint( $item_type ) ),
			esc_attr( $this->item_label( $item_type, $item ) ),
			esc_attr__( 'Any item…', 'gated-media-access' ),
			esc_attr( $item )
		);
	}

	/**
	 * The source select, over the sources records actually carry.
	 */
	private function render_source_filter(): void {
		$source = $this->wanted()[ Access_Writer::META_SOURCE ];

		self::hidden_label( 'gatedmedia_filter_source', __( 'Filter by source', 'gated-media-access' ) );

		printf( '<select name="gatedmedia_source" id="gatedmedia_filter_source">' );
		printf( '<option value="">%s</option>', esc_html__( 'All sources', 'gated-media-access' ) );

		foreach ( $this->known_sources() as $known ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $known ), selected( $source, $known, false ), esc_html( $known ) );
		}

		echo '</select>';
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
