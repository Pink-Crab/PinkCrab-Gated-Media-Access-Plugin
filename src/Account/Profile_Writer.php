<?php
/**
 * The profile shape, and the one place it is written.
 *
 * @package PinkCrab\Gated_Access
 */

declare( strict_types = 1 );

namespace PinkCrab\Gated_Access\Account;

use WP_Error;
use WP_User;
use PinkCrab\Loader\Hook_Loader;
use PinkCrab\Gated_Access\Hookable;
use PinkCrab\Gated_Access\Auth\Auth_State;
use PinkCrab\Gated_Access\Support\Account_Url;
use PinkCrab\Gated_Access\Support\Auth_Url;
use PinkCrab\Gated_Access\Support\Labels;

/**
 * The profile's groups of fields, and the handler that saves them from the front end.
 *
 * Each field says where it is stored: `core` and `meta` are read and written here, `none` is left to whoever handles `gatedmedia_profile_updated`.
 *
 * The meta keys follow the same `gatedmedia_` prefix and no-leading-underscore rule as everything else.
 *
 * @phpstan-import-type Profile_Field from Profile_Fields
 * @phpstan-import-type Profile_Group from Profile_Fields
 */
class Profile_Writer implements Hookable {

	/** The admin-post action, and the nonce name. */
	public const ACTION = 'gatedmedia_save_profile';

	/** The query arg carrying a refusal's code back to the form. */
	public const ARG_ERROR = 'gatedmedia_profile_error';

	/** The query arg naming the field a refusal is about. */
	public const ARG_FIELD = 'gatedmedia_profile_field';

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
	 * The groups and their fields, in render order, after `gatedmedia_profile_fields`.
	 *
	 * @return array<string, Profile_Group>
	 */
	public static function groups(): array {
		$defaults = Profile_Fields::defaults();

		/**
		 * Filters the profile's groups and fields, to reorder, add or remove them.
		 *
		 * @param array<string, array<string, mixed>> $groups Group key => array{label, fields}.
		 */
		$filtered = apply_filters( 'gatedmedia_profile_fields', $defaults );

		return Profile_Fields::normalise( is_array( $filtered ) ? $filtered : $defaults );
	}

	/**
	 * The fields stored here (`core` and `meta`), across every group, in render order.
	 *
	 * @return array<string, Profile_Field>
	 */
	public static function fields(): array {
		return array_filter(
			self::all_fields(),
			static fn ( array $field ): bool => Profile_Fields::STORE_NONE !== $field['store']
		);
	}

	/**
	 * The current stored values for one user.
	 *
	 * @param int $user_id Whose profile.
	 * @return array<string, string>
	 */
	public static function values_for( int $user_id ): array {
		$values = array();

		foreach ( self::fields() as $key => $field ) {
			$values[ $key ] = (string) get_user_meta( $user_id, self::meta_key( $key, $field['store'] ), true );
		}

		return $values;
	}

	/**
	 * What the form shows: the stored values, the account's email, and '' for the rest, after `gatedmedia_profile_values`.
	 *
	 * @param int $user_id Whose profile.
	 * @return array<string, string>
	 */
	public static function form_values( int $user_id ): array {
		$values = array_merge( array_fill_keys( array_keys( self::all_fields() ), '' ), self::values_for( $user_id ) );

		$user = get_userdata( $user_id );

		if ( array_key_exists( 'email', $values ) && $user instanceof WP_User ) {
			$values['email'] = $user->user_email;
		}

		/**
		 * Filters the values the profile form shows, so a site can fill the fields it stores itself.
		 *
		 * @param array<string, string> $values  Field key => value.
		 * @param int                   $user_id Whose profile.
		 */
		$filtered = apply_filters( 'gatedmedia_profile_values', $values, $user_id );

		if ( ! is_array( $filtered ) ) {
			return $values;
		}

		return array_map( static fn ( $value ): string => is_scalar( $value ) ? (string) $value : '', $filtered );
	}

	/**
	 * Which required stored fields are still empty.
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
	 * The text for a refusal code: the `account.profile.error.{code}` label, or the general not-saved line when there is none.
	 *
	 * @param string $code The refusal code.
	 */
	public static function message_for( string $code ): string {
		$key  = 'account.profile.error.' . $code;
		$text = Labels::text( $key );

		if ( $key === $text ) {
			return Labels::text( 'account.profile.not_saved' );
		}

		return 'password_short' === $code ? sprintf( $text, Auth_State::PASSWORD_MINIMUM ) : $text;
	}

