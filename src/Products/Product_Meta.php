<?php
/**
 * The product's meta, and who may touch it.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Products;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Uuid;

/**
 * Owns spec §1a's keys, per the round 1 decision: the class that writes a
 * key registers it. The writer here is the block editor — the product form
 * is the locked `gated-media-access/product-details` block, saving straight
 * to this meta over REST — so every key is in REST behind an auth callback,
 * and the whole `/wp/v2/gatedmedia_product` surface is guarded: anyone
 * without manage-products gets a 404, so the public cannot enumerate
 * products there while the editor works normally.
 *
 * The server still stamps what the client must not choose: the UUID
 * identity on first save, and the shop currency on every save.
 */
class Product_Meta implements Hookable {

	public const META_UUID       = Uuid::META;
	public const META_PRICE      = 'gatedmedia_price_amount';
	public const META_CURRENCY   = 'gatedmedia_price_currency';
	public const META_DURATION   = 'gatedmedia_duration_days';
	public const META_VISIBILITY = 'gatedmedia_visibility';
	public const META_ITEMS      = 'gatedmedia_items';
	public const META_EMAILS     = 'gatedmedia_allowed_email';

	/**
	 * The currency stamp reads settings; stored item rows label through the
	 * taxonomy's UUID identity.
	 *
	 * @param Settings                                            $settings The settings reader.
	 * @param \PinkCrab\Gated_Access\Registration\Access_Taxonomy $taxonomy Turns a group UUID back into its term.
	 */
	public function __construct( private Settings $settings, private \PinkCrab\Gated_Access\Registration\Access_Taxonomy $taxonomy ) {
	}

	/**
	 * The keys, their protection, the stamps, the REST guard and the
	 * editor's data.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
		$loader->action( 'save_post_' . Post_Types::PRODUCT, array( $this, 'stamp' ) );
		$loader->action( 'enqueue_block_editor_assets', array( $this, 'supply_editor_data' ) );
	}

	/**
	 * The identity and the currency — the two facts the client never
	 * chooses, stamped on every save whichever route saved.
	 *
	 * @param int $post_id The product.
	 */
	public function stamp( int $post_id ): void {
		Uuid::ensure( 'post', $post_id );
		update_post_meta( $post_id, self::META_CURRENCY, $this->settings->currency() );
	}

	/**
	 * Declares every key this class owns, in REST for the block, writable
	 * only by product managers.
	 */
	public function register_meta(): void {
		foreach ( $this->meta_definitions() as $key => $args ) {
			register_post_meta( Post_Types::PRODUCT, $key, $args );
		}
	}

	/**
	 * Marks our keys protected, so nothing treats them as user-editable
	 * outside the auth callback's say-so.
	 *
	 * @param bool   $is_protected Whether the key is already protected.
	 * @param string $meta_key     The key being asked about.
	 * @param string $meta_type    The object type the key is on.
	 */
	public function protect_meta( bool $is_protected, string $meta_key, string $meta_type ): bool {
		if ( 'post' === $meta_type && array_key_exists( $meta_key, $this->meta_definitions() ) ) {
			return true;
		}

		return $is_protected;
	}

	/**
	 * What the product-details block needs and the client cannot know: the
	 * shop currency, its decimal digits, and the picker-search nonce.
	 */
	public function supply_editor_data(): void {
		if ( Post_Types::PRODUCT !== ( get_current_screen()->post_type ?? '' ) ) {
			return;
		}

		$currency = $this->settings->currency();

		wp_add_inline_script(
			'wp-block-editor',
			'window.gatedmediaProduct = ' . (string) wp_json_encode(
				array(
					'currency'   => $currency,
					'digits'     => \Symfony\Component\Intl\Currencies::getFractionDigits( $currency ),
					'nonce'      => wp_create_nonce( \PinkCrab\Gated_Access\Admin\Picker_Search::NONCE ),
					'itemLabels' => $this->item_labels( (int) get_the_ID() ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Display labels for the product's stored item rows — without these a
	 * reloaded editor could only show raw ids, since the search that chose
	 * them is long gone.
	 *
	 * @param int $product_id The product being edited, 0 on Add New.
	 * @return array<string, string> `type:id` row to label.
	 */
	private function item_labels( int $product_id ): array {
		$labels = array();

		if ( 0 === $product_id ) {
			return $labels;
		}

		foreach ( array_map( 'strval', (array) get_post_meta( $product_id, self::META_ITEMS, false ) ) as $item ) {
			list( $type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

			if ( '' === $identifier ) {
				continue;
			}

			if ( 'group' === $type ) {
				$term            = $this->taxonomy->find_group( $identifier );
				$labels[ $item ] = null === $term ? $identifier : $term->name;
				continue;
			}

			$title           = get_the_title( (int) $identifier );
			$labels[ $item ] = '' === $title ? "#{$identifier}" : $title;
		}

		return $labels;
	}

	/**
	 * One definition per key (spec §1a) — in REST for the block, every
	 * write behind manage-products, rows validated by their sanitizers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function meta_definitions(): array {
		$manager = static fn (): bool => current_user_can( Capabilities::manage_products() );

		$single_text = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $manager,
		);

		return array(
			self::META_UUID       => $single_text,
			self::META_PRICE      => array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => $manager,
			),
			self::META_CURRENCY   => $single_text,
			self::META_DURATION   => $single_text,
			self::META_VISIBILITY => array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn ( $value ): string => 'unlisted' === $value ? 'unlisted' : 'listed',
				'auth_callback'     => $manager,
			),
			self::META_ITEMS      => array(
				'type'              => 'string',
				'single'            => false,
				'show_in_rest'      => true,
				'sanitize_callback' => static fn ( $value ): string => is_string( $value ) && 1 === preg_match( '/^(file|post|group):.+$/', $value ) ? $value : '',
				'auth_callback'     => $manager,
			),
			self::META_EMAILS     => array(
				'type'              => 'string',
				'single'            => false,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_email',
				'auth_callback'     => $manager,
			),
		);
	}
}
