<?php
/**
 * What an item is called, and which icon stands for it.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Support;

use WP_Term;
use PinkCrab\Gated_Access\Registration\Access_Taxonomy;

/**
 * Items are stored as `type:identifier` strings — in a product's
 * `gatedmedia_items` meta, and frozen into a payment's contents snapshot. Both
 * the product page and an order's detail have to turn those back into
 * something a person can read.
 *
 * One class rather than a copy in each, because the fallbacks are a decision:
 * an item deleted since keeps its kind ("A file") so a list still accounts for
 * what was bought, instead of silently losing a line.
 */
class Item_Label {

	/**
	 * Groups are named by their term, so the taxonomy comes along.
	 *
	 * @param Access_Taxonomy $taxonomy Group identity.
	 */
	public function __construct( private Access_Taxonomy $taxonomy ) {
	}

	/**
	 * What one item is called now.
	 *
	 * @param string $type       group, post or file.
	 * @param string $identifier A post id, or a group's uuid.
	 */
	public function text( string $type, string $identifier ): string {
		if ( '' === $type || '' === $identifier ) {
			return '';
		}

		if ( 'group' === $type ) {
			$term = $this->taxonomy->find_group( $identifier );

			return $term instanceof WP_Term ? $term->name : __( 'A group', 'gated-media-access' );
		}

		$title = (string) get_the_title( (int) $identifier );

		if ( '' !== $title ) {
			return $title;
		}

		return 'file' === $type
			? __( 'A file', 'gated-media-access' )
			: __( 'An item', 'gated-media-access' );
	}

	/**
	 * The sprite symbol for an item's type.
	 *
	 * @param string $type One of group, post, file — anything else draws none.
	 */
	public function icon( string $type ): string {
		$icons = array(
			'group' => 'i-groups',
			'post'  => 'i-article',
			'file'  => 'i-doc',
		);

		return $icons[ $type ] ?? '';
	}

	/**
	 * One stored `type:identifier` string split into its two parts.
	 *
	 * @param string $entry The stored value.
	 * @return array{0: string, 1: string} Type then identifier, either possibly ''.
	 */
	public static function split( string $entry ): array {
		list( $type, $identifier ) = array_pad( explode( ':', $entry, 2 ), 2, '' );

		return array( sanitize_key( $type ), sanitize_text_field( $identifier ) );
	}
}
