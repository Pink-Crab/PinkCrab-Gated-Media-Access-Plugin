<?php
/**
 * Changing a password from the profile.
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

/**
 * The profile's password group, checked and saved through the same hooks a site's own fields use.
 *
 * The current password is required to change it (OWASP ASVS 5.0, 6.2.3).
 */
class Password_Change implements Hookable {

	/** The field each refusal marks. */
	private const FIELDS = array(
		'password_current_missing' => 'current_password',
		'password_current'         => 'current_password',
		'password_short'           => 'new_password',
		'password_mismatch'        => 'confirm_password',
	);

	/**
	 * Registers the check and the save.
	 *
	 * @param Hook_Loader $loader The shared loader.
	 */
	public function register_hooks( Hook_Loader $loader ): void {
		$loader->filter( 'gatedmedia_profile_errors', array( $this, 'check' ), 3 );
		$loader->action( 'gatedmedia_profile_updated', array( $this, 'save' ), 2 );
	}

	/**
	 * Refuses a change with the current password missing or wrong, or a new one too short or not matching.
	 *
	 * @param WP_Error              $errors    Refusals so far.
	 * @param int                   $user_id   Whose profile.
	 * @param array<string, string> $submitted The posted fields.
	 */
	public function check( WP_Error $errors, int $user_id, array $submitted ): WP_Error {
		list( $current, $new, $confirm ) = $this->typed( $submitted );

		// No new password means no change, even when the browser has filled the current one in.
		if ( '' === $new && '' === $confirm ) {
			return $errors;
		}

		$code = $this->refusal( $user_id, $current, $new, $confirm );

		if ( '' !== $code ) {
			$errors->add( $code, Profile_Writer::message_for( $code ), array( 'field' => self::FIELDS[ $code ] ) );
		}

		return $errors;
	}

	/**
	 * Sets the new password. Checked again, since anything can fire the action.
	 *
	 * `wp_update_user()` keeps the person signed in and sends core's password-changed email.
	 *
	 * @param int                   $user_id   Whose profile.
	 * @param array<string, string> $submitted The posted fields.
	 */
	public function save( int $user_id, array $submitted = array() ): void {
		$new = $this->typed( $submitted )[1];

		if ( '' === $new || $this->check( new WP_Error(), $user_id, $submitted )->has_errors() ) {
			return;
		}

		wp_update_user(
			array(
				'ID'        => $user_id,
				'user_pass' => $new,
			)
		);
	}

	/**
	 * The current, new and confirm passwords, trimmed as core's sign-in trims them, so a saved password can always be signed in with.
	 *
	 * @param array<string, string> $submitted The posted fields.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function typed( array $submitted ): array {
		return array(
			trim( $submitted['current_password'] ?? '' ),
			trim( $submitted['new_password'] ?? '' ),
			trim( $submitted['confirm_password'] ?? '' ),
		);
	}

	/**
	 * The first thing wrong with a change, '' for nothing.
	 *
	 * @param int    $user_id          Whose password.
	 * @param string $current_password The current password as typed.
	 * @param string $new_password     The new password.
	 * @param string $confirm_password The new password again.
	 */
	private function refusal( int $user_id, string $current_password, string $new_password, string $confirm_password ): string {
		if ( '' === $current_password ) {
			return 'password_current_missing';
		}

		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User || ! wp_check_password( $current_password, $user->user_pass, $user_id ) ) {
			return 'password_current';
		}

		if ( strlen( $new_password ) < Auth_State::PASSWORD_MINIMUM ) {
			return 'password_short';
		}

		return $new_password === $confirm_password ? '' : 'password_mismatch';
	}
}
