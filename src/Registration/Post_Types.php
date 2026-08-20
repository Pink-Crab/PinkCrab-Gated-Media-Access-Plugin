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
use PinkCrab\Gated_Access\Settings\Settings_Page;

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
		$loader->filter( 'wp_sitemaps_post_types', array( $this, 'hide_products_from_sitemaps' ) );
	}

	/**
	 * Products out of wp-sitemap.xml — `public` alone would list every
	 * product page for crawlers, unlisted ones included.
	 *
	 * @param array<string, \WP_Post_Type> $types The sitemap's post types.
	 * @return array<string, \WP_Post_Type>
	 */
	public function hide_products_from_sitemaps( array $types ): array {
		unset( $types[ self::PRODUCT ] );

		return $types;
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
	 * The access record. Pure data: no editor, no REST, no front end.
	 *
	 * `show_ui` is true for the core list screen only — the round 4 flip the
	 * original docblock argued for. The `capabilities` map points the caps the
	 * list screen checks at the filtered give-access capability, and shuts the
	 * core write surfaces (Add New, publish, trash): records are made by the
	 * Add Access form and changed by `Access_Writer`, nothing else.
	 */
	private function register_access(): void {
		register_post_type(
			self::ACCESS,
			array(
				'labels'              => array(
					'name'          => __( 'Access', 'gated-media-access' ),
					'singular_name' => __( 'Access', 'gated-media-access' ),
					'search_items'  => __( 'Search Access', 'gated-media-access' ),
					'not_found'     => __( 'No access records found.', 'gated-media-access' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => Settings_Page::MENU_SLUG,
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
				'capabilities'        => array(
					'edit_posts'          => Capabilities::give_access(),
					'edit_others_posts'   => Capabilities::give_access(),
					'read_private_posts'  => Capabilities::give_access(),
					'create_posts'        => 'do_not_allow',
					'publish_posts'       => 'do_not_allow',
					'delete_posts'        => 'do_not_allow',
					'delete_others_posts' => 'do_not_allow',
				),
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
				'labels'              => array(
					'name'          => __( 'Products', 'gated-media-access' ),
					'singular_name' => __( 'Product', 'gated-media-access' ),
					'add_new_item'  => __( 'Add New Product', 'gated-media-access' ),
					'edit_item'     => __( 'Edit Product', 'gated-media-access' ),
					'view_item'     => __( 'View Product', 'gated-media-access' ),
					'search_items'  => __( 'Search Products', 'gated-media-access' ),
				),
				'public'              => true,
				'show_ui'             => true,
				// In REST for the block editor; Product_Meta's guard 404s
				// the surface for anyone without manage-products, so the
				// public cannot enumerate products there. Search and
				// sitemaps (hide_products_from_sitemaps() below) stay shut:
				// a product is found through our own listings or its direct
				// link, never by crawling the site (Glynn's round 5 ruling).
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'supports'            => array( 'title', 'editor' ),
				// The product form is this block, present from the first
				// paint, pinned and not removable — the description writes
				// freely around it.
				'template'            => array(
					array(
						'gated-media-access/product-details',
						array(
							'lock' => array(
								'move'   => true,
								'remove' => true,
							),
						),
					),
				),
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'product' ),
				'menu_icon'           => 'dashicons-products',
				'map_meta_cap'        => true,
				'capability_type'     => array( 'gatedmedia_product', 'gatedmedia_products' ),
				// Spec §7: the screens sit behind the one filtered
				// manage-products capability — without this map the menu
				// asked for caps nobody was ever granted.
				'capabilities'        => $this->manage_products_capabilities(),
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
				'map_meta_cap'    => true,
				'capability_type' => array( 'gatedmedia_coupon', 'gatedmedia_coupons' ),
				// Same gate as products — spec §7 puts both behind it.
				'capabilities'    => $this->manage_products_capabilities(),
			)
		);
	}

	/**
	 * Every primitive capability both commerce types check, pointed at the
	 * one filtered manage-products capability (spec §7) — administrators
	 * hold it from the init grant, and a site can move it wholesale.
	 *
	 * @return array<string, string>
	 */
	private function manage_products_capabilities(): array {
		$manage = Capabilities::manage_products();

		return array(
			'edit_posts'             => $manage,
			'edit_others_posts'      => $manage,
			'publish_posts'          => $manage,
			'read_private_posts'     => $manage,
			'create_posts'           => $manage,
			'delete_posts'           => $manage,
			'delete_others_posts'    => $manage,
			'delete_private_posts'   => $manage,
			'delete_published_posts' => $manage,
			'edit_private_posts'     => $manage,
			'edit_published_posts'   => $manage,
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
