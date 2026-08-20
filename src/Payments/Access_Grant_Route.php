<?php
/**
 * The webhook another system grants access through.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Payments;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_User;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Access\Access_Writer;
use PinkCrab\Gated_Access\Products\Product_Meta;
use PinkCrab\Gated_Access\Registration\Capabilities;
use PinkCrab\Gated_Access\Registration\Post_Types;

/**
 * POST `/gated-media-access/v1/access` (spec §4): they paid elsewhere, and
 * the sending system says so. Application-password authentication with the
 * give-access capability; the payload names a person by email — found or
 * created, profile fields filled the same as every other route (§1c) — a
 * target, a duration, and the sender's source and reference, which are the
 * retry guard: a delivery repeated writes nothing.
 *
 * A `product` target expands to its items, one record each, all carrying
 * the same source and reference. Every write goes through `Access_Writer`.
 */
class Access_Grant_Route implements Hookable {

	/** Target types the payload may name. */
	private const TARGET_TYPES = array( 'file', 'post', 'group', 'product' );

	/**
	 * Grants are the writer's, as everywhere.
	 *
	 * @param Access_Writer $writer The one writer of access records.
	 */
	public function __construct( private Access_Writer $writer ) {
	}

	/**
	 * The route.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Registers the grant endpoint behind the give-access capability —
	 * application passwords ride core's REST authentication.
	 */
	public function register_route(): void {
		register_rest_route(
			Payment_Status_Route::ROUTE_NAMESPACE,
			'/access',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::give_access() ),
			)
		);
	}

	/**
	 * One delivery: validate, find or create the person, grant per target,
	 * and announce the outcome either way.
	 *
	 * @param WP_REST_Request $request The delivery.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : array();
		$source  = sanitize_text_field( (string) ( $payload['source'] ?? '' ) );

		$invalid = $this->validate( $payload );

		if ( null !== $invalid ) {
			return $this->refuse( $payload, $source, $invalid );
		}

		$user = $this->find_or_create_user( (string) $payload['email'] );

		if ( $user instanceof WP_Error ) {
			return $this->refuse( $payload, $source, $user->get_error_message() );
		}

		$this->fill_profile( $user, $payload );

		$granted = $this->grant_target( $user, $payload, $source );

		if ( $granted instanceof WP_Error ) {
			return $this->refuse( $payload, $source, $granted->get_error_message() );
		}

		$this->announce( $payload, $source, true, '' );

		return new WP_REST_Response( array( 'granted' => $granted ) );
	}

	/**
	 * The payload's required shape, or what is wrong with it.
	 *
	 * @param array<string, mixed> $payload The delivery's body.
	 * @return string|null The reason it is refused, null when sound.
	 */
	private function validate( array $payload ): ?string {
		if ( false === is_email( (string) ( $payload['email'] ?? '' ) ) ) {
			return 'email is required and must be an address.';
		}

		$target = $payload['target'] ?? null;

		if ( ! is_array( $target ) || ! in_array( (string) ( $target['type'] ?? '' ), self::TARGET_TYPES, true ) || '' === (string) ( $target['id'] ?? '' ) ) {
			return 'target must name a type (file, post, group or product) and an id.';
		}

		return $this->validate_terms( $payload );
	}

	/**
	 * The delivery's terms: a real duration, and the retry-guard pair.
	 *
	 * @param array<string, mixed> $payload The delivery's body.
	 * @return string|null The reason it is refused, null when sound.
	 */
	private function validate_terms( array $payload ): ?string {
		$duration = $payload['duration'] ?? null;

		if ( 'lifetime' !== $duration && ( ! is_numeric( $duration ) || (int) $duration < 1 ) ) {
			return 'duration must be days as a positive integer, or "lifetime".';
		}

		if ( '' === trim( (string) ( $payload['reference'] ?? '' ) ) || '' === trim( (string) ( $payload['source'] ?? '' ) ) ) {
			return 'reference and source are both required.';
		}

		return null;
	}

	/**
	 * The person the email names, created when unknown — indistinguishable
	 * afterwards from someone who signed up (§1c).
	 *
	 * @param string $email The address.
	 */
	private function find_or_create_user( string $email ): WP_User|WP_Error {
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof WP_User ) {
			return $existing;
		}

		$created = wp_insert_user(
			array(
				'user_login' => sanitize_user( $email, true ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24 ),
			)
		);

		if ( $created instanceof WP_Error ) {
			return $created;
		}

		$user = get_user_by( 'id', $created );

		return $user instanceof WP_User ? $user : new WP_Error( 'gatedmedia_user', 'The user could not be created.' );
	}

	/**
	 * The optional profile fields, stored where every route stores them:
	 * name split across core's first and last, the rest as gatedmedia_ user
	 * meta matching Profile_Writer::fields().
	 *
	 * @param WP_User              $user    The person.
	 * @param array<string, mixed> $payload The delivery's body.
	 */
	private function fill_profile( WP_User $user, array $payload ): void {
		$name = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );

		if ( '' !== $name ) {
			$parts = explode( ' ', $name, 2 );
			wp_update_user(
				array(
					'ID'         => $user->ID,
					'first_name' => $parts[0],
					'last_name'  => $parts[1] ?? '',
				)
			);
		}

		foreach ( array(
			'company' => 'gatedmedia_company',
			'phone'   => 'gatedmedia_phone',
			'address' => 'gatedmedia_address_line',
		) as $field => $meta_key ) {
			$value = sanitize_text_field( (string) ( $payload[ $field ] ?? '' ) );

			if ( '' !== $value ) {
				update_user_meta( $user->ID, $meta_key, $value );
			}
		}
	}

	/**
	 * The target as grants: a product expands to its items, one record
	 * each, same source and reference; anything else is one record.
	 *
	 * @param WP_User              $user    The person.
	 * @param array<string, mixed> $payload The delivery's body.
	 * @param string               $source  The sending system.
	 * @return array<int, int>|WP_Error The record ids, or the first refusal.
	 */
	private function grant_target( WP_User $user, array $payload, string $source ): array|WP_Error {
		$target    = (array) $payload['target'];
		$type      = (string) $target['type'];
		$target_id = (string) $target['id'];
		$duration  = 'lifetime' === $payload['duration'] ? null : (int) $payload['duration'];
		$reference = sanitize_text_field( (string) $payload['reference'] );
		$meta      = is_array( $payload['meta'] ?? null ) ? $payload['meta'] : array();

		$items = array( array( $type, $target_id ) );

		if ( 'product' === $type ) {
			$items = $this->product_items( (int) $target_id );

			if ( $items instanceof WP_Error ) {
				return $items;
			}
		}

		$granted = array();

		foreach ( $items as $item ) {
			$access_id = $this->writer->grant( $user->ID, $item[0], $item[1], $duration, $source, $reference, $meta );

			if ( $access_id instanceof WP_Error ) {
				return $access_id;
			}

			$granted[] = $access_id;
		}

		return $granted;
	}

	/**
	 * A product target's items, as type and id pairs.
	 *
	 * @param int $product_id The product named by the target.
	 * @return array<int, array{0: string, 1: string}>|WP_Error
	 */
	private function product_items( int $product_id ): array|WP_Error {
		$product = get_post( $product_id );

		if ( null === $product || Post_Types::PRODUCT !== $product->post_type ) {
			return new WP_Error( 'gatedmedia_no_product', 'No such product to expand.' );
		}

		$items = array();

		foreach ( array_map( 'strval', (array) get_post_meta( $product_id, Product_Meta::META_ITEMS, false ) ) as $item ) {
			list( $item_type, $identifier ) = array_pad( explode( ':', $item, 2 ), 2, '' );

			if ( '' !== $identifier ) {
				$items[] = array( $item_type, $identifier );
			}
		}

		return $items;
	}

	/**
	 * A refusal: announced, then answered as a 400.
	 *
	 * @param array<string, mixed> $payload The delivery's body.
	 * @param string               $source  The sending system, as claimed.
	 * @param string               $reason  Why it is refused.
	 */
	private function refuse( array $payload, string $source, string $reason ): WP_Error {
		$this->announce( $payload, $source, false, $reason );

		return new WP_Error( 'gatedmedia_webhook_refused', $reason, array( 'status' => 400 ) );
	}

	/**
	 * Every delivery is announced, accepted or not (spec §5).
	 *
	 * @param array<string, mixed> $payload  The delivery's body.
	 * @param string               $source   The sending system, as claimed.
	 * @param bool                 $accepted Whether it was acted on.
	 * @param string               $reason   Why not, '' when accepted.
	 */
	private function announce( array $payload, string $source, bool $accepted, string $reason ): void {
		/**
		 * Fires for every delivery to the access webhook.
		 *
		 * @param array<string, mixed> $payload  The delivery's body.
		 * @param string               $source   The sending system, as claimed.
		 * @param bool                 $accepted Whether it was acted on.
		 * @param string               $reason   Why not, '' when accepted.
		 */
		do_action( 'gatedmedia_webhook_received', $payload, $source, $accepted, $reason );
	}
}