	/**
	 * Saves the posted profile and sends the person back where they came from.
	 *
	 * Only ever writes the current user's own profile, with no user id in the payload to tamper with. A refusal from `gatedmedia_profile_errors` saves nothing.
	 */
	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			$this->leave( Auth_Url::signin( Account_Url::section( 'profile' ) ) );
			return;
		}

		check_admin_referer( self::ACTION );

		$user_id   = get_current_user_id();
		$submitted = self::submitted();

		/**
		 * Filters whether this profile save may go ahead. Add to the `WP_Error` to refuse it, with a `field` in its data to mark that field.
		 *
		 * @param WP_Error              $errors    Add to it to refuse.
		 * @param int                   $user_id   Whose profile.
		 * @param array<string, string> $submitted The posted fields.
		 */
		$errors = apply_filters( 'gatedmedia_profile_errors', new WP_Error(), $user_id, $submitted );

		if ( $errors instanceof WP_Error && $errors->has_errors() ) {
			$this->refuse( $errors );
			return;
		}

		foreach ( self::fields() as $key => $field ) {
			// Absent means "not submitted", never "blank it": forced completion posts only the missing fields.
			if ( array_key_exists( $key, $submitted ) ) {
				update_user_meta( $user_id, self::meta_key( $key, $field['store'] ), $submitted[ $key ] );
			}
		}

		/**
		 * Fires after a person saves their own profile from the front end, once the stored fields are written. Fields stored `none` are saved here.
		 *
		 * @param int                   $user_id   The user whose profile changed.
		 * @param array<string, string> $submitted The posted fields: passwords raw, the rest sanitised.
		 */
		do_action( 'gatedmedia_profile_updated', $user_id, $submitted );

		$this->leave( add_query_arg( 'profile', 'saved', $this->referer() ) );
	}

	/**
	 * Every field across the groups, in render order.
	 *
	 * @return array<string, Profile_Field>
	 */
	private static function all_fields(): array {
		$fields = array();

		foreach ( self::groups() as $group ) {
			$fields = array_merge( $fields, $group['fields'] );
		}

		return $fields;
	}

	/**
	 * Where a stored field lives in user meta.
	 *
	 * @param string $key   The field key.
	 * @param string $store The field's store.
	 */
	private static function meta_key( string $key, string $store ): string {
		return Profile_Fields::STORE_CORE === $store ? $key : 'gatedmedia_' . $key;
	}

	/**
	 * The posted value of each field in the groups, skipping read-only fields and anything not posted.
	 *
	 * @return array<string, string>
	 */
	private static function submitted(): array {
		$submitted = array();

		foreach ( self::all_fields() as $key => $field ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() ran in handle().
			if ( $field['disabled'] || ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised below, except a password, which sanitising would alter.
			$raw = wp_unslash( $_POST[ $key ] );

			$submitted[ $key ] = 'password' === $field['type'] ? $raw : sanitize_text_field( $raw );
		}

		return $submitted;
	}

	/**
	 * Back to the form with the first refusal's code and field. Nothing has been saved.
	 *
	 * @param WP_Error $errors The refusals.
	 */
	private function refuse( WP_Error $errors ): void {
		$code = (string) $errors->get_error_code();
		$data = $errors->get_error_data( $code );

		$args = array(
			'profile'       => 'error',
			self::ARG_ERROR => sanitize_key( $code ),
			self::ARG_FIELD => is_array( $data ) && is_string( $data['field'] ?? null ) ? sanitize_key( $data['field'] ) : '',
		);

		$this->leave( add_query_arg( array_filter( $args, static fn ( string $value ): bool => '' !== $value ), $this->referer() ) );
	}

	/**
	 * The redirect, and the `exit` that has to follow it.
	 *
	 * A `wp_safe_redirect()` that does not halt emits a body alongside the Location header, and on admin-post.php the redirect may then not be honoured at all.
	 *
	 * @param string $url Where to send them.
	 */
	private function leave( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Where to go back to, defaulting to the account route.
	 */
	private function referer(): string {
		$referer = wp_get_referer();

		if ( is_string( $referer ) && '' !== $referer ) {
			return remove_query_arg( array( 'profile', self::ARG_ERROR, self::ARG_FIELD ), $referer );
		}

		return Account_Url::section( 'profile' );
	}
}
