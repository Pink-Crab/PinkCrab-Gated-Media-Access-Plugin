<?php
/**
 * The coupon's one metabox.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Admin;

use WP_Post;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;
use PinkCrab\Gated_Access\Settings\Settings;
use PinkCrab\Gated_Access\Support\Money;
use PinkCrab\Gated_Access\Support\View;

/**
 * Type, value, limits and expiry, behind `gatedmedia_manage_products`. The code itself is the title, and core keeps `post_name` unique per post type, which is the whole uniqueness story.
 *
 * Usage is never stored: both limits count completed payments carrying the coupon through `Payment_Store::coupon_completions()`, so an abandoned checkout consumes nothing.
 *
 * A checkout in flight reserves one use for a short window through `Coupon_Hold`, which is what stops buyers arriving together from all passing the same limit.
 *
 * A fixed value is typed and stored in the shop currency's minor units, because `Coupon_Pricing::discount()` subtracts it straight from a price held in those same units.
 *
 * The `gatedmedia_coupon_discount` filter has the last word at checkout either way.
 */
class Coupon_Metabox implements Hookable {

	public const META_TYPE           = 'gatedmedia_discount_type';
	public const META_VALUE          = 'gatedmedia_discount_value';
	public const META_USAGE_LIMIT    = 'gatedmedia_usage_limit';
	public const META_PER_USER_LIMIT = 'gatedmedia_per_user_limit';
	public const META_EXPIRES_AT     = 'gatedmedia_expires_at';

	/** The nonce field inside the editor form. */
	public const NONCE_FIELD = 'gatedmedia_coupon_nonce';

	/**
	 * Holds the settings, for the currency a fixed amount is typed in.
	 *
	 * @param Settings $settings The shop settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * The keys, their protection, the box and its save.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'init', array( $this, 'register_meta' ) );
		$loader->filter( 'is_protected_meta', array( $this, 'protect_meta' ), 3 );
		$loader->admin_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		$loader->action( 'save_post_' . Post_Types::COUPON, array( $this, 'save' ) );
	}

	/**
	 * Declares every key this class writes. Nothing is exposed over REST.
	 */
	public function register_meta(): void {
		foreach ( $this->meta_definitions() as $key => $args ) {
			register_post_meta( Post_Types::COUPON, $key, $args );
		}
	}

	/**
	 * Marks our keys protected, so nothing treats them as user-editable.
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
	 * One box, for those who may manage products.
	 */
	public function register_metabox(): void {
		if ( ! current_user_can( Capabilities::manage_products() ) ) {
			return;
		}

		add_meta_box(
			'gatedmedia_coupon',
			__( 'Coupon', 'gated-media-access' ),
			array( $this, 'render' ),
			Post_Types::COUPON,
			'normal'
		);
	}

	/**
	 * The whole box: what it takes off, and the three limits on using it.
	 *
	 * @param WP_Post $post The coupon being edited.
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_FIELD, self::NONCE_FIELD );

		$is_percent = 'fixed' !== (string) get_post_meta( $post->ID, self::META_TYPE, true );
		$expires    = (string) get_post_meta( $post->ID, self::META_EXPIRES_AT, true );

		View::render(
			'admin/coupon',
			array(
				'is_percent'     => $is_percent,
				'value'          => $this->display_value( $is_percent, (string) get_post_meta( $post->ID, self::META_VALUE, true ) ),
				'usage_limit'    => (string) get_post_meta( $post->ID, self::META_USAGE_LIMIT, true ),
				'per_user_limit' => (string) get_post_meta( $post->ID, self::META_PER_USER_LIMIT, true ),
				'expires'        => '' === $expires ? '' : substr( $expires, 0, 10 ),
			)
		);
	}

	/**
	 * Persists the box on the editor's own save.
	 *
	 * @param int $post_id The coupon being saved.
	 */
	public function save( int $post_id ): void {
		if ( ! $this->may_save( $post_id ) ) {
			return;
		}

		$is_percent = 'fixed' !== $this->posted_text( 'gatedmedia_discount_type' );
		$usage      = absint( $this->posted_text( 'gatedmedia_usage_limit' ) );
		$per_user   = absint( $this->posted_text( 'gatedmedia_per_user_limit' ) );

		update_post_meta( $post_id, self::META_TYPE, $is_percent ? 'percent' : 'fixed' );
		update_post_meta( $post_id, self::META_VALUE, $this->stored_value( $is_percent, $this->posted_text( 'gatedmedia_discount_value' ) ) );
		update_post_meta( $post_id, self::META_USAGE_LIMIT, 0 === $usage ? '' : $usage );
		update_post_meta( $post_id, self::META_PER_USER_LIMIT, 0 === $per_user ? '' : $per_user );
		update_post_meta( $post_id, self::META_EXPIRES_AT, $this->expiry_value( $this->posted_text( 'gatedmedia_coupon_expires' ) ) );
	}

	/**
	 * One submitted field, sanitized, empty when absent.
	 *
	 * @param string $field The POST field.
	 */
	private function posted_text( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in may_save(), the only route here.
		return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	/**
	 * The save guards: a verified nonce, a real save, a permitted user.
	 *
	 * @param int $post_id The coupon being saved.
	 */
	private function may_save( int $post_id ): bool {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( false === wp_verify_nonce( $nonce, self::NONCE_FIELD ) ) {
			return false;
		}

		if ( false !== wp_is_post_autosave( $post_id ) || false !== wp_is_post_revision( $post_id ) ) {
			return false;
		}

		return current_user_can( Capabilities::manage_products() );
	}

	/**
	 * A typed expiry day to the stored UTC datetime, usable through that whole day. Anything that is not a date stores empty, which means never.
	 *
	 * @param string $expires The typed day, Y-m-d.
	 */
	private function expiry_value( string $expires ): string {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires ) ? $expires . ' 23:59:59' : '';
	}

	/**
	 * The stored value back to what the input holds.
	 *
	 * @param bool   $is_percent Whether the coupon is percent-off.
	 * @param string $value      The stored value.
	 */
	private function display_value( bool $is_percent, string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return $is_percent ? $value : Money::to_decimal( (int) $value, $this->settings->currency() );
	}

	/**
	 * The typed value to what is stored: whole percent capped at 100, or minor units at the shop currency's own digits.
	 *
	 * @param bool   $is_percent Whether the coupon is percent-off.
	 * @param string $value      The typed value.
	 */
	private function stored_value( bool $is_percent, string $value ): int {
		if ( $is_percent ) {
			return min( 100, absint( $value ) );
		}

		return max( 0, Money::to_minor( $value, $this->settings->currency() ) );
	}

	/**
	 * One definition per key. Single, typed, never in REST.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function meta_definitions(): array {
		$text = array(
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => '__return_false',
		);

		return array(
			self::META_TYPE           => $text,
			self::META_VALUE          => array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => '__return_false',
			),
			self::META_USAGE_LIMIT    => $text,
			self::META_PER_USER_LIMIT => $text,
			self::META_EXPIRES_AT     => $text,
		);
	}
}
