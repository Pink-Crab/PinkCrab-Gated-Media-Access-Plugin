<?php
/**
 * The profile shape, and the one place it is written.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;

/**
 * One profile shape (name, email, address, phone, company) and the handler that saves it from the front end.
 *
 * All three creation routes fill the same fields, so a person who arrived by webhook is indistinguishable from one who signed up, and that only holds while `fields()` below is the one definition of what the fields are.
 *
 * The webhook and the admin-created user read that same list when they are built.
 *
 * The meta keys follow the same `gatedmedia_` prefix and no-leading-underscore rule as everything else.
 *
 * Email is deliberately absent: it identifies the account and is rendered read-only, so this form cannot write it.
 */
class Profile_Writer implements Hookable {

	/** The admin-post action, and the nonce name. */
	public const ACTION = 'gatedmedia_save_profile';

	/**
	 * Registers the save handler.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		// admin_post_ rather than a template_redirect handler: posting to admin-post.php keeps the write off the rendering path, so there is no ordering to get wrong against the account route.
		$loader->action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The profile fields, in render order.
	 *
	 * Two are core user fields and the rest are our meta, and `core` says which, because they are saved through different functions.
	 *
	 * @return array<string, array{label: string, type: string, core: bool, required: bool, autocomplete: string}>
	 */
	public static function fields(): array {
		return array(
			'first_name'   => array(
				'label'        => __( 'First name', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => true,
				'required'     => true,
				'autocomplete' => 'given-name',
			),
			'last_name'    => array(
				'label'        => __( 'Last name', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => true,
				'required'     => true,
				'autocomplete' => 'family-name',
			),
			'company'      => array(
				'label'        => __( 'Company', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'organization',
			),
			'phone'        => array(
				'label'        => __( 'Phone', 'gated-media-access' ),
				'type'         => 'tel',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'tel',
			),
			'address_line' => array(
				'label'        => __( 'Address', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'address-line1',
			),
			'city'         => array(
				'label'        => __( 'Town or city', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'address-level2',
			),
			'postcode'     => array(
				'label'        => __( 'Postcode', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'postal-code',
			),
			'country'      => array(
				'label'        => __( 'Country', 'gated-media-access' ),
				'type'         => 'text',
				'core'         => false,
				'required'     => false,
				'autocomplete' => 'country-name',
			),
		);
	}

	/**
	 * The current values for one user.
	 *
	 * @param int $user_id Whose profile.
	 * @return array<string, string>
	 */
	public static function values_for( int $user_id ): array {
		$values = array();

		foreach ( self::fields() as $key => $field ) {
			$values[ $key ] = $field['core']
				? (string) get_user_meta( $user_id, $key, true )
				: (string) get_user_meta( $user_id, 'gatedmedia_' . $key, true );
		}

		return $values;
	}

	/**
	 * Which required fields are still empty.
	 *
	 * Drives both the "profile incomplete" notice and the forced-completion state, which shows only what is missing rather than the whole form.
	 *
	 * @param int $user_id Whose profile.
	 * @return array<int, string>
	 */
	public static function missing_for( int $user_id ): array {
		$values  = self::values_for( $user_id );
		$missing = array();

		foreach ( self::fields() as $key => $field ) {
			if ( $field['required'] && '' === trim( $values[ $key ] ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Saves the posted profile and sends the person back where they came from.
	 *
	 * Only ever writes the current user's own profile, with no user id in the payload to tamper with.
	 *
	 * The `exit` calls are required: a `wp_safe_redirect()` that does not halt emits a body alongside the Location header, and on admin-post.php the redirect may then not be honoured at all.
	 */
	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( Auth_Url::signin( Account_Url::section( 'profile' ) ) );
			exit;
		}

		check_admin_referer( self::ACTION );

		$user_id = get_current_user_id();

		foreach ( self::fields() as $key => $field ) {
			// Absent means "not submitted", never "blank it": forced completion posts only the missing fields.
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}

			$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

			if ( $field['core'] ) {
				update_user_meta( $user_id, $key, $value );
				continue;
			}

			update_user_meta( $user_id, 'gatedmedia_' . $key, $value );
		}

		/**
		 * Fires after a person edits their own profile from the front end.
		 *
		 * @param int $user_id The user whose profile changed.
		 */
		do_action( 'gatedmedia_profile_updated', $user_id );

		wp_safe_redirect( add_query_arg( 'profile', 'saved', $this->referer() ) );
		exit;
	}

	/**
	 * Where to go back to, defaulting to the account route.
	 */
	private function referer(): string {
		$referer = wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			return remove_query_arg( 'profile', $referer );
		}

		return Account_Url::section( 'profile' );
	}
}
