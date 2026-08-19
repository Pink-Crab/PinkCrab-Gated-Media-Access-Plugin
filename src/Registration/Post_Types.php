<?php
/**
 * The plugin's post types and post statuses.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Registration;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;

/**
 * Registers the three containers — access records, products, coupons — and the
 * three access statuses. Nothing here reads or writes them.
 *
 * Meta is deliberately absent. The class that writes a key registers it, on
 * `init`, alongside its own hooks — `Profile_Writer` already works this way —
 * so the access writer brings its own `register_post_meta()` calls when it
 * lands.
 *
 * No `supports` list includes `custom-fields`. The Custom Fields metabox is
 * gated on `post_type_supports( $type, 'custom-fields' )`, so leaving it out
 * is what keeps our keys out of the editor — not a naming convention.
 *
 * Sits first in `Plugin::SERVICES`: the account route flushes rewrite rules on
 * `init`, and the product type's rules must exist before that happens.
 */
class Post_Types implements Hookable {

	/** An access record: one person's right to one product's content. */
	public const ACCESS = 'gatedmedia_access';

	/** A purchasable product. */
	public const PRODUCT = 'gatedmedia_product';

	/** A discount coupon. */
	public const COUPON = 'gatedmedia_coupon';

	/** Access that currently grants entry. */
	public const STATUS_ACTIVE = 'gatedmedia_active';

	/**
	 * Access whose expiry date has passed.
	 *
	 * Housekeeping for admin screens only: the resolver always compares the
	 * expiry date against now, so if the sweep that writes this status never
	 * ran, nothing would leak.
	 */
	public const STATUS_EXPIRED = 'gatedmedia_expired';

	/**
	 * Access an administrator has revoked.
	 *
	 * Authoritative, unlike expiry: written the instant it happens, with no
	 * date behind it.
	 */
	public const STATUS_REVOKED = 'gatedmedia_revoked';

	/**
	 * Registers everything on init.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the post types, then the statuses.
	 */
	public function register(): void {
		$this->register_access();
		$this->register_product();
		$this->register_coupon();
		$this->register_statuses();
	}

	/**
	 * The access record. Pure data: no UI, no REST, no front end.
	 *
	 * `show_ui` false follows specification.md §1. The admin step wants the
	 * core list screen for these, which needs it true — that is its problem
	 * and one argument to change.
	 */
	private function register_access(): void {
		register_post_type(
			self::ACCESS,
			array(
				'labels'              => array(
					'name'          => __( 'Access', 'gated-media-access' ),
					'singular_name' => __( 'Access', 'gated-media-access' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				// false, not array(): register_post_type() reads an empty
				// array as "use the defaults" and would add title and editor.
				'supports'            => false,
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'can_export'          => true,
				'map_meta_cap'        => true,
				'capability_type'     => array( 'gatedmedia_access', 'gatedmedia_accesses' ),
			)
		);
	}

	/**
	 * The product: the one public-facing type of the three.
	 */
	private function register_product(): void {
		register_post_type(
			self::PRODUCT,
			array(
				'labels'          => array(
					'name'          => __( 'Products', 'gated-media-access' ),
					'singular_name' => __( 'Product', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Product', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Product', 'gated-media-access' ),
					'view_item'     => __( 'View Product', 'gated-media-access' ),
					'search_items'  => __( 'Search Products', 'gated-media-access' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'editor' ),
				'has_archive'     => false,
				'rewrite'         => array( 'slug' => 'product' ),
				'menu_icon'       => 'dashicons-products',
				'capability_type' => array( 'gatedmedia_product', 'gatedmedia_products' ),
			)
		);
	}

	/**
	 * The coupon: admin-only, a title and nothing else visible.
	 */
	private function register_coupon(): void {
		register_post_type(
			self::COUPON,
			array(
				'labels'          => array(
					'name'          => __( 'Coupons', 'gated-media-access' ),
					'singular_name' => __( 'Coupon', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Coupon', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Coupon', 'gated-media-access' ),
					'search_items'  => __( 'Search Coupons', 'gated-media-access' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'rewrite'         => false,
				'query_var'       => false,
				'capability_type' => array( 'gatedmedia_coupon', 'gatedmedia_coupons' ),
			)
		);
	}

	/**
	 * The three access statuses.
	 *
	 * Expiry is a date, revocation is a state (architecture.md §2) — see the
	 * constants above for what that means for each.
	 */
	private function register_statuses(): void {
		register_post_status(
			self::STATUS_ACTIVE,
			$this->status_args(
				__( 'Active', 'gated-media-access' ),
				/* translators: %s: number of access records. */
				_n_noop( 'Active <span class="count">(%s)</span>', 'Active <span class="count">(%s)</span>', 'gated-media-access' )
			)
		);

		register_post_status(
			self::STATUS_EXPIRED,
			$this->status_args(
				__( 'Expired', 'gated-media-access' ),
				/* translators: %s: number of access records. */
				_n_noop( 'Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'gated-media-access' )
			)
		);

		register_post_status(
			self::STATUS_REVOKED,
			$this->status_args(
				__( 'Revoked', 'gated-media-access' ),
				/* translators: %s: number of access records. */
				_n_noop( 'Revoked <span class="count">(%s)</span>', 'Revoked <span class="count">(%s)</span>', 'gated-media-access' )
			)
		);
	}

	/**
	 * The shared shape: not public, listed on the admin screens.
	 *
	 * @param string                         $label       The status name.
	 * @param array<int|string, string|null> $label_count The `_n_noop()` pair for the counts.
	 * @return array<string, mixed>
	 */
	private function status_args( string $label, array $label_count ): array {
		return array(
			'label'                     => $label,
			'label_count'               => $label_count,
			'public'                    => false,
			'internal'                  => false,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
		);
	}
}
